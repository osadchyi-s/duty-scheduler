<?php

namespace App\Periods;

use Carbon\Carbon;

/**
 * Days-of-week filter shared by all period types (params.weekdays, ISO numbers,
 * 1 = Monday). An empty list means every day.
 */
final class Weekdays
{
    /**
     * Sorted unique ISO numbers; all seven days are stored as an empty list, so
     * "every day" has a single representation.
     *
     * @return array<int, int>
     */
    public static function normalize(mixed $weekdays): array
    {
        $days = collect(is_array($weekdays) ? $weekdays : [])
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return count($days) === 7 ? [] : $days;
    }

    /**
     * Localized full day names, Monday first, for form options.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return collect(range(1, 7))
            ->mapWithKeys(fn (int $day): array => [$day => self::day($day)->translatedFormat('l')])
            ->all();
    }

    /**
     * @param  array<int, int>  $weekdays
     */
    public static function shortNames(array $weekdays): string
    {
        return collect($weekdays)->map(fn (int $day): string => self::day($day)->translatedFormat('D'))->implode(', ');
    }

    private static function day(int $isoDay): Carbon
    {
        // Any Monday works; the date only selects the day of the week.
        return Carbon::create(2024, 1, 1)->addDays($isoDay - 1);
    }
}
