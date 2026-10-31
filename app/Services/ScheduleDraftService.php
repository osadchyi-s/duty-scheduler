<?php

namespace App\Services;

use App\Models\EventType;
use App\Models\ScheduleDraft;
use App\Models\User;
use App\Models\UserEvent;
use App\Support\AuditLog;
use App\Support\MatrixCache;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of schedule drafts: create (copying the approved month), duplicate,
 * and approve (replacing the approved month's schedulable events with the draft's).
 */
class ScheduleDraftService
{
    public function __construct(protected ConstraintsEngine $engine)
    {
    }

    /**
     * Creates a draft of the month with a copy of the approved events that
     * overlap it, so the draft starts from the current schedule.
     */
    public function create(Carbon $month, ?User $user, ?string $name = null): ScheduleDraft
    {
        $monthStart = $month->copy()->startOfMonth()->startOfDay();

        return DB::transaction(function () use ($monthStart, $user, $name): ScheduleDraft {
            $draft = ScheduleDraft::create([
                'name' => $name ?? $this->defaultName($monthStart),
                'month' => $monthStart,
                'created_by' => $user?->id,
                'snapshot_at' => now(),
            ]);

            [$from, $to] = $draft->window();
            $approvedEvents = UserEvent::query()
                ->inScenario(null)
                ->where('status', '!=', 'cancelled')
                ->overlappingPeriod($from, $to)
                ->get();

            foreach ($approvedEvents as $event) {
                $this->copyEvent($event, $draft, $event->id);
            }

            MatrixCache::forgetRange($from, $from, $draft->id);

            AuditLog::record('draft_created', $draft, [
                'name' => $draft->name,
                'month' => $draft->month->format('Y-m'),
                'copied_events' => $approvedEvents->count(),
            ]);

            return $draft;
        });
    }

    /**
     * Copies a draft with all its events. The copy keeps the original's
     * snapshot time and links to the approved events.
     */
    public function duplicate(ScheduleDraft $draft, ?User $user): ScheduleDraft
    {
        return DB::transaction(function () use ($draft, $user): ScheduleDraft {
            $copy = ScheduleDraft::create([
                'name' => __('Copy of :name', ['name' => $draft->name]),
                'month' => $draft->month,
                'created_by' => $user?->id,
                'snapshot_at' => $draft->snapshot_at,
            ]);

            $events = $draft->events()->get();
            foreach ($events as $event) {
                $this->copyEvent($event, $copy, $event->source_event_id);
            }

            AuditLog::record('draft_duplicated', $copy, [
                'name' => $copy->name,
                'month' => $copy->month->format('Y-m'),
                'source_draft_id' => $draft->id,
                'source_draft_name' => $draft->name,
                'events' => $events->count(),
            ]);

            return $copy;
        });
    }

    /**
     * What approving the draft changes in the approved schedule. Only events of
     * schedulable types that start within the month are replaced; other events
     * (vacations, sick leave, ...) of the approved schedule always stay.
     *
     * @return array{remove: Collection<int, UserEvent>, add: Collection<int, UserEvent>, unchanged: Collection<int, UserEvent>}
     */
    public function approvalPlan(ScheduleDraft $draft): array
    {
        [$from, $to] = $draft->window();
        $schedulableTypeIds = EventType::where('is_schedulable', true)->pluck('id');

        $approved = UserEvent::query()
            ->inScenario(null)
            ->where('status', '!=', 'cancelled')
            ->whereIn('event_type_id', $schedulableTypeIds)
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->with(['user', 'eventType'])
            ->orderBy('starts_at')
            ->get()
            ->keyBy('id');

        $draftEvents = $draft->events()
            ->where('status', '!=', 'cancelled')
            ->whereIn('event_type_id', $schedulableTypeIds)
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->with(['user', 'eventType'])
            ->orderBy('starts_at')
            ->get();

        // A draft event that still equals the approved event it was copied from needs no change.
        [$unchanged, $add] = $draftEvents->partition(function (UserEvent $event) use ($approved): bool {
            $source = $event->source_event_id ? $approved->get($event->source_event_id) : null;

            return $source !== null && $this->sameAssignment($event, $source);
        });

        $keptIds = $unchanged->pluck('source_event_id')->flip();

        return [
            'remove' => $approved->reject(fn (UserEvent $event): bool => $keptIds->has($event->id))->values(),
            'add' => $add->values(),
            'unchanged' => $unchanged->values(),
        ];
    }

