<?php

namespace App\Filament\Resources\DutyLimitResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\DutyLimitResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDutyLimit extends CreateRecord
{
    use LogsRelationChanges;

    protected static string $resource = DutyLimitResource::class;

    protected function loggedRelations(): array
    {
        return [
            'groups' => 'name',
            'eventTypes' => 'name',
        ];
    }
}
