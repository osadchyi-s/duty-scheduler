<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('locale', 2)->default('uk');
            $table->timestamps();
        });

        DB::table('app_settings')->insert([
            'id' => 1,
            'locale' => config('app.locale', 'uk'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
