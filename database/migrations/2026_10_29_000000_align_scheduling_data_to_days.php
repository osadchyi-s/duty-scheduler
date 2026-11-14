<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns existing data to day granularity (scheduling.time_unit = days).
 * Values stay in hours, but become whole days:
 *   - rest below 24 h => 0, otherwise rounded up to whole days (36 h => 48 h);
 *   - shift duration rounded up to whole days, at least one day;
 *   - default start time and event starts moved to 00:00;
 *   - event ends moved up to the next 00:00 unless they already end at midnight.
 *
 * The data change is lossy, so down() does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('event_types')->orderBy('id')->each(function (object $type): void {
            DB::table('event_types')->where('id', $type->id)->update([
                'min_rest_hours' => $this->restHours((int) $type->min_rest_hours),
                'preferred_rest_hours' => $this->restHours((int) $type->preferred_rest_hours),
                'default_duration_hours' => $type->default_duration_hours === null
                    ? null
                    : max(1, (int) ceil($type->default_duration_hours / 24)) * 24,
                'default_start_time' => $type->default_start_time === null ? null : '00:00:00',
            ]);
        });

        DB::table('user_events')->orderBy('id')->each(function (object $event): void {
            $startsAt = Carbon::parse($event->starts_at)->startOfDay();
            $originalEnd = Carbon::parse($event->ends_at);
            $endsAt = $originalEnd->copy()->startOfDay();

            if ($endsAt->lt($originalEnd) || $endsAt->lte($startsAt)) {
                $endsAt->addDay();
            }

            DB::table('user_events')->where('id', $event->id)->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        });
    }

    public function down(): void
    {
        //
    }

    private function restHours(int $hours): int
    {
        return $hours < 24 ? 0 : (int) ceil($hours / 24) * 24;
    }
};
