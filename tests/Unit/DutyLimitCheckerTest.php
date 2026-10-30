<?php

use App\Models\DutyLimit;
use App\Models\EmployeeGroup;
use App\Models\EventType;
use App\Models\Period;
use App\Models\User;
use App\Models\UserEvent;
use App\Periods\PeriodTypes;
use App\Services\AutoScheduler;
use App\Services\ConstraintCheckResult;
use App\Services\ConstraintsEngine;
use App\Services\DutyLimitChecker;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['scheduling.time_unit' => 'days']);

    $this->dutyType = EventType::create([
        'name' => 'Добове чергування', 'code' => 'duty', 'color_code' => '#3B82F6',
        'min_capacity' => 1, 'max_capacity' => 5, 'min_rest_hours' => 0, 'preferred_rest_hours' => 0,
        'default_duration_hours' => 24, 'is_schedulable' => true, 'is_work_time' => true, 'is_active' => true,
    ]);
    $this->trainingType = EventType::create([
        'name' => 'Навчання', 'code' => 'training', 'color_code' => '#000000',
        'min_rest_hours' => 0, 'preferred_rest_hours' => 0, 'is_work_time' => false,
    ]);

    $this->user = User::create(['name' => 'Тест', 'email' => 'limit@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->user->allowedEventTypes()->attach([$this->dutyType->id, $this->trainingType->id]);

    $this->group = EmployeeGroup::create(['name' => 'Чергові']);
    $this->group->users()->attach($this->user->id);

    $this->week = Period::where('type', PeriodTypes::CALENDAR_WEEK)->sole();
    $this->month = Period::where('type', PeriodTypes::CALENDAR_MONTH)->sole();
});

function makeLimit(Period $period, int $maxDays, string $severity = DutyLimit::SEVERITY_FORBIDDEN, array $groups = [], array $eventTypes = []): DutyLimit
{
    $limit = DutyLimit::create(['name' => "Ліміт {$maxDays}", 'period_id' => $period->id, 'max_days' => $maxDays, 'severity' => $severity]);
    $limit->groups()->attach($groups);
    $limit->eventTypes()->attach($eventTypes);

    return $limit;
}

function dutyOn(User $user, EventType $type, string $day, int $days = 1, string $status = 'approved'): UserEvent
{
    $start = Carbon::parse($day)->startOfDay();

    return UserEvent::create([
        'user_id' => $user->id, 'event_type_id' => $type->id,
        'starts_at' => $start, 'ends_at' => $start->copy()->addDays($days), 'status' => $status,
    ]);
}

function validateDay(object $test, string $day, ?EventType $type = null, ?int $ignore = null): ConstraintCheckResult
{
    $start = Carbon::parse($day)->startOfDay();

    return app(ConstraintsEngine::class)->validate($test->user, $type ?? $test->dutyType, $start, $start->copy()->addDay(), $ignore);
}

it('forbids exceeding a hard weekly limit', function () {
    makeLimit($this->week, 2, groups: [$this->group->id]);
    dutyOn($this->user, $this->dutyType, '2026-11-02');
    dutyOn($this->user, $this->dutyType, '2026-11-04');

    $result = validateDay($this, '2026-11-06');

    expect($result->isAllowed)->toBeFalse()
        ->and($result->reason)->toContain('Ліміт 2')
        ->and($result->reason)->toContain('02.11.2026–08.11.2026')
        // The next calendar week has its own quota.
        ->and(validateDay($this, '2026-11-09')->isAllowed)->toBeTrue();
});

it('allows exceeding a soft limit with a warning', function () {
    makeLimit($this->week, 1, DutyLimit::SEVERITY_WARNING, [$this->group->id]);
    dutyOn($this->user, $this->dutyType, '2026-11-02');

    $result = validateDay($this, '2026-11-04');

    expect($result->isAllowed)->toBeTrue()
        ->and($result->status)->toBe(ConstraintCheckResult::STATUS_WARNING)
        ->and($result->reason)->toContain('Ліміт 1');
});

it('counts every day of a multi-day duty and splits it between periods', function () {
    makeLimit($this->week, 2, groups: [$this->group->id]);
    // Sat 07.11 and Sun 08.11 fall into the first week, Mon 09.11 into the next one.
    dutyOn($this->user, $this->dutyType, '2026-11-07', 3);

    expect(validateDay($this, '2026-11-02')->isAllowed)->toBeFalse()
        ->and(validateDay($this, '2026-11-11')->isAllowed)->toBeTrue();
});

it('applies a limit without groups to every employee', function () {
    makeLimit($this->week, 1);
    dutyOn($this->user, $this->dutyType, '2026-11-02');

    expect(validateDay($this, '2026-11-04')->isAllowed)->toBeFalse();
});

it('only applies to members of the selected groups', function () {
    $otherGroup = EmployeeGroup::create(['name' => 'Інші']);
    makeLimit($this->week, 0, groups: [$otherGroup->id]);

    expect(validateDay($this, '2026-11-02')->isAllowed)->toBeTrue();
});

it('ignores inactive limits', function () {
    makeLimit($this->week, 0, groups: [$this->group->id])->update(['is_active' => false]);

    expect(validateDay($this, '2026-11-02')->isAllowed)->toBeTrue();
});

