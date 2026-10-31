<?php

namespace App\Filament\Concerns;

use App\Support\AuditLog;

/**
 * For create/edit pages of resources with BelongsToMany fields: logs which
 * related records (roles, groups, ...) were added or removed, because such
 * changes fire no model events.
 */
trait LogsRelationChanges
{
    /** @var array<string, array<int, string>> */
    protected array $relationsBeforeSave = [];

    /**
     * @return array<string, string> relation => attribute shown in the log
     */
    abstract protected function loggedRelations(): array;

    protected function beforeSave(): void
    {
        $this->relationsBeforeSave = AuditLog::relationSnapshot($this->record, $this->loggedRelations());
    }

    protected function afterSave(): void
    {
        AuditLog::recordRelationChanges($this->record, $this->relationsBeforeSave, $this->loggedRelations());
    }

    protected function afterCreate(): void
    {
        AuditLog::recordRelationChanges($this->record, [], $this->loggedRelations());
    }
}
