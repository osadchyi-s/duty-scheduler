<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Activitylog\Models\Activity;

/**
 * Deletes activity log entries older than the retention period
 * (activitylog.delete_records_older_than_days). Dispatched at most once a day
 * when a new entry is written (see AppServiceProvider), so no scheduler is needed.
 */
class PruneActivityLog implements ShouldQueue
{
    use Queueable;

    /** Rows deleted per statement, to keep table locks short. */
    private const CHUNK = 1000;

    public function handle(): void
    {
        $cutoff = now()->subDays((int) config('activitylog.delete_records_older_than_days', 365));

        do {
            $ids = Activity::query()->where('created_at', '<', $cutoff)->limit(self::CHUNK)->pluck('id');
            Activity::query()->whereKey($ids)->delete();
        } while ($ids->count() === self::CHUNK);
    }
}
