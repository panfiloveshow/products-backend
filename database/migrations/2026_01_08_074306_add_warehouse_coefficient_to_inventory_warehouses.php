<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет коэффициент склада (КС) для WB
     */
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            // Коэффициент склада (КС) — множитель для логистики WB
            $table->decimal('warehouse_coefficient', 5, 3)->nullable()->after('warehouse_name')
                ->comment('Коэффициент склада (КС) для WB — множитель логистики');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn('warehouse_coefficient');
        });
    }
};
