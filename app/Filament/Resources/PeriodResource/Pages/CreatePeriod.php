<?php

namespace App\Filament\Resources\PeriodResource\Pages;

use App\Filament\Resources\PeriodResource;
use App\Filament\Concerns\HasCreateAndCloseActions;
use Filament\Resources\Pages\CreateRecord;

class CreatePeriod extends CreateRecord
{
    use HasCreateAndCloseActions;

    protected static string $resource = PeriodResource::class;
}
