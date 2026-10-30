<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duty_limits', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // A period in use cannot be deleted, so limits never lose their period silently.
            $table->foreignId('period_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('max_days')
                ->comment('Maximum duty days per employee within one period window');
            $table->string('severity', 20)->default('forbidden')
                ->comment('forbidden = hard limit, warning = soft limit');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('duty_limit_employee_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_limit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['duty_limit_id', 'employee_group_id']);
        });

        Schema::create('duty_limit_event_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_limit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['duty_limit_id', 'event_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duty_limit_event_type');
        Schema::dropIfExists('duty_limit_employee_group');
        Schema::dropIfExists('duty_limits');
    }
};