it('counts only working event types when no event types are selected', function () {
    makeLimit($this->week, 1, groups: [$this->group->id]);
    dutyOn($this->user, $this->trainingType, '2026-11-02');

    expect(validateDay($this, '2026-11-03')->isAllowed)->toBeTrue()
        ->and(validateDay($this, '2026-11-03', $this->trainingType)->isAllowed)->toBeTrue();
});

it('counts only the selected event types', function () {
    makeLimit($this->week, 1, groups: [$this->group->id], eventTypes: [$this->trainingType->id]);
    dutyOn($this->user, $this->trainingType, '2026-11-02');

    expect(validateDay($this, '2026-11-03')->isAllowed)->toBeTrue()
        ->and(validateDay($this, '2026-11-04', $this->trainingType)->isAllowed)->toBeFalse();
});

it('ignores cancelled events and the event being edited', function () {
    makeLimit($this->week, 1, groups: [$this->group->id]);
    dutyOn($this->user, $this->dutyType, '2026-11-02', status: 'cancelled');
    $edited = dutyOn($this->user, $this->dutyType, '2026-11-03');

    expect(validateDay($this, '2026-11-05', ignore: $edited->id)->isAllowed)->toBeTrue()
        ->and(validateDay($this, '2026-11-05')->isAllowed)->toBeFalse();
});

it('applies all limits so the strictest one wins', function () {
    makeLimit($this->week, 5, groups: [$this->group->id]);
    makeLimit($this->month, 2, groups: [$this->group->id]);
    dutyOn($this->user, $this->dutyType, '2026-11-02');
    dutyOn($this->user, $this->dutyType, '2026-11-10');

    $result = validateDay($this, '2026-11-20');

    expect($result->isAllowed)->toBeFalse()
        ->and($result->reason)->toContain('01.11.2026–30.11.2026');
});

it('reports existing events that exceed a limit, including ones created before it', function () {
    $first = dutyOn($this->user, $this->dutyType, '2026-11-02');
    $second = dutyOn($this->user, $this->dutyType, '2026-11-04');
    $nextWeek = dutyOn($this->user, $this->dutyType, '2026-11-10');
    makeLimit($this->week, 1, groups: [$this->group->id]);

    $violations = app(DutyLimitChecker::class)->violations(UserEvent::with('eventType')->get());

    expect(array_keys($violations))->toEqualCanonicalizing([$first->id, $second->id])
        ->and($violations[$first->id][0])->toContain('2 з 1');
});

it('stops auto-generation from exceeding a hard limit', function () {
    Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);
    $this->user->assignRole('User');
    makeLimit($this->week, 2, groups: [$this->group->id]);

    $result = app(AutoScheduler::class)->generate(
        Carbon::parse('2026-11-02'),
        Carbon::parse('2026-11-08')->endOfDay(),
        [$this->dutyType->id],
    );

    expect($result->createdCount())->toBe(2)
        ->and($result->deficitCount())->toBe(5);
});

it('applies a rolling period to any consecutive days, unlike fixed recurring weeks', function () {
    // Weeks from Wednesday 01.04: 22–28.04 and 29.04–05.05 are separate windows.
    $recurring = Period::create(['name' => 'Тиждень від 01.04', 'type' => PeriodTypes::RECURRING,
        'params' => ['anchor_date' => '2026-04-01', 'length_days' => '7', 'repeat_every_days' => '7']]);
    $rolling = Period::create(['name' => 'Будь-які 7 днів', 'type' => PeriodTypes::ROLLING, 'params' => ['length_days' => '7']]);
    dutyOn($this->user, $this->dutyType, '2026-04-24');
    dutyOn($this->user, $this->dutyType, '2026-04-26');

    $recurringLimit = makeLimit($recurring, 2);
    expect(validateDay($this, '2026-04-29')->isAllowed)->toBeTrue();

    $recurringLimit->delete();
    makeLimit($rolling, 2);
    // A new request (or queue job) gets a fresh engine that loads the current limits.
    app()->forgetScopedInstances();
    $result = validateDay($this, '2026-04-29');

    expect($result->isAllowed)->toBeFalse()
        ->and($result->reason)->toContain('3 з 2')
        // A duty a week later fits again: 26.04 has left the 7-day window.
        ->and(validateDay($this, '2026-05-03')->isAllowed)->toBeTrue();
});

it('reports a rolling limit once per event although several windows are exceeded', function () {
    $rolling = Period::create(['name' => 'Будь-які 7 днів', 'type' => PeriodTypes::ROLLING, 'params' => ['length_days' => 7]]);
    $events = collect(['2026-04-24', '2026-04-26', '2026-04-28'])->map(fn (string $day) => dutyOn($this->user, $this->dutyType, $day));
    makeLimit($rolling, 2);

    $violations = app(DutyLimitChecker::class)->violations(UserEvent::with('eventType')->get());

    expect(array_keys($violations))->toEqualCanonicalizing($events->pluck('id')->all())
        ->and(collect($violations)->every(fn (array $reasons): bool => count($reasons) === 1))->toBeTrue();
});
