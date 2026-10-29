<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Maximum number of duty days per employee of the selected groups within each
 * window of a period. Without selected event types, all working event types count.
 */
class DutyLimit extends Model
{
    public const SEVERITY_FORBIDDEN = 'forbidden';

    public const SEVERITY_WARNING = 'warning';

    protected $fillable = [
        'name',
        'period_id',
        'max_days',
        'severity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'max_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(EmployeeGroup::class)->withTimestamps();
    }

    public function eventTypes(): BelongsToMany
    {
        return $this->belongsToMany(EventType::class)->withTimestamps();
    }

    public function isHard(): bool
    {
        return $this->severity === self::SEVERITY_FORBIDDEN;
    }

    /**
     * Requires groups.users and eventTypes to be loaded.
     */
    public function appliesToUser(int $userId): bool
    {
        return $this->groups->contains(fn (EmployeeGroup $group): bool => $group->users->contains('id', $userId));
    }

    public function appliesToEventType(EventType $eventType): bool
    {
        return $this->eventTypes->isEmpty()
            ? $eventType->is_work_time
            : $this->eventTypes->contains('id', $eventType->id);
    }
}
