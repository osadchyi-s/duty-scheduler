<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "draft" status is replaced by schedule drafts. Events with status "draft"
 * are moved, per month of their start, into a new draft that also receives a
 * copy of that month's approved events. The status column then only
 * distinguishes approved and cancelled events.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // The original enum column carries a check constraint with the old values.
            DB::statement('ALTER TABLE user_events DROP CONSTRAINT IF EXISTS user_events_status_check');
        }

        // Changed before copying events: SQLite rebuilds the table on change(), which
        // would reset the self-referencing source_event_id of already copied rows.
        Schema::table('user_events', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->change();
        });

        $months = DB::table('user_events')
            ->where('status', 'draft')
            ->whereNull('schedule_draft_id')
            ->pluck('starts_at')
            ->map(fn (string $startsAt): string => Carbon::parse($startsAt)->startOfMonth()->toDateString())
            ->unique();

        foreach ($months as $month) {
            $from = Carbon::parse($month);
            $to = $from->copy()->addMonth();
            $now = now();

            $draftId = DB::table('schedule_drafts')->insertGetId([
                'name' => __('Migrated drafts'),
                'month' => $from->toDateString(),
                'snapshot_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Copy the approved events of the month, like a newly created draft does.
            DB::table('user_events')
                ->whereNull('schedule_draft_id')
                ->where('status', 'approved')
                ->where('starts_at', '<', $to)
                ->where('ends_at', '>', $from)
                ->orderBy('id')
                ->each(function (object $event) use ($draftId): void {
                    $copy = (array) $event;
                    unset($copy['id']);
                    $copy['schedule_draft_id'] = $draftId;
                    $copy['source_event_id'] = $event->id;
                    DB::table('user_events')->insert($copy);
                });

            DB::table('user_events')
                ->whereNull('schedule_draft_id')
                ->where('status', 'draft')
                ->where('starts_at', '>=', $from)
                ->where('starts_at', '<', $to)
                ->update(['schedule_draft_id' => $draftId, 'status' => 'approved']);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE user_events ADD CONSTRAINT user_events_status_check CHECK (status IN ('approved', 'cancelled'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE user_events DROP CONSTRAINT IF EXISTS user_events_status_check');
            DB::statement("ALTER TABLE user_events ADD CONSTRAINT user_events_status_check CHECK (status IN ('draft', 'approved', 'cancelled'))");
        }

        Schema::table('user_events', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });
    }
};
