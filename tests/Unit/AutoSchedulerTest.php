<?php

use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\AutoScheduler;
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
    $to = Carbon::parse('2026-09-05');

    $result = $this->scheduler->generate($from, $to, [$this->dutyType->id]);

    expect($result->createdCount())->toBe(5)
        ->and($result->deficitCount())->toBe(0);

    expect(UserEvent::where('event_type_id', $this->dutyType->id)->count())->toBe(5);
});

it('distributes shifts fairly across eligible users', function () {
    $from = Carbon::parse('2026-09-01');
    $to = Carbon::parse('2026-09-06'); // 6 днів, 3 людини -> по 2 зміни кожному (з урахуванням min_rest=24)

    $this->scheduler->generate($from, $to, [$this->dutyType->id]);

    $counts = UserEvent::where('event_type_id', $this->dutyType->id)
        ->get()
        ->groupBy('user_id')
        ->map->count();

    // Жоден співробітник не повинен отримати істотно більше змін за інших.
    expect($counts->max() - $counts->min())->toBeLessThanOrEqual(1);
});

it('records a deficit when no eligible user passes the constraints', function () {
    // Лише один співробітник з допуском, чергування 24г/48г бажаного відпочинку -
    // на другий день поспіль buде forbidden (rest < 24h).
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
    $to = Carbon::parse('2026-09-02'); // два дні поспіль -> другий день неможливо заповнити

    $result = $this->scheduler->generate($from, $to, [$lonelyType->id]);

    expect($result->createdCount())->toBe(1)
        ->and($result->deficitCount())->toBe(1);
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
