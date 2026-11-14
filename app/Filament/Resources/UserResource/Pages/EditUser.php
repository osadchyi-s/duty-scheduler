<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\UserResource;
use Filament\Actions;
use App\Filament\Concerns\HasSaveAndCloseActions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use LogsRelationChanges, HasSaveAndCloseActions;

    protected static string $resource = UserResource::class;

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
            'roles' => 'name',
            'employeeGroups' => 'name',
            'allowedEventTypes' => 'name',
        ];
    }
}
