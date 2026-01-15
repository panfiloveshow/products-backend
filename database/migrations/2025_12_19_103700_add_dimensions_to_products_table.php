<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Добавляет поля габаритов товара в таблицу products
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Габариты товара (из Ozon API)
            $table->decimal('depth', 10, 2)->nullable()->after('fulfillment_type')->comment('Длина (мм)');
            $table->decimal('width', 10, 2)->nullable()->after('depth')->comment('Ширина (мм)');
            $table->decimal('height', 10, 2)->nullable()->after('width')->comment('Высота (мм)');
            $table->decimal('weight', 10, 2)->nullable()->after('height')->comment('Вес (г)');
            $table->decimal('volume_weight', 10, 4)->nullable()->after('weight')->comment('Объём (л)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['depth', 'width', 'height', 'weight', 'volume_weight']);
        });
    }
};
