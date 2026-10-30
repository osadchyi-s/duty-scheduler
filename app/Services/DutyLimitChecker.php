<?php

namespace App\Services;

use App\Models\DutyLimit;
use App\Models\EventType;
use App\Models\ScheduleDraft;
use App\Models\User;
use App\Models\UserEvent;
use App\Periods\PeriodWindow;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Checks duty limits: an employee of a limit's groups (every employee when the
 * limit has no groups) may have at most
 * max_days counted duty days (see UserEvent::countedDaysFor) of the limit's
 * event types within each window of the limit's period. All applicable
 * limits are checked, so effectively the strictest one wins.
 */
class DutyLimitChecker
{
    /** @var Collection<int, DutyLimit>|null Active limits, loaded once per instance. */
    protected ?Collection $limits = null;

    /**
     * Checks a prospective assignment. Returns null when no limit is exceeded.
     */
    public function check(
        User $user,
        EventType $eventType,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $ignoreEventId = null,
        ?ScheduleDraft $draft = null,
    ): ?ConstraintCheckResult {
        $limits = $this->activeLimits()->filter(
            fn (DutyLimit $limit): bool => $limit->appliesToUser($user->id) && $limit->appliesToEventType($eventType)
        );

        if ($limits->isEmpty()) {
            return null;
        }

        $newDays = UserEvent::countedDaysFor($startsAt, $endsAt);
        $windowsByLimit = $limits->map(fn (DutyLimit $limit): array => $limit->period->windowsForDays($newDays));
        $existingEvents = $this->eventsInWindows([$user->id], $windowsByLimit->flatten()->all(), $ignoreEventId, $draft);
        $warning = null;

        foreach ($limits as $limitKey => $limit) {
            foreach ($windowsByLimit[$limitKey] as $window) {
                $total = $this->countDays($limit, $existingEvents, $window)
                    + count(array_filter($newDays, fn (Carbon $day): bool => $window->contains($day)));

                if ($total <= $limit->max_days) {
                    continue;
                }

                $reason = $this->reason($limit, $total, $window);

                if ($limit->isHard()) {
                    return ConstraintCheckResult::forbidden($reason);
                }

                $warning ??= $reason;
            }
        }

        return $warning === null ? null : ConstraintCheckResult::warning($warning);
    }

    /**
     * Existing events that take part in an exceeded limit, for highlighting.
     * Windows reaching outside the given events are counted from the database.
     *
     * @param  Collection<int, UserEvent>  $events  events with eventType loaded
     * @param  ScheduleDraft|null  $draft  scenario the events belong to; null means the approved schedule
     * @return array<int, array<int, string>> event id => reasons
     */
    public function violations(Collection $events, ?ScheduleDraft $draft = null): array
    {
        $limits = $this->activeLimits();
        $events = $events->filter(fn (UserEvent $event): bool => $event->status !== 'cancelled');

        if ($limits->isEmpty() || $events->isEmpty()) {
            return [];
        }

        // [limit key][user id][window key] => window
        $checks = [];
        foreach ($events as $event) {
            foreach ($limits as $limitKey => $limit) {
                if (! $limit->appliesToUser($event->user_id) || ! $limit->appliesToEventType($event->eventType)) {
                    continue;
                }

                $days = UserEvent::countedDaysFor($event->starts_at, $event->ends_at);
                foreach ($limit->period->windowsForDays($days) as $windowKey => $window) {
                    $checks[$limitKey][$event->user_id][$windowKey] = $window;
                }
            }
        }

        if ($checks === []) {
            return [];
        }

        $userIds = collect($checks)->flatMap(fn (array $byUser): array => array_keys($byUser))->unique()->all();
        $windows = collect($checks)->flatten()->all();
        $allEvents = $this->eventsInWindows($userIds, $windows, null, $draft);
        $violations = [];

        foreach ($checks as $limitKey => $byUser) {
            $limit = $limits[$limitKey];

            foreach ($byUser as $userId => $userWindows) {
                $userEvents = $allEvents->where('user_id', $userId);

                foreach ($userWindows as $window) {
                    $total = $this->countDays($limit, $userEvents, $window);

                    if ($total <= $limit->max_days) {
                        continue;
                    }

                    $reason = $this->reason($limit, $total, $window);
                    foreach ($this->matchingEvents($limit, $userEvents, $window) as $event) {
                        $violations[$event->id][] = $reason;
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @return Collection<int, DutyLimit>
     */
    protected function activeLimits(): Collection
    {
        return $this->limits ??= DutyLimit::query()
            ->where('is_active', true)
            ->with(['period', 'eventTypes', 'groups.users'])
            ->get();
    }

    /**
     * Non-cancelled events of the users that overlap any of the windows.
     *
     * @param  array<int, PeriodWindow>  $windows
     * @return Collection<int, UserEvent>
     */
    protected function eventsInWindows(array $userIds, array $windows, ?int $ignoreEventId = null, ?ScheduleDraft $draft = null): Collection
    {
        if ($windows === []) {
            return collect();
        }

        $from = collect($windows)->min(fn (PeriodWindow $window) => $window->start);
        $to = collect($windows)->max(fn (PeriodWindow $window) => $window->end);

        return UserEvent::query()
            ->inScenario($draft)
            ->whereIn('user_id', $userIds)
            ->where('status', '!=', 'cancelled')
            ->when($ignoreEventId, fn ($query) => $query->whereKeyNot($ignoreEventId))
            ->overlappingPeriod($from, $to)
            ->with('eventType:id,is_work_time')
            ->get();
    }

    /**
     * @param  Collection<int, UserEvent>  $events
     * @return Collection<int, UserEvent>
     */
    protected function matchingEvents(DutyLimit $limit, Collection $events, PeriodWindow $window): Collection
    {
        return $events->filter(fn (UserEvent $event): bool => $limit->appliesToEventType($event->eventType)
            && $this->daysInWindow($event, $window) > 0);
    }

    /**
     * @param  Collection<int, UserEvent>  $events
     */
    protected function countDays(DutyLimit $limit, Collection $events, PeriodWindow $window): int
    {
        return $this->matchingEvents($limit, $events, $window)
            ->sum(fn (UserEvent $event): int => $this->daysInWindow($event, $window));
    }

    protected function daysInWindow(UserEvent $event, PeriodWindow $window): int
    {
        return count(array_filter(
            UserEvent::countedDaysFor($event->starts_at, $event->ends_at),
            fn (Carbon $day): bool => $window->contains($day)
        ));
    }

    protected function reason(DutyLimit $limit, int $total, PeriodWindow $window): string
    {
        return __('Duty limit “:limit” exceeded: :count of :max days in the period :period.', [
            'limit' => $limit->name,
            'count' => $total,
            'max' => $limit->max_days,
            'period' => $window->label(),
        ]);
    }
}
