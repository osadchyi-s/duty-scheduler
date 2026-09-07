<?php

namespace App\Services;

use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
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
 */
class ConstraintsEngine
{
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

        return $this->evaluateRestPeriod($user, $eventType, $startsAt, $endsAt, $ignoreEventId);
    }

    /**
     * 1. Перевірка допуску: чи дозволено співробітнику цей тип події.
     */
    protected function checkPermission(User $user, EventType $eventType): ?ConstraintCheckResult
    {
        if (! $user->isAllowedEventType($eventType->id)) {
            return ConstraintCheckResult::forbidden(
                "Співробітнику {$user->name} не дозволено призначати тип події «{$eventType->name}» (немає допуску)."
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
                "Подія «{$eventType->name}» є ексклюзивною (is_blocking) і конфліктує з наявною подією «{$conflict->eventType->name}» ({$conflict->starts_at->format('d.m H:i')}–{$conflict->ends_at->format('d.m H:i')})."
            );
        }

        // Якщо будь-яка з існуючих подій, що перекриваються, є блокуючою — теж заборона.
        $blockingConflict = $overlapping->first(fn (UserEvent $e) => $e->eventType->is_blocking);
        if ($blockingConflict) {
            return ConstraintCheckResult::forbidden(
                "Співробітник вже перебуває в ексклюзивній події «{$blockingConflict->eventType->name}» ({$blockingConflict->starts_at->format('d.m')}–{$blockingConflict->ends_at->format('d.m')}), що блокує будь-які інші призначення."
            );
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
                "Досягнуто максимальної місткості ({$eventType->max_capacity}) для типу події «{$eventType->name}» на цей період."
            );
        }

        return null;
    }

    /**
     * 4. Оцінка часу відпочинку відносно попередньої та наступної події співробітника.
     *    Optimal >= preferred; [min, preferred) => Warning; < min => Forbidden.
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

        // Відпочинок ПІСЛЯ попередньої події і ДО початку нової.
        if ($previousEvent) {
            $restBefore = $previousEvent->ends_at->diffInMinutes($startsAt) / 60;
            [$worstStatus, $worstRestHours, $reason] = $this->pickWorst(
                $worstStatus, $worstRestHours, $reason,
                $this->classifyRest($restBefore, $eventType->min_rest_hours, $eventType->preferred_rest_hours),
                $restBefore,
                "Відпочинок після попередньої події ({$restBefore} год) менший за бажаний ({$eventType->preferred_rest_hours} год)."
            );
        }

        // Відпочинок МІЖ кінцем нової події і ПОЧАТКОМ наступної (перевіряємо за правилами наступної події).
        if ($nextEvent) {
            $restAfter = $endsAt->diffInMinutes($nextEvent->starts_at) / 60;
            $nextType = $nextEvent->eventType;
            [$worstStatus, $worstRestHours, $reason] = $this->pickWorst(
                $worstStatus, $worstRestHours, $reason,
                $this->classifyRest($restAfter, $nextType->min_rest_hours, $nextType->preferred_rest_hours),
                $restAfter,
                "Відпочинок перед наступною подією «{$nextType->name}» ({$restAfter} год) менший за її бажаний поріг ({$nextType->preferred_rest_hours} год)."
            );
        }

        return match ($worstStatus) {
            ConstraintCheckResult::STATUS_FORBIDDEN => ConstraintCheckResult::forbidden(
                $reason ?? 'Порушення мінімального обов’язкового відпочинку.',
                $worstRestHours
            ),
            ConstraintCheckResult::STATUS_WARNING => ConstraintCheckResult::warning(
                $reason ?? 'Призначення в межах бажаного (не мінімального) відпочинку.',
                $worstRestHours
            ),
            default => ConstraintCheckResult::ok($worstRestHours),
        };
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
