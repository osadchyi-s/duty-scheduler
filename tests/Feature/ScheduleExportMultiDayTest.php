<?php

use App\Exports\ScheduleExport;
use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('shows multi-day events on every day they cover in the exported month', function () {
    Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);

    $user = User::create([
        'name' => 'Тестовий працівник',
        'email' => 'multi-day-export@example.com',
        'password' => bcrypt('password'),
        'position' => 'Співробітник',
        'is_active' => true,
    ]);
    $user->syncRoles(['User']);

    $eventType = EventType::create([
        'name' => 'Добове чергування (24г)',
        'code' => 'duty_24h',
        'color_code' => '#3B82F6',
        'is_blocking' => false,
        'min_capacity' => 1,
        'max_capacity' => 2,
        'min_rest_hours' => 24,
        'preferred_rest_hours' => 48,
        'default_start_time' => '08:00:00',
        'default_duration_hours' => 24,
        'is_schedulable' => true,
        'is_work_time' => true,
        'sort_order' => 1,
    ]);

    UserEvent::create([
        'user_id' => $user->id,
        'event_type_id' => $eventType->id,
        'starts_at' => '2025-02-10 08:00:00',
        'ends_at' => '2025-02-12 08:00:00',
        'status' => 'approved',
    ]);

    $rows = (new ScheduleExport('2025-02'))->array();

    $row = collect($rows)->first(fn ($r) => $r[0] === 'Тестовий працівник');
    expect($row)->not->toBeNull();
    expect($row[10])->toBe('duty_24h');
    expect($row[11])->toBe('duty_24h');
    expect($row[12])->toBe('duty_24h');
});

it('does not show a day-aligned event on the day it ends at midnight and counts it once', function () {
    config(['scheduling.time_unit' => 'days']);
    Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);

    $user = User::create([
        'name' => 'Працівник днів',
        'email' => 'day-aligned-export@example.com',
        'password' => bcrypt('password'),
        'is_active' => true,
    ]);
    $user->syncRoles(['User']);

    $eventType = EventType::create([
        'name' => 'Добове чергування',
        'code' => 'duty',
        'color_code' => '#3B82F6',
        'default_duration_hours' => 24,
        'is_work_time' => true,
    ]);

    UserEvent::create([
        'user_id' => $user->id,
        'event_type_id' => $eventType->id,
        'starts_at' => '2025-02-10 00:00:00',
        'ends_at' => '2025-02-12 00:00:00',
        'status' => 'approved',
    ]);

    $rows = (new ScheduleExport('2025-02'))->array();
    $row = collect($rows)->first(fn ($r) => $r[0] === 'Працівник днів');

    expect($rows[0])->toHaveCount(31) // name + 28 days + total + shifts
        ->and($row[10])->toBe('duty')
        ->and($row[11])->toBe('duty')
        ->and($row[12])->toBe('')
        ->and($row[29])->toEqual(2) // total days
        ->and($row[30])->toBe(1); // shifts
});
