<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Цена, которую видит покупатель на витрине (WB: после СПП, из card.wb.ru).
            // NULL → на фронте показываем price. Ozon покупательскую цену через API не отдаёт.
            $table->decimal('buyer_price', 12, 2)->nullable()->after('old_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('buyer_price'));
    }
};
