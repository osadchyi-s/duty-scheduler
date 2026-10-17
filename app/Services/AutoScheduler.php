<?php

namespace App\Services;

use App\Models\EventType;
use App\Models\Synergy;
use App\Models\User;
use App\Models\UserEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Автоматичний розподіл чергувань наперед на заданий період.
 *
 * Алгоритм — жадібний (greedy) із балансуванням навантаження:
 *   для кожного дня періоду і кожного обраного "розкладного" типу події
 *   бере список допущених співробітників (user_event_type_permissions),
 *   перевіряє кожного через ConstraintsEngine і випадково обирає з допустимих,
 *   зважуючи вибір синергією та балансом навантаження.
 *
 * Все, що створюється — статус `draft`: менеджер переглядає й затверджує
 * в самій Матриці чергувань, нічого не потрапляє в графік "наосліп".
 */
class AutoScheduler
{
    public function __construct(protected ConstraintsEngine $engine)
    {
    }

    /**
     * @param  Collection<int, EventType>|array<int>  $eventTypeIds
     */
    public function generate(Carbon $from, Carbon $to, array $eventTypeIds, bool $fillToMax = false): AutoScheduleResult
    {
        $result = new AutoScheduleResult;

        $eventTypes = EventType::whereIn('id', $eventTypeIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($eventTypes->isEmpty()) {
            return $result;
        }

        $activeUsers = User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['User', 'Manager', 'Admin']))
            ->with('allowedEventTypes:id')
            ->get();

        // Базове навантаження — сума годин наявних робочих подій за весь період (для справедливого старту).
        $workloadHours = $this->initialWorkload($activeUsers, $from, $to);
        $shiftCounts = []; // [user_id][event_type_id] => к-сть змін, призначених у цьому запуску
        $synergies = Synergy::with('eventTypes:id')->get()->keyBy(
            fn (Synergy $synergy): string => $this->synergyPairKey($synergy->user_a_id, $synergy->user_b_id)
        );

