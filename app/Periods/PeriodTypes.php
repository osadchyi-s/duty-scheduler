<?php

namespace App\Periods;

use InvalidArgumentException;

/**
 * Registry of available period types, keyed by the value stored in periods.type.
 */
final class PeriodTypes
{
    public const CALENDAR_WEEK = 'calendar_week';

    public const CALENDAR_MONTH = 'calendar_month';

    public const FIXED = 'fixed';

    public const RECURRING = 'recurring';

    public const ROLLING = 'rolling';

    private const TYPES = [
        self::CALENDAR_WEEK => CalendarWeekPeriod::class,
        self::CALENDAR_MONTH => CalendarMonthPeriod::class,
        self::FIXED => FixedPeriod::class,
        self::RECURRING => RecurringPeriod::class,
        self::ROLLING => RollingPeriod::class,
    ];

    public static function get(string $key): PeriodType
    {
        $class = self::TYPES[$key] ?? throw new InvalidArgumentException("Unknown period type [{$key}].");

        return app($class);
    }

    /**
     * @return array<string, PeriodType>
     */
    public static function all(): array
    {
        return array_map(fn (string $class): PeriodType => app($class), self::TYPES);
    }

    /**
     * Types a user can create a period of. Calendar types are offered too, so they can be
     * combined with a days-of-week filter (the built-in calendar periods count every day).
     *
     * @return array<string, string>
     */
    public static function creatableOptions(): array
    {
        return array_map(fn (PeriodType $type): string => $type->label(), self::all());
    }
}
