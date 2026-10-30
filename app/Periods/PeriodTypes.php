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
     * Types a user can create a period of (built-in types are excluded).
     *
     * @return array<string, string>
     */
    public static function creatableOptions(): array
    {
        return collect(self::all())
            ->reject(fn (PeriodType $type): bool => $type->isSystem())
            ->map(fn (PeriodType $type): string => $type->label())
            ->all();
    }
}
