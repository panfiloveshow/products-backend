<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_constraint_files', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_supply_constraint_files', 'source_kind')) {
                // file | api_sync | manual — отличать ручной файл от авто-синка ограничений.
                $table->string('source_kind', 20)->default('file')->after('marketplace');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_constraint_files', function (Blueprint $table) {
            if (Schema::hasColumn('auto_supply_constraint_files', 'source_kind')) {
                $table->dropColumn('source_kind');
            }
        });
    }
};
