<?php

use App\Filament\Resources\EventTypeResource\Pages\EditEventType;
use App\Models\EventType;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('edits rest and duration in days while storing hours', function () {
    config(['scheduling.time_unit' => 'days']);
    Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    $admin = User::create(['name' => 'Адмін', 'email' => 'admin@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $admin->assignRole('Admin');

    $type = EventType::create([
        'name' => 'Добове чергування', 'code' => 'duty', 'color_code' => '#3B82F6',
        'min_rest_hours' => 24, 'preferred_rest_hours' => 48, 'default_duration_hours' => 24,
    ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($admin);

    Livewire::test(EditEventType::class, ['record' => $type->getRouteKey()])
        ->assertFormSet([
            'min_rest_hours' => 1,
            'preferred_rest_hours' => 2,
            'default_duration_hours' => 1,
        ])
        ->assertFormFieldIsHidden('default_start_time')
        ->fillForm([
            'min_rest_hours' => 2,
            'preferred_rest_hours' => 3,
            'default_duration_hours' => 2,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $type->refresh();

    expect($type->min_rest_hours)->toBe(48)
        ->and($type->preferred_rest_hours)->toBe(72)
        ->and($type->default_duration_hours)->toBe(48);
});
