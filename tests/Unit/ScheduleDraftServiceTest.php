<?php

use App\Models\EventType;
use App\Models\ScheduleDraft;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\ConstraintsEngine;
use App\Services\ScheduleDraftService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['scheduling.time_unit' => 'days']);
    app()->setLocale('uk');

    $this->service = app(ScheduleDraftService::class);

    $this->duty = EventType::create([
        'name' => 'Чергування', 'code' => 'duty', 'color_code' => '#3B82F6',
        'min_capacity' => 1, 'max_capacity' => 2, 'min_rest_hours' => 24, 'preferred_rest_hours' => 24,
        'default_duration_hours' => 24, 'is_schedulable' => true, 'is_work_time' => true, 'is_active' => true,
    ]);
    $this->vacation = EventType::create([
        'name' => 'Відпустка', 'code' => 'vacation', 'color_code' => '#F59E0B',
        'is_blocking' => true, 'is_schedulable' => false, 'is_work_time' => false,
    ]);

    $this->ann = User::create(['name' => 'Анна', 'email' => 'ann@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->bob = User::create(['name' => 'Борис', 'email' => 'bob@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    foreach ([$this->ann, $this->bob] as $user) {
        $user->allowedEventTypes()->attach([$this->duty->id, $this->vacation->id]);
    }

    $this->march = Carbon::parse('2026-03-01');
});

function draftTestEvent(User $user, EventType $type, string $day, int $days = 1, ?ScheduleDraft $draft = null, string $status = 'approved'): UserEvent
{
    $start = Carbon::parse($day)->startOfDay();

    return UserEvent::create([
        'schedule_draft_id' => $draft?->id,
        'user_id' => $user->id, 'event_type_id' => $type->id,
        'starts_at' => $start, 'ends_at' => $start->copy()->addDays($days), 'status' => $status,
    ]);
}

it('creates a draft with a copy of the approved events of the month', function () {
    $inMonth = draftTestEvent($this->ann, $this->duty, '2026-03-05');
    $crossing = draftTestEvent($this->bob, $this->vacation, '2026-02-27', 5);
    draftTestEvent($this->ann, $this->duty, '2026-04-02');
    draftTestEvent($this->bob, $this->duty, '2026-03-10', status: 'cancelled');

    $first = $this->service->create($this->march, $this->ann);
    $second = $this->service->create($this->march, $this->ann);

    expect($first->name)->toBe('Чернетка 1')
        ->and($second->name)->toBe('Чернетка 2')
        ->and($first->events()->pluck('source_event_id')->sort()->values()->all())->toBe([$inMonth->id, $crossing->id])
        ->and(UserEvent::whereNull('schedule_draft_id')->count())->toBe(4);
});

it('shows a draft its own events plus approved events outside the month', function () {
    draftTestEvent($this->ann, $this->duty, '2026-03-05');
    $february = draftTestEvent($this->ann, $this->duty, '2026-02-20');
    $draft = $this->service->create($this->march, null);
    $draftOnly = draftTestEvent($this->bob, $this->duty, '2026-03-07', draft: $draft);

    $visible = UserEvent::inScenario($draft)->pluck('id');

    expect($visible)->toContain($february->id, $draftOnly->id)
        ->and($visible)->toHaveCount(3) // the copy of 05.03, 20.02 and 07.03
        ->and(UserEvent::inScenario(null)->pluck('id'))->not->toContain($draftOnly->id);
});

