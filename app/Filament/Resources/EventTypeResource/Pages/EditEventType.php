<?php

namespace App\Filament\Resources\EventTypeResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\EventTypeResource;
use Filament\Actions;
use App\Filament\Concerns\HasSaveAndCloseActions;
use Filament\Resources\Pages\EditRecord;

class EditEventType extends EditRecord
{
    use LogsRelationChanges, HasSaveAndCloseActions;

    protected static string $resource = EventTypeResource::class;

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

    protected function loggedRelations(): array
    {
        return [
            'allowedUsers' => 'name',
        ];
    }
}
