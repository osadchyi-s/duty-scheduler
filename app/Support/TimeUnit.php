<?php

namespace App\Support;

use App\Models\UserEvent;
use Carbon\Carbon;

/**
 * Converts between the stored time values (always hours) and the unit shown
 * in the interface (config "scheduling.time_unit": days or hours).
 *
 * In "days" mode every event starts at 00:00 and ends at 00:00 of the day
 * after its last day, so one day always equals exactly 24 hours.
 */
final class TimeUnit
{
    public const DAYS = 'days';

    public const HOURS = 'hours';

    public const HOURS_PER_DAY = 24;

    public static function current(): string
    {
        return config('scheduling.time_unit') === self::HOURS ? self::HOURS : self::DAYS;
    }

    public static function usesDays(): bool
    {
        return self::current() === self::DAYS;
    }

    /**
     * Stored hours => value in the interface unit.
     */
    public static function toDisplay(int|float|string|null $hours): int|float|null
    {
        if ($hours === null || $hours === '') {
            return null;
        }

        $value = self::usesDays() ? (float) $hours / self::HOURS_PER_DAY : (float) $hours;

        return self::trimNumber($value);
    }

    /**
     * Value in the interface unit => stored hours.
     */
    public static function toHours(int|float|string|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(self::usesDays() ? (float) $value * self::HOURS_PER_DAY : (float) $value);
    }

    /**
     * Rounds a shift duration up to whole days in "days" mode (minimum one day).
     */
    public static function normalizeDurationHours(int $hours): int
    {
        if (! self::usesDays()) {
            return $hours;
        }

        return max(1, (int) ceil($hours / self::HOURS_PER_DAY)) * self::HOURS_PER_DAY;
    }

    /**
     * Start of a shift on the given day: midnight in "days" mode, otherwise the
     * event type's default start time.
     */
    public static function shiftStart(Carbon $day, ?string $defaultStartTime): Carbon
    {
        if (self::usesDays()) {
            return $day->copy()->startOfDay();
        }

        return $day->copy()->setTimeFromTimeString($defaultStartTime ?? '08:00:00');
    }

    /**
     * Localized duration, e.g. "2 days" or "36 hours".
     */
    public static function format(int|float $hours): string
    {
        $value = self::toDisplay($hours);

        return self::usesDays()
            ? trans_choice(':count day|:count days', $value)
            : trans_choice(':count hour|:count hours', $value);
    }

    public static function formatStart(Carbon $startsAt): string
    {
        return $startsAt->format(self::usesDays() ? 'd.m' : 'd.m H:i');
    }

    /**
     * In "days" mode the end is shown as the last covered day, because the
     * stored end (00:00 of the next day) is exclusive.
     */
    public static function formatEnd(Carbon $startsAt, Carbon $endsAt): string
    {
        return self::usesDays()
            ? UserEvent::lastCoveredDayFor($startsAt, $endsAt)->format('d.m')
            : $endsAt->format('d.m H:i');
    }

    public static function formatPeriod(Carbon $startsAt, Carbon $endsAt): string
    {
        $start = self::formatStart($startsAt);
        $end = self::formatEnd($startsAt, $endsAt);

        return $start === $end ? $start : $start.'–'.$end;
    }

    private static function trimNumber(float $value): int|float
    {
        $rounded = round($value, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }
}
