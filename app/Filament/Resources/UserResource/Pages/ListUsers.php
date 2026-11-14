<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Support\AuditLog;
use App\Support\MatrixCache;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Filament saves the new order with one bulk update (no model events), so the
     * matrix cache is refreshed and one summary entry is logged here.
     */
    public function reorderTable(array $order): void
    {
        parent::reorderTable($order);

        MatrixCache::invalidateEmployees();
        AuditLog::record('employees_reordered', null, ['order' => array_map('intval', array_values($order))], AuditLog::LOG_RECORDS);
    }
}
