<?php

use App\Models\EventType;
use App\Models\Synergy;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\AutoScheduler;
use App\Services\ScheduleDraftService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);

    $this->scheduler = app(AutoScheduler::class);

    $this->dutyType = EventType::create([
        'name' => 'Добове чергування', 'code' => 'duty_24h', 'color_code' => '#3B82F6',
        'is_blocking' => false, 'min_capacity' => 1, 'max_capacity' => 1,
        'min_rest_hours' => 24, 'preferred_rest_hours' => 48,
        'default_start_time' => '08:00:00', 'default_duration_hours' => 24,
        'is_schedulable' => true, 'is_work_time' => true, 'is_active' => true,
    ]);

    $this->users = collect();
    for ($i = 1; $i <= 3; $i++) {
        $user = User::create([
            'name' => "Співробітник {$i}", 'email' => "emp{$i}@test.local",
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $user->assignRole('User');
        $user->allowedEventTypes()->attach($this->dutyType->id);
        $this->users->push($user);
    }
});

it('fills min_capacity for every day in the period', function () {
    $from = Carbon::parse('2026-09-01');
    $to = Carbon::parse('2026-09-05')->endOfDay();

    $result = $this->scheduler->generate($from, $to, [$this->dutyType->id]);

    expect($result->createdCount())->toBe(5)
        ->and($result->deficitCount())->toBe(0);

    expect(UserEvent::where('event_type_id', $this->dutyType->id)->count())->toBe(5);
});

it('distributes shifts fairly across eligible users', function () {
    $from = Carbon::parse('2026-09-01');
    $to = Carbon::parse('2026-09-06'); // 6 days, 3 people -> 2 shifts each (taking min_rest=24 into account)

    $this->scheduler->generate($from, $to, [$this->dutyType->id]);

    $counts = UserEvent::where('event_type_id', $this->dutyType->id)
        ->get()
        ->groupBy('user_id')
        ->map->count();

    // No employee should get noticeably more shifts than the others.
    expect($counts->max() - $counts->min())->toBeLessThanOrEqual(1);
});

it('records a deficit when no eligible user passes the constraints', function () {
    // Only one permitted employee, 24h duty with 48h preferred rest:
    // the second day in a row is forbidden (rest < 24h).
    $lonelyType = EventType::create([
        'name' => 'Ексклюзивне чергування', 'code' => 'solo_duty', 'color_code' => '#000000',
        'is_blocking' => false, 'min_capacity' => 1, 'max_capacity' => 1,
        'min_rest_hours' => 24, 'preferred_rest_hours' => 48,
        'default_start_time' => '08:00:00', 'default_duration_hours' => 24,
        'is_schedulable' => true, 'is_work_time' => true, 'is_active' => true,
    ]);

    $solo = User::create([
        'name' => 'Одинак', 'email' => 'solo@test.local', 'password' => bcrypt('secret'), 'is_active' => true,
    ]);
    $solo->assignRole('User');
    $solo->allowedEventTypes()->attach($lonelyType->id);

    $from = Carbon::parse('2026-09-01');
    $to = Carbon::parse('2026-09-02')->endOfDay(); // two days in a row -> the second day cannot be filled

    $result = $this->scheduler->generate($from, $to, [$lonelyType->id]);

    expect($result->createdCount())->toBe(1)
        ->and($result->deficitCount())->toBe(1);
});

it('does not assign one employee overlapping shifts of different event types', function () {
    $dayType = EventType::create([
        'name' => 'Денне чергування', 'code' => 'day_duty', 'color_code' => '#22C55E',
        'is_blocking' => false, 'min_capacity' => 1, 'max_capacity' => 1,
        'min_rest_hours' => 0, 'preferred_rest_hours' => 0,
        'default_start_time' => '09:00:00', 'default_duration_hours' => 8,
        'is_schedulable' => true, 'is_work_time' => true, 'is_active' => true,
    ]);

    foreach ($this->users as $user) {
        $user->allowedEventTypes()->detach();
    }

    $onlyAvailableUser = User::create([
        'name' => 'Єдиний доступний', 'email' => 'only-available@test.local',
        'password' => bcrypt('secret'), 'is_active' => true,
    ]);
    $onlyAvailableUser->assignRole('User');
    $onlyAvailableUser->allowedEventTypes()->attach([$this->dutyType->id, $dayType->id]);

    $result = $this->scheduler->generate(
        Carbon::parse('2026-12-08'),
        Carbon::parse('2026-12-08')->endOfDay(),
        [$this->dutyType->id, $dayType->id],
    );

    $events = UserEvent::whereIn('event_type_id', [$this->dutyType->id, $dayType->id])->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->user_id)->toBe($onlyAvailableUser->id)
        ->and($result->createdCount())->toBe(1)
        ->and($result->deficitCount())->toBe(1);
});

