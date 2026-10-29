<?php

namespace App\Services;

use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
use App\Support\TimeUnit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Сервіс багаторівневої валідації призначення події (Duty Matrix Constraints Engine).
 *
 * Порядок перевірок відповідає ТЗ п.4:
 *   1. Допуск співробітника до типу події (user_event_type_permissions)
 *   2. Ексклюзивність (is_blocking) — конфлікт з іншими подіями співробітника
 *   3. Місткість (max_capacity) на дату/зміну
 *   4. Оцінка часу відпочинку (min_rest_hours / preferred_rest_hours)
 *
 * Duty limits (DutyLimitChecker) are checked after capacity and before rest:
 * a hard limit forbids the assignment, a soft one adds a warning.
 */
class ConstraintsEngine
{
    public function __construct(protected DutyLimitChecker $dutyLimits)
    {
    }

    /**
     * Повна перевірка перед створенням/переміщенням події.
     *
     * @param  int|null  $ignoreEventId  id події, яку ігноруємо при перевірці (для редагування existing)
     */
    public function validate(
        User $user,
        EventType $eventType,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $ignoreEventId = null,
    ): ConstraintCheckResult {
        if ($result = $this->checkPermission($user, $eventType)) {
            return $result;
        }

        if ($result = $this->checkBlockingExclusivity($user, $eventType, $startsAt, $endsAt, $ignoreEventId)) {
            return $result;
        }

        if ($result = $this->checkCapacity($eventType, $startsAt, $endsAt, $ignoreEventId)) {
            return $result;
        }

        $limitResult = $this->dutyLimits->check($user, $eventType, $startsAt, $endsAt, $ignoreEventId);
        if ($limitResult && ! $limitResult->isAllowed) {
            return $limitResult;
        }

        $restResult = $this->evaluateRestPeriod($user, $eventType, $startsAt, $endsAt, $ignoreEventId);

        return $limitResult ? $this->mergeWarning($restResult, $limitResult) : $restResult;
    }

    /**
     * Adds a soft duty-limit warning to the rest evaluation; a forbidden rest result wins.
     */
    protected function mergeWarning(ConstraintCheckResult $restResult, ConstraintCheckResult $limitWarning): ConstraintCheckResult
    {
        if (! $restResult->isAllowed) {
            return $restResult;
        }

        $reason = $restResult->status === ConstraintCheckResult::STATUS_WARNING
            ? $restResult->reason.' '.$limitWarning->reason
            : $limitWarning->reason;

        return ConstraintCheckResult::warning($reason, $restResult->restHours);
    }

    /**
     * 1. Перевірка допуску: чи дозволено співробітнику цей тип події.
     */
    protected function checkPermission(User $user, EventType $eventType): ?ConstraintCheckResult
    {
        if (! $user->isAllowedEventType($eventType->id)) {
            return ConstraintCheckResult::forbidden(
                __('Employee :name is not allowed to be assigned event type “:event” (no permission).', [
                    'name' => $user->name,
                    'event' => $eventType->name,
                ])
            );
        }

        return null;
    }

    /**
     * 2. Перевірка ексклюзивності: is_blocking-подія блокує будь-які інші події,
     *    а нова подія не може накладатись на вже існуючу блокуючу подію співробітника.
     */
    protected function checkBlockingExclusivity(
        User $user,
        EventType $eventType,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $ignoreEventId,
    ): ?ConstraintCheckResult {
        $overlapping = UserEvent::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->when($ignoreEventId, fn ($q) => $q->where('id', '!=', $ignoreEventId))
            ->overlappingPeriod($startsAt, $endsAt)
            ->with('eventType')
            ->get();

        // Якщо нова подія сама блокуюча — будь-яке перекриття з іншою подією забороняється.
        if ($eventType->is_blocking && $overlapping->isNotEmpty()) {
            $conflict = $overlapping->first();

            return ConstraintCheckResult::forbidden(
                __('Event “:event” is exclusive (is_blocking) and conflicts with existing event “:conflict” (:from–:to).', [
                    'event' => $eventType->name,
                    'conflict' => $conflict->eventType->name,
                    'from' => TimeUnit::formatStart($conflict->starts_at),
                    'to' => TimeUnit::formatEnd($conflict->starts_at, $conflict->ends_at),
                ])
            );
        }

        // Якщо будь-яка з існуючих подій, що перекриваються, є блокуючою — теж заборона.
        $blockingConflict = $overlapping->first(fn (UserEvent $e) => $e->eventType->is_blocking);
        if ($blockingConflict) {
            return ConstraintCheckResult::forbidden(
                __('The employee is already in exclusive event “:event” (:from–:to), which blocks all other assignments.', [
                    'event' => $blockingConflict->eventType->name,
                    'from' => $blockingConflict->starts_at->format('d.m'),
                    'to' => UserEvent::lastCoveredDayFor($blockingConflict->starts_at, $blockingConflict->ends_at)->format('d.m'),
                ])
            );
        }

        if ($eventType->is_work_time) {
            $overlappingWorkEvent = $overlapping->first(
                fn (UserEvent $event): bool => $event->eventType->is_work_time
            );

            if ($overlappingWorkEvent) {
                return ConstraintCheckResult::forbidden(
                    __('Duty “:event” overlaps with working event “:conflict” (:from–:to).', [
                        'event' => $eventType->name,
                        'conflict' => $overlappingWorkEvent->eventType->name,
                        'from' => TimeUnit::formatStart($overlappingWorkEvent->starts_at),
                        'to' => TimeUnit::formatEnd($overlappingWorkEvent->starts_at, $overlappingWorkEvent->ends_at),
                    ])
                );
            }
        }

        return null;
    }

