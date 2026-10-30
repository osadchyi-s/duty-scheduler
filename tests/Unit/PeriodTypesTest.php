<?php

use App\Periods\PeriodTypes;
use App\Periods\PeriodWindow;
use Carbon\Carbon;

function windowKeys(string $type, array $params, string $day): array
{
    return array_map(
        fn (PeriodWindow $window): string => $window->label(),
        PeriodTypes::get($type)->windowsContaining($params, Carbon::parse($day))
    );
}

it('uses Monday-based calendar weeks', function () {
    expect(windowKeys(PeriodTypes::CALENDAR_WEEK, [], '2026-11-01'))->toBe(['26.10.2026–01.11.2026'])
        ->and(windowKeys(PeriodTypes::CALENDAR_WEEK, [], '2026-11-02'))->toBe(['02.11.2026–08.11.2026']);
});

it('uses calendar months', function () {
    expect(windowKeys(PeriodTypes::CALENDAR_MONTH, [], '2026-02-15'))->toBe(['01.02.2026–28.02.2026']);
});

it('matches a fixed period only inside its inclusive range', function () {
    $params = ['start_date' => '2026-12-20', 'end_date' => '2027-01-10'];

    expect(windowKeys(PeriodTypes::FIXED, $params, '2026-12-19'))->toBe([])
        ->and(windowKeys(PeriodTypes::FIXED, $params, '2027-01-10'))->toBe(['20.12.2026–10.01.2027'])
        ->and(windowKeys(PeriodTypes::FIXED, $params, '2027-01-11'))->toBe([]);
});

it('repeats a recurring period in both directions from the anchor', function () {
    $params = ['anchor_date' => '2026-11-02', 'length_days' => 7, 'repeat_every_days' => 14];

    expect(windowKeys(PeriodTypes::RECURRING, $params, '2026-11-08'))->toBe(['02.11.2026–08.11.2026'])
        ->and(windowKeys(PeriodTypes::RECURRING, $params, '2026-11-09'))->toBe([])
        ->and(windowKeys(PeriodTypes::RECURRING, $params, '2026-11-16'))->toBe(['16.11.2026–22.11.2026'])
        ->and(windowKeys(PeriodTypes::RECURRING, $params, '2026-10-19'))->toBe(['19.10.2026–25.10.2026'])
        ->and(windowKeys(PeriodTypes::RECURRING, $params, '2026-10-26'))->toBe([])
        // Crosses the DST switch on 25.10.2026 (Europe/Kyiv).
        ->and(windowKeys(PeriodTypes::RECURRING, ['anchor_date' => '2026-10-20', 'length_days' => 1, 'repeat_every_days' => 7], '2026-10-27'))->toBe(['27.10.2026–27.10.2026']);
});

it('only offers configurable period types for new periods', function () {
    expect(array_keys(PeriodTypes::creatableOptions()))->toBe([PeriodTypes::FIXED, PeriodTypes::RECURRING, PeriodTypes::ROLLING]);
});

it('puts a day into every rolling window that contains it', function () {
    expect(windowKeys(PeriodTypes::ROLLING, ['length_days' => 3], '2026-04-29'))->toBe([
        '27.04.2026–29.04.2026',
        '28.04.2026–30.04.2026',
        '29.04.2026–01.05.2026',
    ])
        ->and(windowKeys(PeriodTypes::ROLLING, ['length_days' => 0], '2026-04-29'))->toBe([]);
});
