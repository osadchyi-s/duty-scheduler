<?php

namespace App\Filament\Resources\EmployeeGroupResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\EmployeeGroupResource;
use App\Filament\Concerns\HasCreateAndCloseActions;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeGroup extends CreateRecord
{
    use LogsRelationChanges, HasCreateAndCloseActions;

    protected static string $resource = EmployeeGroupResource::class;

    protected function loggedRelations(): array
    {
        return [
            'users' => 'name',
        ];
    }
}
