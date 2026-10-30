<?php

use App\Exports\ScheduleExport;
use App\Filament\Pages\DutyMatrix;
use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\AutoScheduler;
use App\Services\ConstraintsEngine;
use App\Services\ScheduleDraftService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['scheduling.time_unit' => 'days']);
    foreach (['Manager', 'User'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->manager = User::create(['name' => 'Менеджер', 'email' => 'manager@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->manager->assignRole('Manager');

    $this->duty = EventType::create([
        'name' => 'Чергування', 'code' => 'duty', 'color_code' => '#3B82F6',
        'min_capacity' => 1, 'max_capacity' => 1, 'min_rest_hours' => 0, 'preferred_rest_hours' => 0,
        'default_duration_hours' => 24, 'is_schedulable' => true, 'is_work_time' => true, 'is_active' => true,
    ]);

    $this->makeEmployee = function (string $name, bool $active) {
        $user = User::create(['name' => $name, 'email' => Str::slug($name).'@test.local', 'password' => bcrypt('secret'), 'is_active' => $active]);
        $user->assignRole('User');
        $user->allowedEventTypes()->attach($this->duty->id);

        return $user;
    };

    $this->active = ($this->makeEmployee)('Активна Анна', true);
    $this->leftWithDuty = ($this->makeEmployee)('Звільнений Борис', false);
    $this->leftWithoutDuty = ($this->makeEmployee)('Звільнена Віра', false);

    UserEvent::create([
        'user_id' => $this->leftWithDuty->id, 'event_type_id' => $this->duty->id,
        'starts_at' => '2026-09-03 00:00:00', 'ends_at' => '2026-09-04 00:00:00',
    ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('forbids assigning events to an inactive employee', function () {
    $result = app(ConstraintsEngine::class)->validate(
        $this->leftWithoutDuty, $this->duty, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11')
    );

    expect($result->isAllowed)->toBeFalse()
        ->and($result->reason)->toContain('неактивний');
});

it('shows inactive employees in the matrix only while they have events in the period', function () {
    $this->actingAs($this->manager);

    Livewire::test(DutyMatrix::class)
        ->set('month', '2026-09')
        ->assertSee('Активна Анна')
        ->assertSee('Звільнений Борис')
        ->assertSee('Неактивний')
        ->assertDontSee('Звільнена Віра');

    Livewire::test(DutyMatrix::class)
        ->set('month', '2026-10')
        ->assertSee('Активна Анна')
        ->assertDontSee('Звільнений Борис');
});

it('updates the cached matrix when an employee is deactivated', function () {
    $this->actingAs($this->manager);
    Livewire::test(DutyMatrix::class)->set('month', '2026-10')->assertSee('Активна Анна');

    $this->active->update(['is_active' => false]);

    Livewire::test(DutyMatrix::class)->set('month', '2026-10')->assertDontSee('Активна Анна');
});

it('rejects a manual assignment for an inactive employee in the matrix', function () {
    $this->actingAs($this->manager);

    Livewire::test(DutyMatrix::class)
        ->set('month', '2026-09')
        ->mountAction('assign', ['userId' => $this->leftWithDuty->id, 'date' => '2026-09-10'])
        ->setActionData(['event_type_id' => $this->duty->id, 'starts_at' => '2026-09-10', 'ends_at' => '2026-09-10'])
        ->callMountedAction();

    expect(UserEvent::where('user_id', $this->leftWithDuty->id)->count())->toBe(1);
});

it('does not auto-generate duties for inactive employees', function () {
    app(AutoScheduler::class)->generate(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-05')->endOfDay(), [$this->duty->id]);

    expect(UserEvent::whereIn('user_id', [$this->leftWithDuty->id, $this->leftWithoutDuty->id])->count())->toBe(1)
        ->and(UserEvent::where('user_id', $this->active->id)->count())->toBe(5);
});

it('does not approve draft duties of an employee who became inactive', function () {
    $service = app(ScheduleDraftService::class);
    $draft = $service->create(Carbon::parse('2026-10-01'), null);
    UserEvent::create([
        'schedule_draft_id' => $draft->id, 'user_id' => $this->active->id, 'event_type_id' => $this->duty->id,
        'starts_at' => '2026-10-02 00:00:00', 'ends_at' => '2026-10-03 00:00:00',
    ]);
    $this->active->update(['is_active' => false]);

    expect(fn () => $service->approve($draft, [], null))->toThrow(ValidationException::class);
    expect(UserEvent::inScenario(null)->where('user_id', $this->active->id)->count())->toBe(0);
});

it('exports inactive employees with events and marks them', function () {
    $names = collect((new ScheduleExport('2026-09'))->array())->pluck(0);

    expect($names)->toContain('Активна Анна', 'Звільнений Борис (неактивний)')
        ->and($names)->not->toContain('Звільнена Віра', 'Звільнена Віра (неактивний)');
});
