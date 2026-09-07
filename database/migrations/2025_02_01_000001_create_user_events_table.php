<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_type_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['draft', 'approved', 'cancelled'])->default('draft');
            $table->boolean('has_rest_warning')->default(false)
                ->comment('Призначення з використанням мінімального замість бажаного відпочинку');
            $table->string('warning_reason')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Матриця за місяць вантажиться одним запитом — індекси під основні фільтри.
            $table->index(['user_id', 'starts_at', 'ends_at']);
            $table->index(['event_type_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_events');
    }
};