it('validates draft assignments against approved events across the month boundary', function () {
    draftTestEvent($this->ann, $this->duty, '2026-02-28');
    $draft = $this->service->create($this->march, null);
    $engine = app(ConstraintsEngine::class);
    $start = Carbon::parse('2026-03-01');

    // The duty on 28.02 requires a free 01.03.
    expect($engine->validate($this->ann, $this->duty, $start, $start->copy()->addDay(), null, $draft)->isAllowed)->toBeFalse();

    // A draft event does not affect the approved schedule.
    draftTestEvent($this->bob, $this->duty, '2026-03-01', draft: $draft);
    expect($engine->validate($this->bob, $this->duty, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-03'))->isAllowed)->toBeTrue()
        ->and($engine->validate($this->bob, $this->duty, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-03'), null, $draft)->isAllowed)->toBeFalse();
});

it('duplicates a draft with its events and snapshot', function () {
    $approved = draftTestEvent($this->ann, $this->duty, '2026-03-05');
    $draft = $this->service->create($this->march, null);
    draftTestEvent($this->bob, $this->duty, '2026-03-07', draft: $draft);

    $copy = $this->service->duplicate($draft, null);

    expect($copy->name)->toBe('Копія: Чернетка 1')
        ->and($copy->snapshot_at->eq($draft->snapshot_at))->toBeTrue()
        ->and($copy->events()->count())->toBe(2)
        ->and($copy->events()->whereNotNull('source_event_id')->value('source_event_id'))->toBe($approved->id);
});

it('approves a draft by replacing only schedulable events of the month', function () {
    $kept = draftTestEvent($this->ann, $this->duty, '2026-03-05');
    $moved = draftTestEvent($this->bob, $this->duty, '2026-03-10');
    $draft = $this->service->create($this->march, null);

    $draft->events()->where('source_event_id', $moved->id)->update(['starts_at' => '2026-03-12 00:00:00', 'ends_at' => '2026-03-13 00:00:00']);
    draftTestEvent($this->ann, $this->duty, '2026-03-20', draft: $draft);
    // Added to the approved schedule after the draft was created; not schedulable, so it stays.
    $vacation = draftTestEvent($this->bob, $this->vacation, '2026-03-25', 3);
    $other = $this->service->create($this->march, null);

    $plan = $this->service->approvalPlan($draft);
    expect($plan['unchanged']->pluck('source_event_id')->all())->toBe([$kept->id])
        ->and($plan['remove']->pluck('id')->all())->toBe([$moved->id])
        ->and($plan['add'])->toHaveCount(2);

    $this->service->approve($draft, [], $this->ann);

    $approved = UserEvent::inScenario(null)->orderBy('starts_at')->get();
    expect($approved->map(fn (UserEvent $e) => $e->starts_at->format('d.m').' '.$e->user->name)->all())
        ->toBe(['05.03 Анна', '12.03 Борис', '20.03 Анна', '25.03 Борис'])
        ->and(UserEvent::find($kept->id))->not->toBeNull()
        ->and(UserEvent::find($moved->id))->toBeNull()
        ->and(UserEvent::find($vacation->id))->not->toBeNull()
        ->and($draft->fresh()->approved_at)->not->toBeNull()
        ->and($draft->fresh()->isStale())->toBeFalse()
        ->and($other->fresh()->isStale())->toBeTrue();
});

it('keeps approved events the user chose to keep', function () {
    $extra = draftTestEvent($this->bob, $this->duty, '2026-03-10');
    $draft = $this->service->create($this->march, null);
    $draft->events()->delete();

    $this->service->approve($draft, [$extra->id], null);

    expect(UserEvent::find($extra->id))->not->toBeNull();
});

it('approves nothing when an assignment breaks the rules in the approved schedule', function () {
    $draft = $this->service->create($this->march, null);
    draftTestEvent($this->ann, $this->duty, '2026-03-10', draft: $draft);
    // A vacation added to the approved schedule after the draft was created.
    draftTestEvent($this->ann, $this->vacation, '2026-03-09', 3);

    expect(fn () => $this->service->approve($draft, [], null))->toThrow(ValidationException::class);

    expect(UserEvent::inScenario(null)->where('event_type_id', $this->duty->id)->count())->toBe(0)
        ->and($draft->fresh()->approved_at)->toBeNull();
});

it('can approve a stale draft again', function () {
    $first = $this->service->create($this->march, null);
    draftTestEvent($this->ann, $this->duty, '2026-03-05', draft: $first);
    $second = $this->service->create($this->march, null);
    draftTestEvent($this->bob, $this->duty, '2026-03-06', draft: $second);

    $this->service->approve($second, [], null);
    expect($first->fresh()->isStale())->toBeTrue();

    $this->service->approve($first->fresh(), [], null);

    expect(UserEvent::inScenario(null)->pluck('user_id')->all())->toBe([$this->ann->id])
        ->and($first->fresh()->isStale())->toBeFalse()
        ->and($second->fresh()->isStale())->toBeTrue();
});

it('moves events with the old draft status into a draft of their month', function () {
    $approved = draftTestEvent($this->ann, $this->duty, '2026-03-05');
    $legacyDraft = draftTestEvent($this->bob, $this->duty, '2026-03-07');
    UserEvent::whereKey($legacyDraft->id)->update(['status' => 'draft']);

    (require database_path('migrations/2026_10_31_000002_move_draft_events_into_schedule_drafts.php'))->up();

    $draft = ScheduleDraft::sole();
    $legacyDraft->refresh();

    expect($draft->name)->toBe('Перенесені чернетки')
        ->and($draft->month->toDateString())->toBe('2026-03-01')
        ->and($legacyDraft->schedule_draft_id)->toBe($draft->id)
        ->and($legacyDraft->status)->toBe('approved')
        ->and($draft->events()->where('source_event_id', $approved->id)->exists())->toBeTrue()
        ->and(UserEvent::inScenario(null)->pluck('id')->all())->toBe([$approved->id]);
});
