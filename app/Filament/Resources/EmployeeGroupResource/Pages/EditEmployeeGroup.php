<?php

namespace App\Filament\Resources\EmployeeGroupResource\Pages;

use App\Filament\Resources\EmployeeGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeGroup extends EditRecord
{
    protected static string $resource = EmployeeGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
