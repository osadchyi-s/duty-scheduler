<?php

namespace App\Models\Concerns;

use App\Support\AuditLog;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Logs creating, updating and deleting the model (only changed attributes).
 * Models may override activityLogAttributes() and activityLogName().
 */
trait RecordsActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->activityLogName())
            ->logOnly($this->activityLogAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Attributes written to the log; fillable by default.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return $this->getFillable();
    }

    protected function activityLogName(): string
    {
        return AuditLog::LOG_RECORDS;
    }
}
