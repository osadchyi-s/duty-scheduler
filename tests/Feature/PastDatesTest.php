<?php

use App\Filament\Pages\AutoSchedule;
use App\Filament\Pages\DutyMatrix;
use App\Models\EventType;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['scheduling.time_unit' => 'days']);
    $this->travelTo(now()->setDate(2026, 9, 15)->setTime(10, 0));

    foreach (['Manager', 'User'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
    $this->manager = User::create(['name' => 'Менеджер', 'email' => 'manager@test.local', 'password' => bcrypt('secret')]);
    $this->manager->assignRole('Manager');
    $this->employee = User::create(['name' => 'Працівник', 'email' => 'employee@test.local', 'password' => bcrypt('secret')]);
    $this->employee->assignRole('User');

    $this->duty = EventType::create([
        'name' => 'Чергування', 'code' => 'duty', 'color_code' => '#3B82F6', 'min_capacity' => 1,
        'default_duration_hours' => 24, 'is_schedulable' => true, 'is_work_time' => true, 'is_active' => true,
    ]);
    $this->employee->allowedEventTypes()->attach($this->duty->id);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->manager);
});

it('dims the days before today in the matrix', function () {
    $html = Livewire::test(DutyMatrix::class)->set('month', '2026-09')->html();

    // Days 1–14 are past: their header and the "unassigned" cell are dimmed; today is not.
    expect(substr_count($html, 'title="Минула дата"'))->toBe(14)
        ->and(substr_count($html, 'dm-past-head'))->toBe(28 + 1) // headers + unassigned row + the CSS rule
        ->and(Livewire::test(DutyMatrix::class)->set('month', '2026-10')->html())->not->toContain('title="Минула дата"');
});

it('warns when an event is assigned on a past date', function () {
    $warning = 'Подія починається в минулому';

    Livewire::test(DutyMatrix::class)
        ->set('month', '2026-09')
        ->mountAction('assign', ['userId' => $this->employee->id, 'date' => '2026-09-20'])
        ->assertDontSee($warning)
        ->setActionData(['starts_at' => '2026-09-10'])
        ->assertSee($warning)
        ->setActionData(['event_type_id' => $this->duty->id, 'starts_at' => '2026-09-10', 'ends_at' => '2026-09-10'])
        ->callMountedAction()
        ->assertNotified('Подію призначено на минулу дату');
});

it('starts auto-generation of the current month from today', function () {
    expect(AutoSchedule::defaultFormData('2026-09')['from'])->toBe('2026-09-15')
        ->and(AutoSchedule::defaultFormData('2026-10')['from'])->toBe('2026-10-01')
        ->and(AutoSchedule::defaultFormData('2026-08')['from'])->toBe('2026-08-01');
});

it('warns when the auto-generation period starts in the past', function () {
    $warning = 'Період починається в минулому (01.09.2026)';

    Livewire::test(AutoSchedule::class)
        ->assertDontSee('Період починається в минулому')
        ->fillForm(['from' => '2026-09-01'])
        ->assertSee($warning);
});
