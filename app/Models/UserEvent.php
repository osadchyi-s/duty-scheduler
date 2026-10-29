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
        'has_warning',
        'warning_reason',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'has_warning' => 'boolean',
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

    /**
     * Days the event counts as for duty limits: ceil(duration / 24 h) days,
     * starting from the start day. In "days" mode these are exactly the covered
     * days; a 24-hour shift from Sunday 08:00 counts only for Sunday.
     *
     * @return array<int, Carbon>
     */
    public static function countedDaysFor(Carbon $startsAt, Carbon $endsAt): array
    {
        // Calendar-aware day difference, so a midnight-to-midnight day stays one day across a DST switch.
        $count = max(1, (int) ceil(round($startsAt->diffInDays($endsAt), 6)));
        $firstDay = $startsAt->copy()->startOfDay();
        $days = [];

        for ($i = 0; $i < $count; $i++) {
            $days[] = $firstDay->copy()->addDays($i);
        }

        return $days;
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
