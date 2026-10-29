<?php

use App\Filament\Resources\DutyLimitResource;
use App\Filament\Resources\DutyLimitResource\Pages\CreateDutyLimit;
use App\Filament\Resources\EmployeeGroupResource;
use App\Filament\Resources\EmployeeGroupResource\Pages\CreateEmployeeGroup;
use App\Filament\Resources\PeriodResource;
use App\Filament\Resources\PeriodResource\Pages\CreatePeriod;
use App\Filament\Resources\PeriodResource\Pages\ListPeriods;
use App\Models\DutyLimit;
use App\Models\EmployeeGroup;
use App\Models\Period;
use App\Models\User;
use App\Periods\PeriodTypes;
use Filament\Facades\Filament;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['Admin', 'Manager', 'User'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->manager = User::create(['name' => 'Менеджер', 'email' => 'manager@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->manager->assignRole('Manager');
    $this->employee = User::create(['name' => 'Працівник', 'email' => 'employee@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->employee->assignRole('User');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('opens the groups, periods and limits pages for managers', function () {
    $this->actingAs($this->manager);

    foreach ([EmployeeGroupResource::class, PeriodResource::class, DutyLimitResource::class] as $resource) {
        $this->get($resource::getUrl('index'))->assertOk();
        $this->get($resource::getUrl('create'))->assertOk();
    }
});

it('forbids the groups, periods and limits pages for regular employees', function () {
    $this->actingAs($this->employee);

    foreach ([EmployeeGroupResource::class, PeriodResource::class, DutyLimitResource::class] as $resource) {
        $this->get($resource::getUrl('index'))->assertForbidden();
    }
});

it('creates a group with employees', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateEmployeeGroup::class)
        ->fillForm(['name' => 'Чергові', 'users' => [$this->employee->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EmployeeGroup::sole()->users->pluck('id')->all())->toBe([$this->employee->id]);
});

it('creates a recurring period with its settings', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreatePeriod::class)
        ->fillForm(['name' => 'Через тиждень', 'type' => PeriodTypes::RECURRING])
        ->fillForm(['params' => ['anchor_date' => '2026-11-02', 'length_days' => 7, 'repeat_every_days' => 5]])
        ->call('create')
        ->assertHasFormErrors(['params.repeat_every_days'])
        ->fillForm(['params' => ['anchor_date' => '2026-11-02', 'length_days' => 7, 'repeat_every_days' => 14]])
        ->call('create')
        ->assertHasNoFormErrors();

    $period = Period::where('type', PeriodTypes::RECURRING)->sole();

    expect($period->params)->toMatchArray(['anchor_date' => '2026-11-02', 'length_days' => 7, 'repeat_every_days' => 14])
        ->and($period->is_system)->toBeFalse();
});

it('keeps built-in periods and periods in use read-only', function () {
    $this->actingAs($this->manager);
    $week = Period::where('type', PeriodTypes::CALENDAR_WEEK)->sole();
    $fixed = Period::create(['name' => 'Свята', 'type' => PeriodTypes::FIXED, 'params' => ['start_date' => '2026-12-20', 'end_date' => '2027-01-10']]);
    $limit = DutyLimit::create(['name' => 'Свята', 'period_id' => $fixed->id, 'max_days' => 3]);

    Livewire::test(ListPeriods::class)
        ->assertTableActionHidden(EditAction::class, $week)
        ->assertTableActionHidden(DeleteAction::class, $week)
        ->assertTableActionVisible(EditAction::class, $fixed)
        ->assertTableActionHidden(DeleteAction::class, $fixed);

    $limit->delete();

    Livewire::test(ListPeriods::class)->assertTableActionVisible(DeleteAction::class, $fixed);
});

it('creates a duty limit for groups and a period', function () {
    $this->actingAs($this->manager);
    $group = EmployeeGroup::create(['name' => 'Чергові']);
    $week = Period::where('type', PeriodTypes::CALENDAR_WEEK)->sole();

    Livewire::test(CreateDutyLimit::class)
        ->fillForm([
            'name' => 'Не більше 2 на тиждень',
            'period_id' => $week->id,
            'max_days' => 2,
            'severity' => DutyLimit::SEVERITY_WARNING,
            'groups' => [$group->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $limit = DutyLimit::sole();

    expect($limit->period_id)->toBe($week->id)
        ->and($limit->max_days)->toBe(2)
        ->and($limit->severity)->toBe(DutyLimit::SEVERITY_WARNING)
        ->and($limit->is_active)->toBeTrue()
        ->and($limit->groups->pluck('id')->all())->toBe([$group->id]);
});

it('requires at least one group for a duty limit', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateDutyLimit::class)
        ->fillForm(['name' => 'Без груп', 'period_id' => Period::first()->id, 'max_days' => 2])
        ->call('create')
        ->assertHasFormErrors(['groups' => 'required']);
});

it('renders admin pages at full content width', function () {
    $this->actingAs($this->manager);

    foreach ([EmployeeGroupResource::class, DutyLimitResource::class, \App\Filament\Resources\UserResource::class, \App\Filament\Resources\EventTypeResource::class] as $resource) {
        $this->get($resource::getUrl('index'))
            ->assertOk()
            ->assertSee('max-w-full', false)
            ->assertDontSee('max-w-7xl', false);
    }
});
