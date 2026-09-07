<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Додаткові поля, необхідні для автогенерації графіка (AutoScheduler):
 * без них система не може розрахувати starts_at/ends_at для нової зміни.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->time('default_start_time')->nullable()->after('preferred_rest_hours')
                ->comment('Типовий час початку зміни для автогенерації, напр. 08:00:00');
            $table->unsignedSmallInteger('default_duration_hours')->nullable()->after('default_start_time')
                ->comment('Типова тривалість зміни (год) для автогенерації, напр. 24');
            $table->boolean('is_schedulable')->default(false)->after('default_duration_hours')
                ->comment('Чи бере участь тип події в автогенерації графіка (типово: min_capacity > 0)');
        });
    }

    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->dropColumn(['default_start_time', 'default_duration_hours', 'is_schedulable']);
        });
    }
};
