<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warnings are no longer only about rest periods (soft duty limits produce them too).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_events', function (Blueprint $table) {
            $table->renameColumn('has_rest_warning', 'has_warning');
        });
    }

    public function down(): void
    {
        Schema::table('user_events', function (Blueprint $table) {
            $table->renameColumn('has_warning', 'has_rest_warning');
        });
    }
};
