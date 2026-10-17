<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_b_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('score')->default(0);
            $table->timestamps();

            $table->unique(['user_a_id', 'user_b_id']);
        });

        Schema::create('synergy_event_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('synergy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['synergy_id', 'event_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synergy_event_type');
        Schema::dropIfExists('synergies');
    }
};
