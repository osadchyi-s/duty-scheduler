<?php

use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;

it('aligns event types and events to whole days', function () {
    $type = EventType::create([
        'name' => 'Денна зміна', 'code' => 'day_shift', 'color_code' => '#10B981',
        'min_rest_hours' => 18, 'preferred_rest_hours' => 36,
        'default_start_time' => '08:00:00', 'default_duration_hours' => 12,
    ]);
    $vacation = EventType::create([
        'name' => 'Відпустка', 'code' => 'vacation', 'color_code' => '#F59E0B',
        'min_rest_hours' => 48, 'preferred_rest_hours' => 0,
    ]);
    $user = User::create(['name' => 'Тест', 'email' => 'align@test.local', 'password' => bcrypt('secret')]);

    $shift = UserEvent::create([
        'user_id' => $user->id, 'event_type_id' => $type->id,
        'starts_at' => '2026-09-01 08:00:00', 'ends_at' => '2026-09-02 07:00:00',
    ]);
    $alreadyAligned = UserEvent::create([
        'user_id' => $user->id, 'event_type_id' => $vacation->id,
        'starts_at' => '2026-09-10 00:00:00', 'ends_at' => '2026-09-12 00:00:00',
    ]);

    (require database_path('migrations/2026_10_29_000000_align_scheduling_data_to_days.php'))->up();

    $type->refresh();
    $vacation->refresh();
    $shift->refresh();
    $alreadyAligned->refresh();

    expect($type->min_rest_hours)->toBe(0)
        ->and($type->preferred_rest_hours)->toBe(48)
        ->and($type->default_duration_hours)->toBe(24)
        ->and($type->default_start_time)->toBe('00:00:00')
        ->and($vacation->min_rest_hours)->toBe(48)
        ->and($vacation->default_duration_hours)->toBeNull()
        ->and($vacation->default_start_time)->toBeNull()
        ->and($shift->starts_at->format('Y-m-d H:i'))->toBe('2026-09-01 00:00')
        ->and($shift->ends_at->format('Y-m-d H:i'))->toBe('2026-09-03 00:00')
        ->and($alreadyAligned->starts_at->format('Y-m-d H:i'))->toBe('2026-09-10 00:00')
        ->and($alreadyAligned->ends_at->format('Y-m-d H:i'))->toBe('2026-09-12 00:00');
});
