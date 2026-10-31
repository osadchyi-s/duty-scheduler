<?php

namespace App\Filament\Resources\DutyLimitResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\DutyLimitResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDutyLimit extends EditRecord
{
    use LogsRelationChanges;

    protected static string $resource = DutyLimitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Duplicates the bottom "Save" button at the top of the page; it submits the same form.
            $this->getSaveFormAction()->formId('form'),
            Actions\Action::make('history')
                ->label(__('History'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn (): string => ActivityLogResource::historyUrl($this->record))
                ->visible(fn (): bool => ActivityLogResource::canViewAny()),
            Actions\DeleteAction::make(),
        ];
    }

    protected function loggedRelations(): array
    {
        return [
            'groups' => 'name',
            'eventTypes' => 'name',
        ];
    }
}
