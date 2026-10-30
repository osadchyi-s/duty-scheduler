<?php

namespace App\Periods;

use Carbon\Carbon;

/**
 * Calendar week starting on Monday (ISO 8601).
 */
class CalendarWeekPeriod implements PeriodType
{
    public function label(): string
    {
        return __('Calendar week');
    }

    public function isSystem(): bool
    {
        return true;
    }

    public function windowsContaining(array $params, Carbon $day): array
    {
        return [PeriodWindow::ofDays($day->copy()->startOfWeek(Carbon::MONDAY), 7)];
    }

    public function formSchema(): array
    {
        return [];
    }

    public function describe(array $params): string
    {
        return __('Monday to Sunday');
    }
}