    /**
     * 3. Перевірка місткості: скільки людей вже призначено на цей тип події
     *    в межах доби (перекриття інтервалів), не більше max_capacity.
     */
    protected function checkCapacity(
        EventType $eventType,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $ignoreEventId,
    ): ?ConstraintCheckResult {
        if ($eventType->max_capacity === null) {
            return null;
        }

        $currentCount = UserEvent::query()
            ->where('event_type_id', $eventType->id)
            ->where('status', '!=', 'cancelled')
            ->when($ignoreEventId, fn ($q) => $q->where('id', '!=', $ignoreEventId))
            ->overlappingPeriod($startsAt, $endsAt)
            ->count();

        if ($currentCount >= $eventType->max_capacity) {
            return ConstraintCheckResult::forbidden(
                __('Maximum capacity (:capacity) reached for event type “:event” in this period.', [
                    'capacity' => $eventType->max_capacity,
                    'event' => $eventType->name,
                ])
            );
        }

        return null;
    }

    /**
     * 4. Оцінка часу відпочинку відносно попередньої та наступної події співробітника.
     *    Optimal >= preferred; [min, preferred) => Warning; < min => Forbidden.
     *    Each gap is governed by the rest rules of the event before it.
     */
    public function evaluateRestPeriod(
        User $user,
        EventType $eventType,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $ignoreEventId = null,
    ): ConstraintCheckResult {
        if (! $eventType->is_work_time) {
            return ConstraintCheckResult::ok();
        }

        $previousEvent = UserEvent::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->when($ignoreEventId, fn ($q) => $q->where('id', '!=', $ignoreEventId))
            ->whereHas('eventType', fn ($q) => $q->where('is_work_time', true))
            ->where('ends_at', '<=', $startsAt)
            ->orderByDesc('ends_at')
            ->first();

        $nextEvent = UserEvent::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->when($ignoreEventId, fn ($q) => $q->where('id', '!=', $ignoreEventId))
            ->whereHas('eventType', fn ($q) => $q->where('is_work_time', true))
            ->where('starts_at', '>=', $endsAt)
            ->orderBy('starts_at')
            ->first();

        $worstRestHours = null;
        $worstStatus = ConstraintCheckResult::STATUS_OPTIMAL;
        $reason = null;

        // Rest rules apply to the period AFTER an event: the gap between two events is
        // governed only by the earlier event's type; the later event adds no requirement.

        // Rest after the previous event and before the new one.
        if ($previousEvent) {
            $restBefore = TimeUnit::hoursBetween($previousEvent->ends_at, $startsAt);
            $previousType = $previousEvent->eventType;
            [$minRest, $preferredRest] = $this->restRules($previousType);
            $status = $this->classifyRest($restBefore, $minRest, $preferredRest);
            [$worstStatus, $worstRestHours, $reason] = $this->pickWorst(
                $worstStatus, $worstRestHours, $reason,
                $status,
                $restBefore,
                $status === ConstraintCheckResult::STATUS_FORBIDDEN
                    ? __('Rest after the previous event “:event” (:rest) is below the required minimum (:min).', [
                        'event' => $previousType->name,
                        'rest' => TimeUnit::format($restBefore),
                        'min' => TimeUnit::format($minRest),
                    ])
                    : __('Rest after the previous event (:rest) is below the preferred amount (:preferred).', [
                        'rest' => TimeUnit::format($restBefore),
                        'preferred' => TimeUnit::format($preferredRest),
                    ])
            );
        }

        // Rest after the new event and before the next one.
        if ($nextEvent) {
            $restAfter = TimeUnit::hoursBetween($endsAt, $nextEvent->starts_at);
            $nextType = $nextEvent->eventType;
            [$minRest, $preferredRest] = $this->restRules($eventType);
            $status = $this->classifyRest($restAfter, $minRest, $preferredRest);
            [$worstStatus, $worstRestHours, $reason] = $this->pickWorst(
                $worstStatus, $worstRestHours, $reason,
                $status,
                $restAfter,
                $status === ConstraintCheckResult::STATUS_FORBIDDEN
                    ? __('Rest before the next event “:event” (:rest) is below the required minimum (:min).', [
                        'event' => $nextType->name,
                        'rest' => TimeUnit::format($restAfter),
                        'min' => TimeUnit::format($minRest),
                    ])
                    : __('Rest before the next event “:event” (:rest) is below the preferred amount (:preferred).', [
                        'event' => $nextType->name,
                        'rest' => TimeUnit::format($restAfter),
                        'preferred' => TimeUnit::format($preferredRest),
                    ])
            );
        }

        return match ($worstStatus) {
            ConstraintCheckResult::STATUS_FORBIDDEN => ConstraintCheckResult::forbidden(
                $reason ?? __('Required minimum rest period violated.'),
                $worstRestHours
            ),
            ConstraintCheckResult::STATUS_WARNING => ConstraintCheckResult::warning(
                $reason ?? __('Assignment is within the preferred (but not minimum) rest period.'),
                $worstRestHours
            ),
            default => ConstraintCheckResult::ok($worstRestHours),
        };
    }

