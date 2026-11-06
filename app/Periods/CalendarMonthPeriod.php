<?php

namespace App\Periods;

use Carbon\Carbon;

class CalendarMonthPeriod implements PeriodType
{
    public function label(): string
    {
        return __('Calendar month');
    }

    public function isSystem(): bool
    {
        return true;
    }

    public function windowsContaining(array $params, Carbon $day): array
    {
        $start = $day->copy()->startOfMonth();

        return [new PeriodWindow($start, $start->copy()->addMonth())];
    }

    public function formSchema(): array
    {
        return [];
    }

    public function describe(array $params): string
    {
        return __('From the 1st to the last day of the month');
    }
}
