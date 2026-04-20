<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics_settings', function (Blueprint $table) {
            $table->boolean('is_select_only')->default(false)->after('spp_percent')
                ->comment('Товар продаётся только на Select — Ozon не применяет наценку за нелокальную продажу');
            $table->boolean('is_size_restricted')->default(false)->after('is_select_only')
                ->comment('Крупногабарит/ювелирка — запрет размещения в локальном складе, наценка не применяется');
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_settings', function (Blueprint $table) {
            $table->dropColumn(['is_select_only', 'is_size_restricted']);
        });
    }
};
