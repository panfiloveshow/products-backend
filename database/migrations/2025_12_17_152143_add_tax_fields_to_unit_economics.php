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
            $table->decimal('our_share_percent', 5, 2)->nullable()->after('to_settlement_account')
                ->comment('Наша часть % (100 - комиссия МП - налоги)');
            $table->decimal('tax_percent', 5, 2)->nullable()->after('our_share_percent')
                ->comment('Налоги % (УСН/ОСНО)');
            $table->decimal('vat_percent', 5, 2)->nullable()->after('tax_percent')
                ->comment('НДС %');
            $table->decimal('tax_amount', 12, 2)->nullable()->after('vat_percent')
                ->comment('Сумма налога за единицу');
            $table->decimal('vat_amount', 12, 2)->nullable()->after('tax_amount')
                ->comment('Сумма НДС за единицу');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn([
                'our_share_percent',
                'tax_percent',
                'vat_percent',
                'tax_amount',
                'vat_amount',
            ]);
        });
    }
};
