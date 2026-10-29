<?php

namespace App\Filament\Resources\DutyLimitResource\Pages;

use App\Filament\Resources\DutyLimitResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDutyLimit extends EditRecord
{
    protected static string $resource = DutyLimitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Duplicates the bottom "Save" button at the top of the page; it submits the same form.
            $this->getSaveFormAction()->formId('form'),
            Actions\DeleteAction::make(),
        ];
    }
}
