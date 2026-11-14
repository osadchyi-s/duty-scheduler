<?php

namespace Database\Seeders;

use App\Models\DutyLimit;
use App\Models\EventType;
use App\Models\Period;
use App\Models\Synergy;
use App\Models\User;
use App\Periods\PeriodTypes;
use Illuminate\Database\Seeder;

/**
 * Example scheduling rules (the same as in the demo): periods limited to days of the week,
 * duty limits on them and one unwanted pairing. Runs after EventTypeSeeder and UserSeeder;
 * existing rules with the same names or pairs are left as they are.
 */
class SchedulingRulesSeeder extends Seeder
{
    public function run(): void
    {
        $shiftTypeIds = EventType::whereIn('code', ['duty_24h', 'duty_24h_copilot', 'day_shift_second', 'day_shift'])
            ->pluck('id')
            ->all();

        $weekends = Period::firstOrCreate(
            ['name' => 'Monthly weekends'],
            ['type' => PeriodTypes::CALENDAR_MONTH, 'params' => ['weekdays' => [6, 7]]]
        );
        $longWeekends = Period::firstOrCreate(
            ['name' => 'Long weekends'],
            ['type' => PeriodTypes::CALENDAR_WEEK, 'params' => ['weekdays' => [4, 7]]]
        );

        foreach ([
            ['Two duties per weekend', $weekends, 2],
            ['One duty per long weekend', $longWeekends, 1],
        ] as [$name, $period, $maxDays]) {
            $limit = DutyLimit::firstOrCreate(
                ['name' => $name],
                ['period_id' => $period->id, 'max_days' => $maxDays, 'severity' => DutyLimit::SEVERITY_FORBIDDEN, 'is_active' => true]
            );

            if ($limit->wasRecentlyCreated) {
                $limit->eventTypes()->sync($shiftTypeIds);
            }
        }

        // Unwanted pairing (synergy -1) for 24-hour duties and the second day shift.
        $userA = User::where('email', 'employee6@system.local')->value('id');
        $userB = User::where('email', 'worker02@system.local')->value('id');

        if ($userA && $userB && ! Synergy::where(fn ($q) => $q->where('user_a_id', min($userA, $userB))->where('user_b_id', max($userA, $userB)))->exists()) {
            $synergy = Synergy::create(['user_a_id' => $userA, 'user_b_id' => $userB, 'score' => -1]);
            $synergy->eventTypes()->sync(EventType::whereIn('code', ['duty_24h', 'day_shift_second'])->pluck('id')->all());
        }
    }
}
