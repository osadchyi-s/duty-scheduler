<?php

namespace App\Periods;

use Carbon\Carbon;
use Filament\Forms;

/**
 * Sliding window: any length_days consecutive days. Unlike the other types,
 * a day belongs to several windows (one starting on each of the preceding
 * length_days - 1 days and on the day itself), so a limit holds for every
 * stretch of consecutive days, not only for fixed calendar slots.
 */
class RollingPeriod implements PeriodType
{
    public function label(): string
    {
        return __('Rolling period');
    }

    public function isSystem(): bool
    {
        return false;
    }

    public function windowsContaining(array $params, Carbon $day): array
    {
        $length = (int) ($params['length_days'] ?? 0);
        if ($length < 1) {
            return [];
        }

        $windows = [];
        for ($offset = $length - 1; $offset >= 0; $offset--) {
            $windows[] = PeriodWindow::ofDays($day->copy()->startOfDay()->subDays($offset), $length);
        }

        return $windows;
    }

    public function formSchema(): array
    {
        return [
            Forms\Components\TextInput::make('length_days')
                ->label(__('Number of consecutive days'))
                ->helperText(__('The limit holds for every stretch of this many consecutive days.'))
                ->integer()
                ->minValue(1)
                ->maxValue(366)
                ->required(),
        ];
    }

    public function describe(array $params): string
    {
        return __('any :length consecutive days', ['length' => $params['length_days'] ?? '']);
    }
}
