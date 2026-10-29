<?php

namespace App\Periods;

use Carbon\Carbon;
use Filament\Forms;

/**
 * A window of length_days that repeats every repeat_every_days, counted from
 * anchor_date in both directions. Example: 7 days every 14 days.
 */
class RecurringPeriod implements PeriodType
{
    public function label(): string
    {
        return __('Recurring period');
    }

    public function isSystem(): bool
    {
        return false;
    }

    public function windowsContaining(array $params, Carbon $day): array
    {
        $length = (int) ($params['length_days'] ?? 0);
        $repeat = (int) ($params['repeat_every_days'] ?? 0);

        if (empty($params['anchor_date']) || $length < 1 || $repeat < $length) {
            return [];
        }

        $anchor = Carbon::parse($params['anchor_date'])->startOfDay();
        // Rounded because a DST switch makes the difference between two midnights fractional.
        $offset = (int) round($anchor->diffInDays($day->copy()->startOfDay(), false));
        $cycle = intdiv($offset, $repeat) - ($offset % $repeat < 0 ? 1 : 0);
        $offsetInCycle = $offset - $cycle * $repeat;

        if ($offsetInCycle >= $length) {
            return [];
        }

        return [PeriodWindow::ofDays($anchor->copy()->addDays($cycle * $repeat), $length)];
    }

    public function formSchema(): array
    {
        return [
            Forms\Components\DatePicker::make('anchor_date')
                ->label(__('First period starts on'))
                ->required(),
            Forms\Components\TextInput::make('length_days')
                ->label(__('Period length (days)'))
                ->integer()
                ->minValue(1)
                ->required(),
            Forms\Components\TextInput::make('repeat_every_days')
                ->label(__('Repeats every (days)'))
                ->helperText(__('Must not be shorter than the period length'))
                ->integer()
                ->minValue(1)
                ->gte('length_days')
                ->required(),
        ];
    }

    public function describe(array $params): string
    {
        if (empty($params['anchor_date'])) {
            return '';
        }

        return __(':length days every :repeat days from :date', [
            'length' => $params['length_days'] ?? '',
            'repeat' => $params['repeat_every_days'] ?? '',
            'date' => Carbon::parse($params['anchor_date'])->format('d.m.Y'),
        ]);
    }
}
