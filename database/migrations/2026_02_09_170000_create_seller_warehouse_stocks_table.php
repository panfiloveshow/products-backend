<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('sku', 100)->index();
            $table->string('barcode', 100)->nullable();
            $table->string('product_name', 500)->nullable();
            $table->integer('quantity')->default(0)->comment('Остаток на собственном складе');
            $table->integer('reserved')->default(0)->comment('Зарезервировано (уже в процессе отправки)');
            $table->decimal('cost_price', 12, 2)->nullable()->comment('Себестоимость единицы');
            $table->string('location', 200)->nullable()->comment('Место хранения / ячейка');
            $table->text('note')->nullable()->comment('Заметка продавца');
            $table->timestamp('last_counted_at')->nullable()->comment('Дата последней инвентаризации');
            $table->timestamps();

            $table->unique(['integration_id', 'sku']);
        });

        // Добавляем поля own_stock и deficit в auto_supply_plan_lines
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->integer('own_stock')->nullable()->after('region')->comment('Остаток на собственном складе продавца');
            $table->integer('own_stock_reserved')->nullable()->after('own_stock')->comment('Зарезервировано на собственном складе');
            $table->integer('deficit')->nullable()->after('own_stock_reserved')->comment('Дефицит: сколько нужно докупить (qty - available_own_stock)');
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->dropColumn(['own_stock', 'own_stock_reserved', 'deficit']);
        });

        Schema::dropIfExists('seller_warehouse_stocks');
    }
};
