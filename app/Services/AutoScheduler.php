<?php

namespace App\Services;

use App\Models\EventType;
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
 *   сортує їх за поточним сумарним навантаженням (щоб рівномірно розподіляти),
 *   і по черзі пропускає кожного кандидата через ConstraintsEngine, поки
 *   не заповнить min_capacity (або max_capacity, якщо увімкнено fillToMax).
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

        DB::transaction(function () use ($eventTypes, $activeUsers, $from, $to, $fillToMax, &$workloadHours, &$shiftCounts, $result) {
            for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
                foreach ($eventTypes as $eventType) {
                    $this->fillSlot($eventType, $day, $activeUsers, $fillToMax, $workloadHours, $shiftCounts, $result, $to);
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

            $assignedThisRound = false;

            foreach ($candidates as $candidate) {
                $check = $this->engine->validate($candidate, $eventType, $startsAt, $endsAt);

                if (! $check->isAllowed) {
                    continue;
                }

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

                $result->addCreated(
                    $day->format('d.m.Y'),
                    $eventType->name,
                    $candidate->name,
                    $check->status
                );

                $needed--;
                $filled++;
                $assignedThisRound = true;
                break;
            }

            if (! $assignedThisRound) {
                break; // жодного придатного кандидата не залишилось — фіксуємо дефіцит
            }
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
