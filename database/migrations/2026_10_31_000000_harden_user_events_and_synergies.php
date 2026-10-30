<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Deleting an event type or an employee no longer wipes their event history:
 *   such records are deactivated instead (is_active).
 * - Combined warnings (rest + duty limits) may exceed 255 characters.
 * - Synergy is a three-state value (-1, 0, 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_events', function (Blueprint $table) {
            $table->dropForeign(['event_type_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('user_events', function (Blueprint $table) {
            $table->foreign('event_type_id')->references('id')->on('event_types')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->text('warning_reason')->nullable()->change();
        });

        DB::table('synergies')->where('score', '>', 1)->update(['score' => 1]);
        DB::table('synergies')->where('score', '<', -1)->update(['score' => -1]);

        Schema::table('synergies', function (Blueprint $table) {
            $table->smallInteger('score')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('synergies', function (Blueprint $table) {
            $table->bigInteger('score')->default(0)->change();
        });

        Schema::table('user_events', function (Blueprint $table) {
            $table->dropForeign(['event_type_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('user_events', function (Blueprint $table) {
            $table->foreign('event_type_id')->references('id')->on('event_types')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('warning_reason')->nullable()->change();
        });
    }
};
