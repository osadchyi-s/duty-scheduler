<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 50)
                ->comment('Period type key, see App\Periods\PeriodTypes');
            $table->json('params')->nullable()
                ->comment('Type-specific settings, e.g. start/end dates');
            $table->boolean('is_system')->default(false)
                ->comment('Built-in period that cannot be edited or deleted');
            $table->timestamps();
        });

        // Built-in calendar periods are available right after migrating, without seeders.
        DB::table('periods')->insert([
            ['name' => 'Calendar week', 'type' => 'calendar_week', 'params' => null, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Calendar month', 'type' => 'calendar_month', 'params' => null, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
