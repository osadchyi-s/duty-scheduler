<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use LogsRelationChanges;

    protected static string $resource = UserResource::class;

    protected function loggedRelations(): array
    {
        return [
            'roles' => 'name',
            'employeeGroups' => 'name',
            'allowedEventTypes' => 'name',
        ];
    }
}
