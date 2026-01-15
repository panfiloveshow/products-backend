<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->decimal('markup_percent', 8, 2)->nullable()->after('margin_percent')
                ->comment('Наценка в % от себестоимости: (цена - себестоимость) / себестоимость * 100');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn('markup_percent');
        });
    }
};
