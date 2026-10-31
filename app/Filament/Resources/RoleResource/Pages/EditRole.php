<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    use LogsRelationChanges;

    protected static string $resource = RoleResource::class;

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
            'permissions' => 'name',
        ];
    }
}
