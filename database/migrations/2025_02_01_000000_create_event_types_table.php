<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('color_code', 9)->default('#3B82F6');
            $table->boolean('is_blocking')->default(false)
                ->comment('Blocks assignment to any other events');
            $table->unsignedSmallInteger('min_capacity')->default(0);
            $table->unsignedSmallInteger('max_capacity')->nullable();
            $table->unsignedSmallInteger('min_rest_hours')->default(0)
                ->comment('Hard constraint: required minimum rest');
            $table->unsignedSmallInteger('preferred_rest_hours')->default(0)
                ->comment('Soft constraint: preferred rest');
            $table->boolean('is_work_time')->default(true)
                ->comment('Included in the working-hours balance');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_types');
    }
};
