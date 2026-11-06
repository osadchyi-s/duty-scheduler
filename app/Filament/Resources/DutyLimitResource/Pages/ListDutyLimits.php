<?php

namespace App\Filament\Resources\DutyLimitResource\Pages;

use App\Filament\Resources\DutyLimitResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDutyLimits extends ListRecords
{
    protected static string $resource = DutyLimitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
