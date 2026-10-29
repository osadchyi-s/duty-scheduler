<?php

namespace App\Filament\Resources\PeriodResource\Pages;

use App\Filament\Resources\PeriodResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPeriod extends EditRecord
{
    protected static string $resource = PeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Duplicates the bottom "Save" button at the top of the page; it submits the same form.
            $this->getSaveFormAction()->formId('form'),
            Actions\DeleteAction::make(),
        ];
    }
}
