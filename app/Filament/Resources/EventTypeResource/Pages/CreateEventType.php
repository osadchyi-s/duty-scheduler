<?php

namespace App\Filament\Resources\EventTypeResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\EventTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventType extends CreateRecord
{
    use LogsRelationChanges;

    protected static string $resource = EventTypeResource::class;

    protected function loggedRelations(): array
    {
        return [
            'allowedUsers' => 'name',
        ];
    }
}