    /**
     * Replaces the month's schedulable approved events with the draft's ones.
     * Every new event is validated against the resulting schedule; if any is
     * forbidden, nothing is changed. Other drafts of the month become stale.
     *
     * @param  array<int, int>  $keepEventIds  approved events to keep although the draft does not contain them
     *
     * @throws ValidationException with the list of forbidden assignments
     */
    public function approve(ScheduleDraft $draft, array $keepEventIds, ?User $user): void
    {
        $summary = [];

        // One summary entry is logged after the approval instead of an entry per replaced event.
        // A regular closure (not fn) so that $summary is shared by reference.
        AuditLog::withoutModelLogs(function () use ($draft, $keepEventIds, $user, &$summary): void {
            DB::transaction(function () use ($draft, $keepEventIds, $user, &$summary): void {
                // Serializes concurrent approvals of drafts of the same month.
                ScheduleDraft::query()->forMonth($draft->month)->lockForUpdate()->get();

                $plan = $this->approvalPlan($draft);
                $keep = collect($keepEventIds)->map(fn ($id): int => (int) $id)->flip();

                $removedIds = $plan['remove']->reject(fn (UserEvent $event): bool => $keep->has($event->id))->pluck('id');
                UserEvent::query()->whereKey($removedIds)->delete();

                // Insert everything first, so each event is validated against the complete result.
                $created = $plan['add']->map(fn (UserEvent $draftEvent): array => [$draftEvent, UserEvent::create([
                    'user_id' => $draftEvent->user_id,
                    'event_type_id' => $draftEvent->event_type_id,
                    'starts_at' => $draftEvent->starts_at,
                    'ends_at' => $draftEvent->ends_at,
                    'status' => 'approved',
                    'note' => $draftEvent->note,
                    'created_by' => $user?->id ?? $draftEvent->created_by,
                ])]);

                $problems = [];
                foreach ($created as [$draftEvent, $event]) {
                    $result = $this->engine->validate(
                        $draftEvent->user, $draftEvent->eventType, $event->starts_at, $event->ends_at, $event->id
                    );

                    if (! $result->isAllowed) {
                        $problems[] = $draftEvent->starts_at->format('d.m').' '.$draftEvent->user->name.': '.$result->reason;

                        continue;
                    }

                    $event->update([
                        'has_warning' => $result->status === ConstraintCheckResult::STATUS_WARNING,
                        'warning_reason' => $result->reason,
                    ]);
                }

                if ($problems !== []) {
                    throw ValidationException::withMessages(['draft' => $problems]);
                }

                $draft->update(['approved_at' => now(), 'approved_by' => $user?->id, 'stale_at' => null]);

                $staleDrafts = ScheduleDraft::query()
                    ->forMonth($draft->month)
                    ->whereKeyNot($draft->id)
                    ->update(['stale_at' => now()]);

                $summary = [
                    'name' => $draft->name,
                    'month' => $draft->month->format('Y-m'),
                    'added' => $plan['add']->count(),
                    'removed' => $removedIds->count(),
                    'kept' => $plan['remove']->count() - $removedIds->count(),
                    'unchanged' => $plan['unchanged']->count(),
                    'stale_drafts' => $staleDrafts,
                ];
            });
        });

        AuditLog::record('draft_approved', $draft, $summary);

        [$from, $to] = $draft->window();
        // Events may cross the month boundary, so neighbouring months are refreshed too.
        MatrixCache::forgetRange($from->copy()->subMonth(), $to, null);
    }

    public function defaultName(Carbon $month): string
    {
        $number = ScheduleDraft::query()->forMonth($month)->count() + 1;

        return __('Draft :number', ['number' => $number]);
    }

    protected function copyEvent(UserEvent $event, ScheduleDraft $draft, ?int $sourceEventId): UserEvent
    {
        $copy = $event->replicate(['schedule_draft_id', 'source_event_id']);
        $copy->schedule_draft_id = $draft->id;
        $copy->source_event_id = $sourceEventId;
        $copy->save();

        return $copy;
    }

    protected function sameAssignment(UserEvent $a, UserEvent $b): bool
    {
        return (int) $a->user_id === (int) $b->user_id
            && (int) $a->event_type_id === (int) $b->event_type_id
            && $a->starts_at->eq($b->starts_at)
            && $a->ends_at->eq($b->ends_at)
            && $a->note === $b->note;
    }
}
