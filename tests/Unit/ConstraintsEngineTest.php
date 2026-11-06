<?php

use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\ConstraintCheckResult;
use App\Services\ConstraintsEngine;
use Carbon\Carbon;

beforeEach(function () {
    $this->engine = app(ConstraintsEngine::class);

    $this->dutyType = EventType::create([
        'name' => 'Добове чергування', 'code' => 'duty_24h', 'color_code' => '#3B82F6',
        'is_blocking' => false, 'min_capacity' => 1, 'max_capacity' => 2,
        'min_rest_hours' => 24, 'preferred_rest_hours' => 48, 'is_work_time' => true,
    ]);

    $this->vacationType = EventType::create([
        'name' => 'Відпустка', 'code' => 'vacation', 'color_code' => '#F59E0B',
        'is_blocking' => true, 'min_capacity' => 0, 'min_rest_hours' => 0,
        'preferred_rest_hours' => 0, 'is_work_time' => false,
    ]);

    $this->user = User::create([
        'name' => 'Тест Тестович', 'email' => 'test@example.com', 'password' => bcrypt('secret'),
    ]);
    $this->user->allowedEventTypes()->attach([$this->dutyType->id, $this->vacationType->id]);
});

it('forbids assigning an event type without permission', function () {
    $otherType = EventType::create([
        'name' => 'Відрядження', 'code' => 'trip', 'color_code' => '#8B5CF6',
        'is_blocking' => true, 'min_rest_hours' => 0, 'preferred_rest_hours' => 0, 'is_work_time' => true,
    ]);

    $result = $this->engine->validate($this->user, $otherType, Carbon::parse('2026-09-01 08:00'), Carbon::parse('2026-09-02 08:00'));

    expect($result->isAllowed)->toBeFalse()
        ->and($result->status)->toBe(ConstraintCheckResult::STATUS_FORBIDDEN);
});

it('marks rest between min and preferred as warning', function () {
    UserEvent::create([
        'user_id' => $this->user->id, 'event_type_id' => $this->dutyType->id,
        'starts_at' => Carbon::parse('2026-09-01 08:00'), 'ends_at' => Carbon::parse('2026-09-02 08:00'),
        'status' => 'approved',
    ]);

    // Next duty after 30 h (between min=24 and preferred=48) -> Warning
    $result = $this->engine->validate(
        $this->user, $this->dutyType,
        Carbon::parse('2026-09-03 14:00'), Carbon::parse('2026-09-04 14:00')
    );

    expect($result->isAllowed)->toBeTrue()
        ->and($result->status)->toBe(ConstraintCheckResult::STATUS_WARNING);
});

it('forbids rest below the hard minimum', function () {
    UserEvent::create([
        'user_id' => $this->user->id, 'event_type_id' => $this->dutyType->id,
        'starts_at' => Carbon::parse('2026-09-01 08:00'), 'ends_at' => Carbon::parse('2026-09-02 08:00'),
        'status' => 'approved',
    ]);

    // Next duty after only 10 h (< min 24) -> Forbidden
    $result = $this->engine->validate(
        $this->user, $this->dutyType,
        Carbon::parse('2026-09-02 18:00'), Carbon::parse('2026-09-03 18:00')
    );

    expect($result->isAllowed)->toBeFalse()
        ->and($result->status)->toBe(ConstraintCheckResult::STATUS_FORBIDDEN);
});

it('blocks any assignment overlapping a blocking event like vacation', function () {
    UserEvent::create([
        'user_id' => $this->user->id, 'event_type_id' => $this->vacationType->id,
        'starts_at' => Carbon::parse('2026-09-01 00:00'), 'ends_at' => Carbon::parse('2026-09-10 00:00'),
        'status' => 'approved',
    ]);

    $result = $this->engine->validate(
        $this->user, $this->dutyType,
        Carbon::parse('2026-09-05 08:00'), Carbon::parse('2026-09-06 08:00')
    );

    expect($result->isAllowed)->toBeFalse();
});

