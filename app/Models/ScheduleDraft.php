<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An alternative version of one month's schedule. It holds a copy of the
 * approved events of the month (taken at snapshot_at) plus any changes.
 */
class ScheduleDraft extends Model
{
    protected $fillable = [
        'name',
        'month',
        'created_by',
        'snapshot_at',
        'approved_at',
        'approved_by',
        'stale_at',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'snapshot_at' => 'datetime',
            'approved_at' => 'datetime',
            'stale_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(UserEvent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeForMonth(Builder $query, Carbon $month): Builder
    {
        return $query->whereDate('month', $month->copy()->startOfMonth()->toDateString());
    }

    /**
     * The drafted month as [start, end), both at 00:00.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(): array
    {
        $start = $this->month->copy()->startOfMonth()->startOfDay();

        return [$start, $start->copy()->addMonth()];
    }

    public function isStale(): bool
    {
        return $this->stale_at !== null;
    }
}