it('generates into a draft without touching the approved schedule', function () {
    $approved = UserEvent::create([
        'user_id' => $this->users[0]->id,
        'event_type_id' => $this->dutyType->id,
        'starts_at' => Carbon::parse('2026-12-08 08:00'),
        'ends_at' => Carbon::parse('2026-12-09 08:00'),
        'status' => 'approved',
    ]);
    $draft = app(ScheduleDraftService::class)->create(Carbon::parse('2026-12-01'), null);

    $result = $this->scheduler->generate(
        Carbon::parse('2026-12-08'),
        Carbon::parse('2026-12-09')->endOfDay(),
        [$this->dutyType->id],
        existingEvents: AutoScheduler::EXISTING_DELETE_ALL,
        draft: $draft,
    );

    // The draft copy of the approved event is replaced; the approved event itself stays.
    expect(UserEvent::find($approved->id))->not->toBeNull()
        ->and(UserEvent::whereNull('schedule_draft_id')->count())->toBe(1)
        ->and($result->deletedCount)->toBe(1)
        ->and($draft->events()->count())->toBe(2);
});

it('rejects a generation period outside the draft month', function () {
    $draft = app(ScheduleDraftService::class)->create(Carbon::parse('2026-12-01'), null);

    $this->scheduler->generate(Carbon::parse('2026-11-30'), Carbon::parse('2026-12-02')->endOfDay(), [$this->dutyType->id], draft: $draft);
})->throws(InvalidArgumentException::class);

it('deletes active assignments of the selected event types when replacing all', function () {
    $approved = UserEvent::create([
        'user_id' => $this->users[0]->id,
        'event_type_id' => $this->dutyType->id,
        'starts_at' => Carbon::parse('2026-12-08 08:00'),
        'ends_at' => Carbon::parse('2026-12-09 08:00'),
        'status' => 'approved',
    ]);
    $second = UserEvent::create([
        'user_id' => $this->users[1]->id,
        'event_type_id' => $this->dutyType->id,
        'starts_at' => Carbon::parse('2026-12-08 08:00'),
        'ends_at' => Carbon::parse('2026-12-09 08:00'),
        'status' => 'approved',
    ]);
    $cancelled = UserEvent::create([
        'user_id' => $this->users[2]->id,
        'event_type_id' => $this->dutyType->id,
        'starts_at' => Carbon::parse('2026-12-08 08:00'),
        'ends_at' => Carbon::parse('2026-12-09 08:00'),
        'status' => 'cancelled',
    ]);

    $result = $this->scheduler->generate(
        Carbon::parse('2026-12-08'),
        Carbon::parse('2026-12-08')->endOfDay(),
        [$this->dutyType->id],
        existingEvents: AutoScheduler::EXISTING_DELETE_ALL,
    );

    expect(UserEvent::find($approved->id))->toBeNull()
        ->and(UserEvent::find($second->id))->toBeNull()
        ->and(UserEvent::find($cancelled->id))->not->toBeNull()
        ->and($result->deletedCount)->toBe(2);
});

it('favors employees with stronger applicable synergy to someone already assigned that day', function () {
    $this->dutyType->update([
        'min_rest_hours' => 0,
        'preferred_rest_hours' => 0,
        'is_work_time' => false,
    ]);

    $seedType = EventType::create([
        'name' => 'Ранкова зміна', 'code' => 'morning_seed', 'color_code' => '#000000',
        'is_blocking' => false, 'min_capacity' => 0, 'max_capacity' => 1,
        'min_rest_hours' => 0, 'preferred_rest_hours' => 0,
        'default_start_time' => '06:00:00', 'default_duration_hours' => 1,
        'is_schedulable' => true, 'is_work_time' => false, 'is_active' => true,
    ]);

    $preferred = $this->users[0];
    $lessCompatible = $this->users[1];
    $alreadyAssigned = $this->users[2];
    $alreadyAssigned->allowedEventTypes()->attach($seedType->id);
    $alreadyAssigned->allowedEventTypes()->detach($this->dutyType->id);

    $preferredSynergy = Synergy::create([
        'user_a_id' => $alreadyAssigned->id,
        'user_b_id' => $preferred->id,
        'score' => 10,
    ]);
    $preferredSynergy->eventTypes()->attach($this->dutyType->id);

    Synergy::create([
        'user_a_id' => $lessCompatible->id,
        'user_b_id' => $alreadyAssigned->id,
        'score' => -10,
    ])->eventTypes()->attach($this->dutyType->id);

    $from = Carbon::parse('2026-09-01');
    $to = Carbon::parse('2026-09-30');

    for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
        UserEvent::create([
            'user_id' => $alreadyAssigned->id,
            'event_type_id' => $seedType->id,
            'starts_at' => $day->copy()->setTime(6, 0),
            'ends_at' => $day->copy()->setTime(7, 0),
            'status' => 'approved',
        ]);
    }

    $this->scheduler->generate($from, $to, [$seedType->id, $this->dutyType->id]);

    $assignments = UserEvent::where('event_type_id', $this->dutyType->id)
        ->get()
        ->groupBy('user_id')
        ->map->count();

    expect($assignments->get($preferred->id, 0))->toBeGreaterThan($assignments->get($lessCompatible->id, 0));
});

it('skips event types without a configured duration', function () {
    $noDuration = EventType::create([
        'name' => 'Без тривалості', 'code' => 'no_duration', 'color_code' => '#000000',
        'is_blocking' => false, 'min_capacity' => 1, 'is_active' => true,
    ]);

    $result = $this->scheduler->generate(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-01'), [$noDuration->id]);

    expect($result->createdCount())->toBe(0)
        ->and($result->deficitCount())->toBe(0);
});
