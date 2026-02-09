<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            // Платное хранение (штраф за превышение 120 дней на складе Ozon)
            // Данные из /v3/finance/transaction/list — MarketplaceServiceItemStorageExcess
            $table->decimal('paid_storage_penalty', 12, 2)->nullable()->after('storage_fee_report_to')
                ->comment('Штраф за платное хранение (>120 дней), руб');
            $table->decimal('paid_storage_fee', 12, 2)->nullable()->after('paid_storage_penalty')
                ->comment('Обычная плата за хранение (StorageFee), руб');
            $table->date('paid_storage_from')->nullable()->after('paid_storage_fee')
                ->comment('Начало периода начислений');
            $table->date('paid_storage_to')->nullable()->after('paid_storage_from')
                ->comment('Конец периода начислений');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'paid_storage_penalty',
                'paid_storage_fee',
                'paid_storage_from',
                'paid_storage_to',
            ]);
        });
    }
};
