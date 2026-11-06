<?php

namespace App\Periods;

use Carbon\Carbon;

/**
 * One concrete occurrence of a period: [start, end), both at 00:00.
 */
final class PeriodWindow
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {
    }

    public static function ofDays(Carbon $firstDay, int $days): self
    {
        $start = $firstDay->copy()->startOfDay();

        return new self($start, $start->copy()->addDays($days));
    }

    public function key(): string
    {
        return $this->start->toDateString().'/'.$this->end->toDateString();
    }

    public function contains(Carbon $day): bool
    {
        return $day->gte($this->start) && $day->lt($this->end);
    }

    /**
     * Inclusive date range for messages, e.g. "02.11.2026–08.11.2026".
     */
    public function label(): string
    {
        return $this->start->format('d.m.Y').'–'.$this->end->copy()->subDay()->format('d.m.Y');
    }
}
