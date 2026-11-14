<?php

namespace App\Filament\Resources\PeriodResource\Pages;

use App\Filament\Resources\PeriodResource;
use App\Filament\Resources\ActivityLogResource;
use Filament\Actions;
use App\Filament\Concerns\HasSaveAndCloseActions;
use Filament\Resources\Pages\EditRecord;

class EditPeriod extends EditRecord
{
    use HasSaveAndCloseActions;

    protected static string $resource = PeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->getSaveHeaderActions(),
            Actions\Action::make('history')
                ->label(__('History'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn (): string => ActivityLogResource::historyUrl($this->record))
                ->visible(fn (): bool => ActivityLogResource::canViewAny()),
            Actions\DeleteAction::make(),
        ];
    }
}
