<?php

namespace App\Filament\Resources\DutyLimitResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\DutyLimitResource;
use App\Filament\Concerns\HasCreateAndCloseActions;
use Filament\Resources\Pages\CreateRecord;

class CreateDutyLimit extends CreateRecord
{
    use LogsRelationChanges, HasCreateAndCloseActions;

    protected static string $resource = DutyLimitResource::class;

    protected function loggedRelations(): array
    {
        return [
            'groups' => 'name',
            'eventTypes' => 'name',
        ];
    }
}
