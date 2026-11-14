<?php

namespace App\Periods;

use Carbon\Carbon;

/**
 * One concrete occurrence of a period: [start, end), both at 00:00. With
 * $weekdays (ISO numbers, 1 = Monday) only those days of the week belong to it.
 */
final class PeriodWindow
{
    /**
     * @param  array<int, int>  $weekdays  Empty: every day.
     */
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly array $weekdays = [],
    ) {
    }

    public static function ofDays(Carbon $firstDay, int $days): self
    {
        $start = $firstDay->copy()->startOfDay();

        return new self($start, $start->copy()->addDays($days));
    }

    /**
     * @param  array<int, int>  $weekdays
     */
    public function withWeekdays(array $weekdays): self
    {
        return new self($this->start, $this->end, $weekdays);
    }

    public function key(): string
    {
        return $this->start->toDateString().'/'.$this->end->toDateString()
            .($this->weekdays === [] ? '' : '/'.implode(',', $this->weekdays));
    }

    public function contains(Carbon $day): bool
    {
        return $day->gte($this->start) && $day->lt($this->end)
            && ($this->weekdays === [] || in_array($day->dayOfWeekIso, $this->weekdays, true));
    }

    /**
     * Inclusive date range for messages, e.g. "02.11.2026–08.11.2026", with the
     * counted days of the week if they are limited: "… (сб, нд)".
     */
    public function label(): string
    {
        $range = $this->start->format('d.m.Y').'–'.$this->end->copy()->subDay()->format('d.m.Y');

        return $this->weekdays === [] ? $range : $range.' ('.Weekdays::shortNames($this->weekdays).')';
    }
}
