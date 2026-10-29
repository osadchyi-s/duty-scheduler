<?php

use App\Filament\Pages\DutyMatrix;
use App\Filament\Resources\EventTypeResource\Pages\ListEventTypes;
use App\Models\EventType;
use App\Models\ScheduleDraft;
use App\Models\User;
use App\Models\UserEvent;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Tables\Actions\DeleteAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['scheduling.time_unit' => 'days']);
    foreach (['Admin', 'Manager', 'User'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->manager = User::create(['name' => 'Менеджер', 'email' => 'manager@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->manager->assignRole('Manager');
    $this->employee = User::create(['name' => 'Працівник', 'email' => 'employee@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->employee->assignRole('User');

    $this->duty = EventType::create([
        'name' => 'Чергування', 'code' => 'duty', 'color_code' => '#3B82F6',
        'min_capacity' => 1, 'max_capacity' => 1, 'min_rest_hours' => 0, 'preferred_rest_hours' => 0,
        'default_duration_hours' => 24, 'is_schedulable' => true, 'is_work_time' => true, 'is_active' => true,
    ]);
    $this->employee->allowedEventTypes()->attach($this->duty->id);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('creates a draft tab and keeps its events out of the approved schedule', function () {
    $this->actingAs($this->manager);

    $page = Livewire::test(DutyMatrix::class)
        ->set('month', '2026-09')
        ->call('createDraft');

    $draft = ScheduleDraft::sole();
    $page->assertSet('draftId', $draft->id)
        ->assertSee('Чернетка 1')
        ->mountAction('assign', ['userId' => $this->employee->id, 'date' => '2026-09-03'])
        ->setActionData(['event_type_id' => $this->duty->id, 'starts_at' => '2026-09-03', 'ends_at' => '2026-09-03'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($draft->events()->count())->toBe(1)
        ->and(UserEvent::inScenario(null)->count())->toBe(0);
});

it('approves a draft from the matrix and returns to the approved schedule', function () {
    $this->actingAs($this->manager);
    $draft = ScheduleDraft::create(['name' => 'Варіант', 'month' => '2026-09-01', 'snapshot_at' => now()]);
    UserEvent::create([
        'schedule_draft_id' => $draft->id, 'user_id' => $this->employee->id, 'event_type_id' => $this->duty->id,
        'starts_at' => '2026-09-03 00:00:00', 'ends_at' => '2026-09-04 00:00:00',
    ]);

    Livewire::test(DutyMatrix::class)
        ->set('month', '2026-09')
        ->call('selectDraft', $draft->id)
        ->callAction('approve_draft')
        ->assertHasNoActionErrors()
        ->assertSet('draftId', null);

    expect(UserEvent::inScenario(null)->count())->toBe(1)
        ->and($draft->fresh()->approved_at)->not->toBeNull();
});

it('auto-generates a new draft from the approved schedule tab', function () {
    $this->actingAs($this->manager);

    $page = Livewire::test(DutyMatrix::class)
        ->set('month', '2026-09')
        ->callAction('auto_schedule', data: [
            'from' => '2026-09-01', 'to' => '2026-09-03',
            'event_type_ids' => [$this->duty->id], 'existing_events' => 'fill_missing', 'fill_to_max' => false,
        ]);

    $draft = ScheduleDraft::sole();
    $page->assertSet('draftId', $draft->id);

    expect($draft->events()->count())->toBe(3)
        ->and(UserEvent::inScenario(null)->count())->toBe(0);
});

it('does not show drafts to regular employees', function () {
    $draft = ScheduleDraft::create(['name' => 'Секретний варіант', 'month' => '2026-09-01', 'snapshot_at' => now()]);
    $this->actingAs($this->employee);

    Livewire::test(DutyMatrix::class)
        ->set('month', '2026-09')
        ->set('draftId', $draft->id)
        ->assertDontSee('Секретний варіант')
        ->assertDontSee('Затверджений графік')
        ->assertActionHidden('approve_draft');
});

it('switches back to the approved schedule when the month changes', function () {
    $this->actingAs($this->manager);
    $draft = ScheduleDraft::create(['name' => 'Варіант', 'month' => '2026-09-01', 'snapshot_at' => now()]);

    Livewire::test(DutyMatrix::class)
        ->set('month', '2026-09')
        ->call('selectDraft', $draft->id)
        ->set('selectedMonth', 10)
        ->assertSet('draftId', null);
});

it('does not allow deleting event types that have events', function () {
    $admin = User::create(['name' => 'Адмін', 'email' => 'admin@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $admin->assignRole('Admin');
    $this->actingAs($admin);
    $unused = EventType::create(['name' => 'Невикористаний', 'code' => 'unused', 'color_code' => '#000000']);
    UserEvent::create([
        'user_id' => $this->employee->id, 'event_type_id' => $this->duty->id,
        'starts_at' => '2026-09-03 00:00:00', 'ends_at' => '2026-09-04 00:00:00',
    ]);

    Livewire::test(ListEventTypes::class)
        ->assertTableActionHidden(DeleteAction::class, $this->duty)
        ->assertTableActionVisible(DeleteAction::class, $unused)
        ->callTableBulkAction('delete', [$this->duty, $unused]);

    expect(EventType::find($this->duty->id))->not->toBeNull()
        ->and(EventType::find($unused->id))->toBeNull();
});

it('generates into a new draft from the auto-generation page and links to it', function () {
    $this->actingAs($this->manager);
    $this->get(\App\Filament\Pages\AutoSchedule::getUrl())->assertOk();

    Livewire::test(\App\Filament\Pages\AutoSchedule::class)
        ->fillForm([
            'from' => '2026-09-01', 'to' => '2026-09-02',
            'event_type_ids' => [$this->duty->id], 'existing_events' => 'fill_missing',
        ])
        ->call('generate')
        ->assertSee('Відкрити чернетку в матриці');

    $draft = ScheduleDraft::sole();

    expect($draft->events()->count())->toBe(2)
        ->and(UserEvent::inScenario(null)->count())->toBe(0);
});

it('rejects an auto-generation period spanning two months', function () {
    $this->actingAs($this->manager);

    Livewire::test(\App\Filament\Pages\AutoSchedule::class)
        ->fillForm([
            'from' => '2026-09-30', 'to' => '2026-10-01',
            'event_type_ids' => [$this->duty->id], 'existing_events' => 'fill_missing',
        ])
        ->call('generate')
        ->assertHasErrors();

    expect(ScheduleDraft::count())->toBe(0);
});
