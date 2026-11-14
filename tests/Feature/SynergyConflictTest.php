<?php

use App\Filament\Pages\DutyMatrix;
use App\Models\EventType;
use App\Models\Synergy;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\AutoScheduler;
use App\Services\ConstraintCheckResult;
use App\Services\ConstraintsEngine;
use App\Services\ScheduleDraftService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['scheduling.time_unit' => 'days']);
    foreach (['Admin', 'User'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $type = fn (string $name, bool $work = true) => EventType::create([
        'name' => $name, 'code' => str($name)->slug()->toString(), 'color_code' => '#3B82F6',
        'min_capacity' => $work ? 1 : 0, 'max_capacity' => 1, 'min_rest_hours' => 0, 'preferred_rest_hours' => 0,
        'default_duration_hours' => 24, 'is_schedulable' => $work, 'is_work_time' => $work, 'is_active' => true,
    ]);
    $this->day1 = $type('Доба1');
    $this->day2 = $type('Доба2');
    $this->vacation = $type('Відпустка', false);

    $this->c1 = User::create(['name' => 'Перший', 'email' => 'c1@test.local', 'password' => 'secret-password']);
    $this->c2 = User::create(['name' => 'Другий', 'email' => 'c2@test.local', 'password' => 'secret-password']);
    foreach ([$this->c1, $this->c2] as $user) {
        $user->assignRole('User');
        $user->allowedEventTypes()->attach([$this->day1->id, $this->day2->id, $this->vacation->id]);
    }

    $this->pair = Synergy::create(['user_a_id' => $this->c1->id, 'user_b_id' => $this->c2->id, 'score' => -1]);
    $this->pair->eventTypes()->attach([$this->day1->id, $this->day2->id]);

    $this->engine = app(ConstraintsEngine::class);
});

function duty(User $user, EventType $type, string $day, ?int $draftId = null): UserEvent
{
    return UserEvent::create([
        'schedule_draft_id' => $draftId, 'user_id' => $user->id, 'event_type_id' => $type->id,
        'starts_at' => "$day 00:00:00", 'ends_at' => Carbon::parse($day)->addDay(),
    ]);
}

function check(object $test, User $user, EventType $type, string $day): ConstraintCheckResult
{
    app()->forgetScopedInstances();

    return app(ConstraintsEngine::class)->validate($user, $type, Carbon::parse($day), Carbon::parse($day)->addDay());
}

it('allows a manual assignment of an unwanted pair but warns about it', function () {
    duty($this->c1, $this->day1, '2026-11-10');

    $result = check($this, $this->c2, $this->day2, '2026-11-10');

    expect($result->isAllowed)->toBeTrue()
        ->and($result->status)->toBe(ConstraintCheckResult::STATUS_WARNING)
        ->and($result->synergyConflict)->toBeTrue()
        ->and($result->reason)->toContain('Небажане поєднання з Перший («Доба1»)');
});

it('does not report pairings outside the rule', function () {
    duty($this->c1, $this->day1, '2026-11-10');

    // Another day.
    expect(check($this, $this->c2, $this->day2, '2026-11-11')->synergyConflict)->toBeFalse()
        // A non-working event (vacation) of the other employee.
        ->and(check($this, $this->c2, $this->vacation, '2026-11-10')->synergyConflict)->toBeFalse();

    // The synergy lists only Доба1: a pairing with Доба2 does not count.
    $this->pair->eventTypes()->sync([$this->day1->id]);
    expect(check($this, $this->c2, $this->day2, '2026-11-10')->synergyConflict)->toBeFalse();

    // A neutral score is not an unwanted pairing.
    $this->pair->eventTypes()->sync([]);
    $this->pair->update(['score' => 0]);
    expect(check($this, $this->c2, $this->day2, '2026-11-10')->status)->toBe(ConstraintCheckResult::STATUS_OPTIMAL);
});

it('never puts an unwanted pair on the same day during auto-generation', function () {
    $c3 = User::create(['name' => 'Третій', 'email' => 'c3@test.local', 'password' => 'secret-password']);
    $c3->assignRole('User');
    $c3->allowedEventTypes()->attach([$this->day1->id, $this->day2->id]);

    for ($run = 0; $run < 5; $run++) {
        UserEvent::query()->delete();
        app()->forgetScopedInstances();
        app(AutoScheduler::class)->generate(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10')->endOfDay(), [$this->day1->id, $this->day2->id]);

        $together = UserEvent::query()->whereIn('user_id', [$this->c1->id, $this->c2->id])->get()
            ->groupBy(fn (UserEvent $event) => $event->starts_at->toDateString())
            ->filter(fn ($events) => $events->pluck('user_id')->unique()->count() > 1);

        expect($together)->toBeEmpty();
    }
});

it('leaves the slot unfilled when only the unwanted partner is available', function () {
    $result = app(AutoScheduler::class)->generate(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-01')->endOfDay(), [$this->day1->id, $this->day2->id]);

    expect($result->createdCount())->toBe(1)
        ->and($result->deficitCount())->toBe(1);
});

it('marks existing unwanted pairings in the matrix', function () {
    $admin = User::create(['name' => 'Адмін', 'email' => 'admin@test.local', 'password' => 'secret-password']);
    $admin->assignRole('Admin');
    duty($this->c1, $this->day1, '2026-11-10');
    duty($this->c2, $this->day2, '2026-11-10');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($admin);

    Livewire::test(DutyMatrix::class)
        ->set('month', '2026-11')
        ->assertSee('Небажане поєднання з Другий («Доба2»)')
        ->assertSee('Небажане поєднання з Перший («Доба1»)');
});

it('approves a draft with an unwanted pairing, keeping the warning', function () {
    $service = app(ScheduleDraftService::class);
    $draft = $service->create(Carbon::parse('2026-11-01'), null);
    duty($this->c1, $this->day1, '2026-11-10', $draft->id);
    duty($this->c2, $this->day2, '2026-11-10', $draft->id);

    $service->approve($draft, [], null);

    $events = UserEvent::query()->inScenario(null)->get();
    expect($events)->toHaveCount(2)
        ->and($events->every(fn (UserEvent $event) => $event->has_warning && str_contains($event->warning_reason, 'Небажане поєднання')))->toBeTrue();
});
