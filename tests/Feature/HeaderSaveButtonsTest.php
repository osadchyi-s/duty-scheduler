<?php

use App\Filament\Pages\AppSettings;
use App\Filament\Resources\DutyLimitResource;
use App\Filament\Resources\EmployeeGroupResource;
use App\Filament\Resources\EventTypeResource;
use App\Filament\Resources\PeriodResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\UserResource;
use App\Models\AppSetting;
use App\Models\DutyLimit;
use App\Models\EmployeeGroup;
use App\Models\EventType;
use App\Models\Period;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    $this->admin = User::create(['name' => 'Адмін', 'email' => 'admin@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->admin->assignRole('Admin');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
});

it('shows a save button at the top of every edit page', function () {
    $fixed = Period::create(['name' => 'Свята', 'type' => 'fixed', 'params' => ['start_date' => '2026-12-20', 'end_date' => '2027-01-10']]);
    $records = [
        [EventTypeResource::class, EventType::create(['name' => 'Чергування', 'code' => 'duty', 'color_code' => '#000000'])],
        [UserResource::class, $this->admin],
        [RoleResource::class, $this->adminRole],
        [EmployeeGroupResource::class, EmployeeGroup::create(['name' => 'Чергові'])],
        [PeriodResource::class, $fixed],
        [DutyLimitResource::class, DutyLimit::create(['name' => 'Ліміт', 'period_id' => $fixed->id, 'max_days' => 2])],
    ];

    foreach ($records as [$resource, $record]) {
        // The bottom button sits inside the form; the top one submits it via the form attribute.
        $this->get($resource::getUrl('edit', ['record' => $record]))
            ->assertOk()
            ->assertSee('form="form"', false);
    }
});

it('saves the application settings from the top button', function () {
    AppSetting::query()->updateOrCreate(['id' => 1], ['locale' => 'uk']);

    Livewire::test(AppSettings::class)
        ->fillForm(['locale' => 'en'])
        ->callAction('save');

    expect(AppSetting::currentLocale())->toBe('en');
});
