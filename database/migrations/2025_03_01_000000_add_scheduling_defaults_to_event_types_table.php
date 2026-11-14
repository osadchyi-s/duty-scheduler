<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional fields required for schedule auto-generation (AutoScheduler):
 * without them the system cannot calculate starts_at/ends_at for a new shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->time('default_start_time')->nullable()->after('preferred_rest_hours')
                ->comment('Default shift start time for auto-generation, e.g. 08:00:00');
            $table->unsignedSmallInteger('default_duration_hours')->nullable()->after('default_start_time')
                ->comment('Default shift duration (hours) for auto-generation, e.g. 24');
            $table->boolean('is_schedulable')->default(false)->after('default_duration_hours')
                ->comment('Whether the event type takes part in auto-generation (default: min_capacity > 0)');
        });
    }

    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->dropColumn(['default_start_time', 'default_duration_hours', 'is_schedulable']);
        });
    }
};
