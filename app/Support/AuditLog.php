<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Activity log entries that are not plain model changes: schedule operations,
 * relation changes and authentication. Model changes are logged by the
 * RecordsActivity trait (spatie/laravel-activitylog).
 */
final class AuditLog
{
    /** Changes of users, event types, groups, periods, limits, roles, settings. */
    public const LOG_RECORDS = 'records';

    /** Approved schedule events and schedule operations (auto-generation, drafts). */
    public const LOG_SCHEDULE = 'schedule';

    public const LOG_AUTH = 'auth';

    /**
     * Records one entry. The description is the event key; the journal page
     * shows a localized label for it.
     */
    public static function record(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        string $logName = self::LOG_SCHEDULE,
        ?Model $causer = null,
    ): void {
        $logger = activity($logName)->event($event)->withProperties($properties);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        $logger->log($event);
    }

    /**
     * Runs a bulk operation without one entry per created or deleted model;
     * the caller records a single summary entry instead.
     */
    public static function withoutModelLogs(Closure $callback): mixed
    {
        return activity()->withoutLogs($callback);
    }

    /**
     * Current values of BelongsToMany relations, e.g. ['roles' => ['Admin']].
     * Such changes fire no model events, so pages log them explicitly.
     *
     * @param  array<string, string>  $relations  relation => attribute to show
     * @return array<string, array<int, string>>
     */
    public static function relationSnapshot(Model $model, array $relations): array
    {
        $snapshot = [];

        foreach ($relations as $relation => $attribute) {
            $query = $model->{$relation}();
            $snapshot[$relation] = $query->pluck($query->getRelated()->qualifyColumn($attribute))
                ->map(fn ($value): string => (string) $value)->sort()->values()->all();
        }

        return $snapshot;
    }

    /**
     * Logs the relations that differ from the snapshot taken before saving.
     *
     * @param  array<string, array<int, string>>  $before  empty for a newly created model
     * @param  array<string, string>  $relations
     */
    public static function recordRelationChanges(Model $model, array $before, array $relations, string $logName = self::LOG_RECORDS): void
    {
        $changes = [];

        foreach (self::relationSnapshot($model, $relations) as $relation => $after) {
            $old = $before[$relation] ?? [];
            if ($old !== $after) {
                $changes[$relation] = ['old' => $old, 'new' => $after];
            }
        }

        if ($changes !== []) {
            self::record('relations_updated', $model, ['relations' => $changes], $logName);
        }
    }
}
