<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_group_user');
        Schema::dropIfExists('employee_groups');
    }
};
