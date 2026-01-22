<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            if (!Schema::hasColumn('supply_recommendations', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
