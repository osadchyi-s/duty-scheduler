<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Support\AuditLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEvent extends Model
{
    use HasFactory;
    use RecordsActivity {
        shouldLogEvent as protected shouldLogActivityEvent;
    }

    protected $attributes = [
        'status' => 'approved',
    ];

    protected $fillable = [
        'schedule_draft_id',
        'source_event_id',
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

    /**
     * Only the approved schedule is logged event by event; drafts are scratch
     * work, their operations (creation, approval, ...) are logged as a whole.
     */
    protected function shouldLogEvent(string $eventName): bool
    {
        return $this->schedule_draft_id === null && $this->shouldLogActivityEvent($eventName);
    }

    protected function activityLogName(): string
    {
        return AuditLog::LOG_SCHEDULE;
    }

    protected function activityLogAttributes(): array
    {
        return ['user_id', 'event_type_id', 'starts_at', 'ends_at', 'status', 'note'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ScheduleDraft::class, 'schedule_draft_id');
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

    /**
     * Events visible in a scenario. Without a draft: the approved schedule.
     * With a draft: the draft's own events plus approved events outside the
     * drafted month (those inside it were copied into the draft), so rest
     * periods and duty limits across the month boundary are still checked.
     */
    public function scopeInScenario(Builder $query, ?ScheduleDraft $draft): Builder
    {
        if ($draft === null) {
            return $query->whereNull('schedule_draft_id');
        }

        [$from, $to] = $draft->window();

        return $query->where(fn (Builder $scenario) => $scenario
            ->where('schedule_draft_id', $draft->id)
            ->orWhere(fn (Builder $approved) => $approved
                ->whereNull('schedule_draft_id')
                ->where(fn (Builder $outside) => $outside->where('ends_at', '<=', $from)->orWhere('starts_at', '>=', $to))));
    }

    public function scopeOverlappingPeriod($query, $from, $to)
    {
        return $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }
}
