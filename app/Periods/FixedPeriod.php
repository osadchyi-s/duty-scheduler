<?php

namespace App\Periods;

use Carbon\Carbon;
use Filament\Forms;

/**
 * A single date range; params: start_date, end_date (inclusive).
 */
class FixedPeriod implements PeriodType
{
    public function label(): string
    {
        return __('Fixed period');
    }

    public function isSystem(): bool
    {
        return false;
    }

    public function windowsContaining(array $params, Carbon $day): array
    {
        if (empty($params['start_date']) || empty($params['end_date'])) {
            return [];
        }

        $window = new PeriodWindow(
            Carbon::parse($params['start_date'])->startOfDay(),
            Carbon::parse($params['end_date'])->startOfDay()->addDay(),
        );

        return $window->contains($day) ? [$window] : [];
    }

    public function formSchema(): array
    {
        return [
            Forms\Components\DatePicker::make('start_date')
                ->label(__('Start date'))
                ->required(),
            Forms\Components\DatePicker::make('end_date')
                ->label(__('End date (inclusive)'))
                ->required()
                ->afterOrEqual('start_date'),
        ];
    }

    public function describe(array $params): string
    {
        if (empty($params['start_date']) || empty($params['end_date'])) {
            return '';
        }

        return Carbon::parse($params['start_date'])->format('d.m.Y').'–'.Carbon::parse($params['end_date'])->format('d.m.Y');
    }
}