        DB::transaction(function () use ($eventTypes, $activeUsers, $from, $to, $fillToMax, &$workloadHours, &$shiftCounts, $synergies, $result) {
            for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
                $assignedUsersToday = UserEvent::whereIn('event_type_id', $eventTypes->pluck('id'))
                    ->where('status', '!=', 'cancelled')
                    ->overlappingPeriod($day->copy()->startOfDay(), $day->copy()->endOfDay())
                    ->pluck('user_id')
                    ->unique()
                    ->flip();

                foreach ($eventTypes as $eventType) {
                    $this->fillSlot($eventType, $day, $activeUsers, $fillToMax, $workloadHours, $shiftCounts, $synergies, $assignedUsersToday, $result, $to);
                }
            }
        });

        Cache::forget('wfm:matrix:'.$from->format('Y-m'));
        Cache::forget('wfm:coverage:'.$from->format('Y-m'));
        if ($from->format('Y-m') !== $to->format('Y-m')) {
            Cache::forget('wfm:matrix:'.$to->format('Y-m'));
            Cache::forget('wfm:coverage:'.$to->format('Y-m'));
        }

        return $result;
    }

    protected function fillSlot(
        EventType $eventType,
        Carbon $day,
        Collection $activeUsers,
        bool $fillToMax,
        array &$workloadHours,
        array &$shiftCounts,
        Collection $synergies,
        Collection $assignedUsersToday,
        AutoScheduleResult $result,
        Carbon $rangeEnd,
    ): void {
        if (! $eventType->default_duration_hours) {
            return; // неможливо розрахувати ends_at без тривалості — пропускаємо (див. форму типу події)
        }

        $target = $fillToMax && $eventType->max_capacity
            ? $eventType->max_capacity
            : $eventType->min_capacity;

        if ($target <= 0) {
            return;
        }

        $startsAt = $day->copy()->setTimeFromTimeString($eventType->default_start_time ?? '08:00:00');
        $endsAt = $startsAt->copy()->addHours($eventType->default_duration_hours);

        if ($startsAt->gt($rangeEnd)) {
            return; // не створюємо подію, яка починається після кінця вибраного періоду
        }

        $existingCount = UserEvent::where('event_type_id', $eventType->id)
            ->where('status', '!=', 'cancelled')
            ->overlappingPeriod($startsAt, $endsAt)
            ->count();

        $needed = $target - $existingCount;
        if ($needed <= 0) {
            return;
        }

        $assignedTodayThisType = UserEvent::where('event_type_id', $eventType->id)
            ->where('status', '!=', 'cancelled')
            ->overlappingPeriod($startsAt, $endsAt)
            ->pluck('user_id')
            ->flip();

        $eligible = $activeUsers->filter(
            fn (User $u) => $u->allowedEventTypes->contains('id', $eventType->id)
                && ! $assignedTodayThisType->has($u->id)
        );

        $filled = 0;

        while ($needed > 0) {
            $candidates = $eligible
                ->reject(fn (User $u) => $assignedTodayThisType->has($u->id))
                ->sortBy(fn (User $u) => [
                    $workloadHours[$u->id] ?? 0,
                    $shiftCounts[$u->id][$eventType->id] ?? 0,
                ])
                ->values();

            $validCandidates = [];

            foreach ($candidates as $candidate) {
                $check = $this->engine->validate($candidate, $eventType, $startsAt, $endsAt);

                if (! $check->isAllowed) {
                    continue;
                }

                $validCandidates[] = [
                    'user' => $candidate,
                    'check' => $check,
                    'synergy' => $this->synergyScore($candidate, $assignedUsersToday, $eventType, $synergies),
                ];
            }

            if ($validCandidates === []) {
                break; // жодного придатного кандидата не залишилось — фіксуємо дефіцит
            }

            $selected = $this->chooseCandidate($validCandidates, $workloadHours, $shiftCounts, $eventType);
            /** @var User $candidate */
            $candidate = $selected['user'];
            /** @var ConstraintCheckResult $check */
            $check = $selected['check'];

            UserEvent::create([
                'user_id' => $candidate->id,
                'event_type_id' => $eventType->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'draft',
                'has_rest_warning' => $check->status === ConstraintCheckResult::STATUS_WARNING,
                'warning_reason' => $check->reason,
                'note' => 'Автогенерація графіка',
            ]);

            if ($eventType->is_work_time) {
                $workloadHours[$candidate->id] = ($workloadHours[$candidate->id] ?? 0) + $eventType->default_duration_hours;
            }
            $shiftCounts[$candidate->id][$eventType->id] = ($shiftCounts[$candidate->id][$eventType->id] ?? 0) + 1;
            $assignedTodayThisType->put($candidate->id, true);
            $assignedUsersToday->put($candidate->id, true);

            $result->addCreated(
                $day->format('d.m.Y'),
                $eventType->name,
                $candidate->name,
                $check->status
            );

            $needed--;
            $filled++;
        }

        if ($needed > 0) {
            $result->addDeficit(
                $day->format('d.m.Y'),
                $eventType->name,
                $target,
                $existingCount + $filled,
                'Немає допущених співробітників, які проходять перевірку обмежень (допуск / відпочинок / ексклюзивність) для цього дня.'
            );
        }
    }

    /**
     * Averages applicable pair scores for the other employees already assigned
     * to this date and event type. An empty event-type list means all types.
     */
    protected function synergyScore(User $candidate, Collection $assignedUsers, EventType $eventType, Collection $synergies): float
    {
        $scores = [];

        foreach ($assignedUsers->keys() as $assignedUserId) {
            if ((int) $assignedUserId === (int) $candidate->id) {
                continue;
            }

            $synergy = $synergies->get($this->synergyPairKey($candidate->id, (int) $assignedUserId));
            if (! $synergy) {
                continue;
            }

            $eventTypeIds = $synergy->eventTypes->pluck('id');
            if ($eventTypeIds->isNotEmpty() && ! $eventTypeIds->contains($eventType->id)) {
                continue;
            }

            $scores[] = $synergy->score;
        }

        return $scores === [] ? 0 : array_sum($scores) / count($scores);
    }

    /**
     * Draws a weighted random candidate: higher synergy can receive up to 100
     * times the weight, while workload and shift counts still favor fairness.
     */
    protected function chooseCandidate(array $candidates, array $workloadHours, array $shiftCounts, EventType $eventType): array
    {
        $scores = array_column($candidates, 'synergy');
        $minScore = min($scores);
        $maxScore = max($scores);
        $minWorkload = min(array_map(fn (array $item): int => $workloadHours[$item['user']->id] ?? 0, $candidates));
        $minShiftCount = min(array_map(fn (array $item): int => $shiftCounts[$item['user']->id][$eventType->id] ?? 0, $candidates));
        $weightedCandidates = [];
        $totalWeight = 0;

        foreach ($candidates as $candidate) {
            $synergyLevel = $maxScore === $minScore
                ? 0
                : ($candidate['synergy'] - $minScore) / ($maxScore - $minScore);
            $synergyWeight = (int) round(1000 * (100 ** $synergyLevel));
            $workloadDifference = ($workloadHours[$candidate['user']->id] ?? 0) - $minWorkload;
            $shiftDifference = ($shiftCounts[$candidate['user']->id][$eventType->id] ?? 0) - $minShiftCount;
            $fairnessFactor = 1 + ($workloadDifference / max(1, $eventType->default_duration_hours)) + $shiftDifference;
            $weight = max(1, (int) round($synergyWeight / $fairnessFactor));

            $weightedCandidates[] = ['candidate' => $candidate, 'weight' => $weight];
            $totalWeight += $weight;
        }

        $draw = random_int(1, $totalWeight);

        foreach ($weightedCandidates as $weightedCandidate) {
            $draw -= $weightedCandidate['weight'];
            if ($draw <= 0) {
                return $weightedCandidate['candidate'];
            }
        }

        return $weightedCandidates[array_key_last($weightedCandidates)]['candidate'];
    }

    protected function synergyPairKey(int $userId, int $otherUserId): string
    {
        return min($userId, $otherUserId).':'.max($userId, $otherUserId);
    }

    /**
     * Стартове навантаження (год) кожного співробітника за вже наявними подіями в періоді —
     * щоб автогенерація "добудовувала" графік рівномірно, а не ігнорувала вже призначене вручну.
     */
    protected function initialWorkload(Collection $users, Carbon $from, Carbon $to): array
    {
        $existing = UserEvent::whereIn('user_id', $users->pluck('id'))
            ->where('status', '!=', 'cancelled')
            ->overlappingPeriod($from, $to)
            ->with('eventType:id,is_work_time')
            ->get();

        $workload = [];
        foreach ($existing as $event) {
            if ($event->eventType->is_work_time) {
                $workload[$event->user_id] = ($workload[$event->user_id] ?? 0) + $event->durationHours();
            }
        }

        return $workload;
    }
}
