<?php

namespace App\Services;

use App\Models\EventType;
use App\Models\Synergy;
use App\Models\User;
use App\Models\UserEvent;
use App\Support\TimeUnit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Automatic duty assignment for a given future period.
 *
 * The algorithm is greedy with workload balancing: for each day of the period
 * and each selected schedulable event type it takes the permitted employees
 * (user_event_type_permissions), validates each through ConstraintsEngine and
 * picks one at random among the least loaded valid candidates, weighted by synergy.
 *
 * Everything created has the `draft` status: a manager reviews and approves
 * it in the duty matrix, so nothing gets into the schedule unreviewed.
 */
class AutoScheduler
{
    public const EXISTING_FILL_MISSING = 'fill_missing';

    public const EXISTING_DELETE_DRAFTS = 'delete_drafts';

    public const EXISTING_DELETE_ALL = 'delete_all';

    public function __construct(protected ConstraintsEngine $engine)
    {
    }

    /**
     * @param  Collection<int, EventType>|array<int>  $eventTypeIds
     */
    public function generate(
        Carbon $from,
        Carbon $to,
        array $eventTypeIds,
        bool $fillToMax = false,
        string $existingEvents = self::EXISTING_FILL_MISSING,
    ): AutoScheduleResult {
        if (! in_array($existingEvents, [
            self::EXISTING_FILL_MISSING,
            self::EXISTING_DELETE_DRAFTS,
            self::EXISTING_DELETE_ALL,
        ], true)) {
            throw new \InvalidArgumentException('Unknown existing event handling mode.');
        }

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

        $workloadHours = [];
        $shiftCounts = []; // [user_id][event_type_id] => number of shifts assigned in this run
        $synergies = Synergy::with('eventTypes:id')->get()->keyBy(
            fn (Synergy $synergy): string => $this->synergyPairKey($synergy->user_a_id, $synergy->user_b_id)
        );

        $result->deletedCount = DB::transaction(function () use ($eventTypes, $activeUsers, $from, $to, $fillToMax, $existingEvents, &$workloadHours, &$shiftCounts, $synergies, $result): int {
            $deletedCount = 0;

            if ($existingEvents !== self::EXISTING_FILL_MISSING) {
                $existingQuery = UserEvent::query()
                    ->whereIn('event_type_id', $eventTypes->pluck('id'))
                    ->where('status', '!=', 'cancelled')
                    ->overlappingPeriod($from, $to);

                if ($existingEvents === self::EXISTING_DELETE_DRAFTS) {
                    $existingQuery->where('status', 'draft');
                }

                $deletedCount = $existingQuery->delete();
            }

            // Calculate workload only after optional removal, so deleted shifts
            // do not distort fairness for the assignments created in this run.
            $workloadHours = $this->initialWorkload($activeUsers, $from, $to);

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

            return $deletedCount;
        });

        for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonth()) {
            Cache::forget('wfm:matrix:'.$month->format('Y-m'));
            Cache::forget('wfm:coverage:'.$month->format('Y-m'));
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
            return; // ends_at cannot be calculated without a duration, so skip (see the event type form)
        }

        $target = $fillToMax && $eventType->max_capacity
            ? $eventType->max_capacity
            : $eventType->min_capacity;

        if ($target <= 0) {
            return;
        }

        $durationHours = TimeUnit::normalizeDurationHours($eventType->default_duration_hours);
        $startsAt = TimeUnit::shiftStart($day, $eventType->default_start_time);
        $endsAt = $startsAt->copy()->addHours($durationHours);

        if ($startsAt->gt($rangeEnd)) {
            return; // do not create an event that starts after the end of the selected period
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
                break; // no eligible candidates left, so record a deficit
            }

            $selected = $this->chooseCandidate($validCandidates, $workloadHours, $shiftCounts, $eventType->id);
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
                'has_warning' => $check->status === ConstraintCheckResult::STATUS_WARNING,
                'warning_reason' => $check->reason,
                'note' => 'system:auto-generated',
            ]);

            if ($eventType->is_work_time) {
                $workloadHours[$candidate->id] = ($workloadHours[$candidate->id] ?? 0) + $durationHours;
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
                __('No eligible employees pass the constraints (permission / rest / exclusivity) for this day.')
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
     * Picks a candidate in two steps: only the employees with the lowest
     * workload (hours) remain, then one of them is drawn at random. Among them,
     * higher synergy can receive up to 100 times the weight, while a higher
     * shift count for this event type lowers it.
     */
    protected function chooseCandidate(array $candidates, array $workloadHours, array $shiftCounts, int $eventTypeId): array
    {
        $workloadOf = fn (array $item): float => (float) ($workloadHours[$item['user']->id] ?? 0);
        $minWorkload = min(array_map($workloadOf, $candidates));
        // Workload may be fractional (events of arbitrary length), so compare with a tolerance.
        $candidates = array_values(array_filter(
            $candidates,
            fn (array $item): bool => $workloadOf($item) - $minWorkload < 0.001
        ));

        $scores = array_column($candidates, 'synergy');
        $minScore = min($scores);
        $maxScore = max($scores);
        $minShiftCount = min(array_map(fn (array $item): int => $shiftCounts[$item['user']->id][$eventTypeId] ?? 0, $candidates));
        $weightedCandidates = [];
        $totalWeight = 0;

        foreach ($candidates as $candidate) {
            $synergyLevel = $maxScore === $minScore
                ? 0
                : ($candidate['synergy'] - $minScore) / ($maxScore - $minScore);
            $synergyWeight = (int) round(1000 * (100 ** $synergyLevel));
            $shiftDifference = ($shiftCounts[$candidate['user']->id][$eventTypeId] ?? 0) - $minShiftCount;
            $weight = max(1, (int) round($synergyWeight / (1 + $shiftDifference)));

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
     * Initial workload (hours) of each employee from existing events in the period,
     * so auto-generation completes the schedule evenly instead of ignoring manual assignments.
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
