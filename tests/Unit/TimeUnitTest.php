<?php

use App\Models\UserEvent;
use App\Support\TimeUnit;
use Carbon\Carbon;

it('converts between stored hours and days', function () {
    config(['scheduling.time_unit' => 'days']);

    expect(TimeUnit::usesDays())->toBeTrue()
        ->and(TimeUnit::toDisplay(48))->toBe(2)
        ->and(TimeUnit::toDisplay(36))->toBe(1.5)
        ->and(TimeUnit::toDisplay(null))->toBeNull()
        ->and(TimeUnit::toHours('3'))->toBe(72)
        ->and(TimeUnit::toHours(''))->toBeNull()
        ->and(TimeUnit::normalizeDurationHours(12))->toBe(24)
        ->and(TimeUnit::normalizeDurationHours(25))->toBe(48)
        ->and(TimeUnit::shiftStart(Carbon::parse('2026-09-01 15:30'), '08:00:00')->format('Y-m-d H:i'))->toBe('2026-09-01 00:00');
});

it('keeps hours unchanged in hours mode', function () {
    config(['scheduling.time_unit' => 'hours']);

    expect(TimeUnit::usesDays())->toBeFalse()
        ->and(TimeUnit::toDisplay(36))->toBe(36)
        ->and(TimeUnit::toHours(36))->toBe(36)
        ->and(TimeUnit::normalizeDurationHours(12))->toBe(12)
        ->and(TimeUnit::shiftStart(Carbon::parse('2026-09-01'), '08:00:00')->format('H:i'))->toBe('08:00');
});

it('formats durations with localized plural forms', function () {
    app()->setLocale('uk');

    config(['scheduling.time_unit' => 'days']);
    expect(TimeUnit::format(24))->toBe('1 доба')
        ->and(TimeUnit::format(48))->toBe('2 доби')
        ->and(TimeUnit::format(120))->toBe('5 діб');

    config(['scheduling.time_unit' => 'hours']);
    expect(TimeUnit::format(21))->toBe('21 година');
});

it('formats a day-aligned period by its last covered day', function () {
    config(['scheduling.time_unit' => 'days']);

    expect(TimeUnit::formatPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-02')))->toBe('01.09')
        ->and(TimeUnit::formatPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-04')))->toBe('01.09–03.09');
});

it('treats an end at midnight as exclusive', function () {
    expect(UserEvent::lastCoveredDayFor(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-03'))->toDateString())->toBe('2026-09-02')
        ->and(UserEvent::lastCoveredDayFor(Carbon::parse('2026-09-01 08:00'), Carbon::parse('2026-09-02 08:00'))->toDateString())->toBe('2026-09-02');
});
