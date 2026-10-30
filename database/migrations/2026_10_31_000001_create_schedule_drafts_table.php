<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule drafts: alternative versions of a month's schedule. Their events
 * live in user_events with schedule_draft_id set; NULL means the approved schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('month')->comment('First day of the drafted month');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('snapshot_at')->comment('When the approved schedule was copied into the draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('stale_at')->nullable()->comment('Set when another draft of the month is approved');
            $table->timestamps();

            $table->index('month');
        });

        Schema::table('user_events', function (Blueprint $table) {
            $table->foreignId('schedule_draft_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('source_event_id')->nullable()->after('schedule_draft_id')
                ->comment('Approved event this draft event was copied from')
                ->constrained('user_events')->nullOnDelete();

            $table->index(['schedule_draft_id', 'user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('user_events', function (Blueprint $table) {
            $table->dropIndex(['schedule_draft_id', 'user_id', 'starts_at']);
            $table->dropConstrainedForeignId('source_event_id');
            $table->dropConstrainedForeignId('schedule_draft_id');
        });

        Schema::dropIfExists('schedule_drafts');
    }
};
