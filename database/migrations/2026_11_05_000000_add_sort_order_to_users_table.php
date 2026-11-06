<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual order of employees (drag and drop on the employees page), also used by
 * the duty matrix and the export. Existing employees are numbered by name, so
 * the matrix keeps its current order until someone reorders the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            $table->index('sort_order');
        });

        DB::table('users')->orderBy('name')->orderBy('id')->pluck('id')
            ->each(fn (int $id, int $index) => DB::table('users')->where('id', $id)->update(['sort_order' => $index + 1]));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
