<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_type_id',
        'starts_at',
        'ends_at',
        'status',
        'has_rest_warning',
        'warning_reason',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'has_rest_warning' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function durationHours(): float
    {
        return $this->starts_at->diffInMinutes($this->ends_at) / 60;
    }

    /**
     * Last calendar day the event occupies. The end is exclusive, so an event
     * ending exactly at 00:00 does not cover that day.
     */
    public function lastCoveredDay(): Carbon
    {
        return static::lastCoveredDayFor($this->starts_at, $this->ends_at);
    }

    public static function lastCoveredDayFor(Carbon $startsAt, Carbon $endsAt): Carbon
    {
        $lastDay = $endsAt->copy()->startOfDay();

        if ($endsAt->eq($lastDay) && $endsAt->gt($startsAt)) {
            $lastDay->subDay();
        }

        return $lastDay;
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeOverlappingPeriod($query, $from, $to)
    {
        return $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }
}
