<?php

use App\Filament\Pages\DutyMatrix;
use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['scheduling.time_unit' => 'days']);
    Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);

    $this->admin = User::create(['name' => 'Адмін', 'email' => 'admin@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->admin->assignRole('Admin');

    $this->employee = User::create(['name' => 'Працівник', 'email' => 'employee@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->employee->assignRole('User');

    $this->dutyType = EventType::create([
        'name' => 'Добове чергування', 'code' => 'duty', 'color_code' => '#3B82F6',
        'min_rest_hours' => 24, 'preferred_rest_hours' => 48,
        'default_duration_hours' => 24, 'is_work_time' => true, 'is_active' => true,
    ]);
    $this->employee->allowedEventTypes()->attach($this->dutyType->id);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
});

it('prefills dates and stores an inclusive end date as the next midnight', function () {
    Livewire::test(DutyMatrix::class)
        ->mountAction('assign', ['userId' => $this->employee->id, 'date' => '2026-09-01'])
        ->assertActionDataSet([
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-09-01',
        ])
        ->setActionData([
            'event_type_id' => $this->dutyType->id,
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-09-02',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $event = UserEvent::sole();

    expect($event->starts_at->format('Y-m-d H:i'))->toBe('2026-09-01 00:00')
        ->and($event->ends_at->format('Y-m-d H:i'))->toBe('2026-09-03 00:00');
});

it('shows the last covered day when editing a day-aligned event', function () {
    $event = UserEvent::create([
        'user_id' => $this->employee->id, 'event_type_id' => $this->dutyType->id,
        'starts_at' => '2026-09-05 00:00:00', 'ends_at' => '2026-09-08 00:00:00', 'status' => 'draft',
    ]);

    Livewire::test(DutyMatrix::class)
        ->mountAction('assign', ['userId' => $this->employee->id, 'date' => '2026-09-05', 'eventId' => $event->id])
        ->assertActionDataSet([
            'starts_at' => '2026-09-05',
            'ends_at' => '2026-09-07',
        ]);
});
