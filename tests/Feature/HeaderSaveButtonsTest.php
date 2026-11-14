<?php

use App\Filament\Pages\AppSettings;
use App\Filament\Resources\DutyLimitResource;
use App\Filament\Resources\EmployeeGroupResource;
use App\Filament\Resources\EventTypeResource;
use App\Filament\Resources\PeriodResource;
use App\Filament\Resources\PeriodResource\Pages\CreatePeriod;
use App\Filament\Resources\PeriodResource\Pages\EditPeriod;
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
            ->assertSee('form="form"', false)
            ->assertSee('Зберегти й закрити');
    }
});

it('stays on the edit page after saving and returns to the list after saving and closing', function () {
    $period = Period::create(['name' => 'Свята', 'type' => 'fixed', 'params' => ['start_date' => '2026-12-20', 'end_date' => '2027-01-10']]);

    Livewire::test(EditPeriod::class, ['record' => $period->getRouteKey()])
        ->fillForm(['name' => 'Новорічні свята'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNoRedirect();

    expect($period->refresh()->name)->toBe('Новорічні свята');

    Livewire::test(EditPeriod::class, ['record' => $period->getRouteKey()])
        ->fillForm(['name' => 'Зимові свята'])
        ->call('saveAndClose')
        ->assertHasNoFormErrors()
        ->assertRedirect(PeriodResource::getUrl('index'));

    expect($period->refresh()->name)->toBe('Зимові свята');

    // A failed save keeps the page open.
    Livewire::test(EditPeriod::class, ['record' => $period->getRouteKey()])
        ->fillForm(['name' => ''])
        ->call('saveAndClose')
        ->assertHasFormErrors(['name'])
        ->assertNoRedirect();
});

it('opens a new record for editing after creating and returns to the list after creating and closing', function () {
    Livewire::test(CreatePeriod::class)
        ->fillForm(['name' => 'Місяць', 'type' => 'calendar_month'])
        ->call('create')
        ->assertRedirect(PeriodResource::getUrl('edit', ['record' => Period::where('name', 'Місяць')->sole()]));

    Livewire::test(CreatePeriod::class)
        ->fillForm(['name' => 'Тиждень', 'type' => 'calendar_week'])
        ->call('createAndClose')
        ->assertRedirect(PeriodResource::getUrl('index'));

    expect(Period::where('name', 'Тиждень')->exists())->toBeTrue();
});

it('offers select all for the employees of an event type', function () {
    $type = EventType::create(['name' => 'Чергування', 'code' => 'duty', 'color_code' => '#000000']);

    $this->get(EventTypeResource::getUrl('edit', ['record' => $type]))
        ->assertOk()
        ->assertSee('Виділити все');
});

it('saves the application settings from the top button', function () {
    AppSetting::query()->updateOrCreate(['id' => 1], ['locale' => 'uk']);

    Livewire::test(AppSettings::class)
        ->fillForm(['locale' => 'en'])
        ->callAction('save');

    expect(AppSetting::currentLocale())->toBe('en');
});
