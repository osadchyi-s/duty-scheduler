<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Concerns\LogsRelationChanges;
use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use LogsRelationChanges;

    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';

        return $data;
    }

    protected function loggedRelations(): array
    {
        return [
            'permissions' => 'name',
        ];
    }
}
