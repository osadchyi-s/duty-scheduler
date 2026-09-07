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

    // Наступне чергування через 30 год (між min=24 і preferred=48) -> Warning
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

    // Наступне чергування вже через 10 год (< min 24) -> Forbidden
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
