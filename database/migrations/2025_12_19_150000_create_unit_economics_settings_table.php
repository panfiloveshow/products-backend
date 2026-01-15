<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблица настроек юнит-экономики (ручной ввод пользователя)
     * Хранит данные, которые пользователь вводит вручную:
     * - себестоимость
     * - налоги
     * - рекламные расходы
     * - переопределение % выкупа
     */
    public function up(): void
    {
        Schema::create('unit_economics_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->onDelete('cascade');
            $table->string('sku', 255);
            
            // Ручные данные (редактируются пользователем)
            $table->decimal('cost_price', 12, 2)->default(0)->comment('Себестоимость');
            $table->decimal('drr_percent', 5, 2)->default(0)->comment('РК % (ДРР)');
            $table->decimal('our_share_percent', 5, 2)->default(0)->comment('Наша часть %');
            $table->decimal('tax_percent', 5, 2)->default(6)->comment('Налог % (УСН)');
            $table->decimal('vat_percent', 5, 2)->default(0)->comment('НДС %');
            $table->decimal('redemption_rate_override', 5, 2)->nullable()->comment('Переопределение % выкупа (если null - берётся из API)');
            
            $table->timestamps();
            
            // Уникальный индекс: одна запись на товар в интеграции
            $table->unique(['integration_id', 'sku']);
            $table->index('integration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_economics_settings');
    }
};