it('forbids overlapping work events even when neither event type is blocking', function () {
    $dayDuty = EventType::create([
        'name' => 'Денне чергування', 'code' => 'day_duty', 'color_code' => '#22C55E',
        'is_blocking' => false, 'min_capacity' => 1, 'max_capacity' => 1,
        'min_rest_hours' => 0, 'preferred_rest_hours' => 0, 'is_work_time' => true,
    ]);
    $this->user->allowedEventTypes()->attach($dayDuty->id);

    UserEvent::create([
        'user_id' => $this->user->id,
        'event_type_id' => $this->dutyType->id,
        'starts_at' => Carbon::parse('2026-12-08 08:00'),
        'ends_at' => Carbon::parse('2026-12-09 08:00'),
        'status' => 'approved',
    ]);

    $result = $this->engine->validate(
        $this->user,
        $dayDuty,
        Carbon::parse('2026-12-08 09:00'),
        Carbon::parse('2026-12-08 17:00'),
    );

    expect($result->isAllowed)->toBeFalse()
        ->and($result->reason)->toContain('перекривається з робочою подією');
});

it('applies the minimum rest of the previous event type to the next assignment', function () {
    $dayShift = EventType::create([
        'name' => 'Денна зміна', 'code' => 'day_shift', 'color_code' => '#10B981',
        'min_rest_hours' => 0, 'preferred_rest_hours' => 24, 'is_work_time' => true,
    ]);
    $this->user->allowedEventTypes()->attach($dayShift->id);

    UserEvent::create([
        'user_id' => $this->user->id, 'event_type_id' => $this->dutyType->id,
        'starts_at' => Carbon::parse('2026-03-06 00:00'), 'ends_at' => Carbon::parse('2026-03-07 00:00'),
        'status' => 'approved',
    ]);

    // The day shift itself needs no rest, but the duty before it requires 24 hours.
    $result = $this->engine->validate(
        $this->user, $dayShift,
        Carbon::parse('2026-03-07 00:00'), Carbon::parse('2026-03-08 00:00')
    );

    expect($result->isAllowed)->toBeFalse()
        ->and($result->reason)->toContain('Добове чергування');
});

it('applies the minimum rest of a new event type before an existing next event', function () {
    $dayShift = EventType::create([
        'name' => 'Денна зміна', 'code' => 'day_shift', 'color_code' => '#10B981',
        'min_rest_hours' => 0, 'preferred_rest_hours' => 0, 'is_work_time' => true,
    ]);
    $this->user->allowedEventTypes()->attach($dayShift->id);

    UserEvent::create([
        'user_id' => $this->user->id, 'event_type_id' => $dayShift->id,
        'starts_at' => Carbon::parse('2026-03-07 00:00'), 'ends_at' => Carbon::parse('2026-03-08 00:00'),
        'status' => 'approved',
    ]);

    $result = $this->engine->validate(
        $this->user, $this->dutyType,
        Carbon::parse('2026-03-06 00:00'), Carbon::parse('2026-03-07 00:00')
    );

    expect($result->isAllowed)->toBeFalse();
});

it('does not require the rest of an event type before that event', function () {
    $dayShift = EventType::create([
        'name' => 'Денна зміна', 'code' => 'day_shift', 'color_code' => '#10B981',
        'min_rest_hours' => 0, 'preferred_rest_hours' => 0, 'is_work_time' => true,
    ]);
    $this->user->allowedEventTypes()->attach($dayShift->id);

    $dayShiftEvent = UserEvent::create([
        'user_id' => $this->user->id, 'event_type_id' => $dayShift->id,
        'starts_at' => Carbon::parse('2026-03-06 00:00'), 'ends_at' => Carbon::parse('2026-03-07 00:00'),
        'status' => 'approved',
    ]);

    // A duty right after a shift without rest rules is fine: the duty's rest applies only after it.
    $dutyAfterShift = $this->engine->validate(
        $this->user, $this->dutyType,
        Carbon::parse('2026-03-07 00:00'), Carbon::parse('2026-03-08 00:00')
    );

    $dayShiftEvent->delete();
    UserEvent::create([
        'user_id' => $this->user->id, 'event_type_id' => $this->dutyType->id,
        'starts_at' => Carbon::parse('2026-03-07 00:00'), 'ends_at' => Carbon::parse('2026-03-08 00:00'),
        'status' => 'approved',
    ]);

    $shiftBeforeDuty = $this->engine->validate(
        $this->user, $dayShift,
        Carbon::parse('2026-03-06 00:00'), Carbon::parse('2026-03-07 00:00')
    );

    expect($dutyAfterShift->status)->toBe(ConstraintCheckResult::STATUS_OPTIMAL)
        ->and($shiftBeforeDuty->status)->toBe(ConstraintCheckResult::STATUS_OPTIMAL);
});