    /**
     * Minimum and preferred rest required after an event of the given type.
     *
     * @return array{0: int, 1: int}
     */
    protected function restRules(EventType $earlierType): array
    {
        return [$earlierType->min_rest_hours, $earlierType->preferred_rest_hours];
    }

    protected function classifyRest(float $restHours, int $minRest, int $preferredRest): string
    {
        if ($restHours < $minRest) {
            return ConstraintCheckResult::STATUS_FORBIDDEN;
        }
        if ($restHours < $preferredRest) {
            return ConstraintCheckResult::STATUS_WARNING;
        }

        return ConstraintCheckResult::STATUS_OPTIMAL;
    }

    /**
     * Комбінує статус двох перевірок (до/після), обираючи найгірший (forbidden > warning > optimal).
     */
    protected function pickWorst(string $currentStatus, ?float $currentRest, ?string $currentReason, string $newStatus, float $newRest, string $newReason): array
    {
        $rank = [
            ConstraintCheckResult::STATUS_OPTIMAL => 0,
            ConstraintCheckResult::STATUS_WARNING => 1,
            ConstraintCheckResult::STATUS_FORBIDDEN => 2,
        ];

        if ($rank[$newStatus] > $rank[$currentStatus]) {
            return [$newStatus, $newRest, $newReason];
        }

        return [$currentStatus, $currentRest ?? $newRest, $currentReason];
    }

    /**
     * Кешована в Redis статистика покриття (кількість призначених) по датах для типу події
     * за місяць — використовується для підсвічування дефіцитних дат у матриці (п.9.2).
     */
    public function monthlyCoverage(Carbon $monthStart, Carbon $monthEnd): array
    {
        $cacheKey = 'wfm:coverage:'.$monthStart->format('Y-m');

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($monthStart, $monthEnd) {
            $events = UserEvent::query()
                ->where('status', '!=', 'cancelled')
                ->whereBetween('starts_at', [$monthStart, $monthEnd])
                ->with('eventType:id,name,min_capacity')
                ->get();

            $coverage = [];
            foreach ($events as $event) {
                $date = $event->starts_at->format('Y-m-d');
                $typeId = $event->event_type_id;
                $coverage[$date][$typeId] = ($coverage[$date][$typeId] ?? 0) + 1;
            }

            return $coverage;
        });
    }
}
