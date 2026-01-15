<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет новые схемы WB: DBS, EDBS, DBW, MIXED
     * Для SQLite: удаляем старые данные и пересоздаём таблицу без enum constraint
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite: пересоздаём таблицу без CHECK constraint на fulfillment_type
            DB::statement('PRAGMA foreign_keys=off');
            
            // Удаляем старую таблицу (данные пересчитаются при следующей синхронизации)
            Schema::dropIfExists('unit_economics_cache');
            
            // Создаём новую таблицу с string вместо enum
            Schema::create('unit_economics_cache', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('integration_id');
                $table->string('sku');
                $table->string('fulfillment_type', 20)->default('FBO');
                $table->uuid('product_id')->nullable();
                $table->string('product_name')->nullable();
                $table->string('marketplace', 50)->default('wildberries');
                
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('old_price', 12, 2)->nullable();
                $table->integer('sales_count')->default(0);
                $table->boolean('is_in_promotion')->default(false);
                $table->decimal('promotion_discount', 5, 2)->nullable();
                
                $table->decimal('volume_liters', 10, 4)->nullable();
                $table->decimal('volume_weight', 10, 4)->nullable();
                $table->decimal('depth', 10, 2)->nullable();
                $table->decimal('width', 10, 2)->nullable();
                $table->decimal('height', 10, 2)->nullable();
                $table->decimal('weight', 10, 2)->nullable();
                
                $table->decimal('commission_percent', 5, 2)->default(0);
                $table->decimal('commission_amount', 12, 2)->default(0);
                
                $table->decimal('avg_delivery_time_hours', 8, 2)->nullable();
                $table->decimal('logistics_coefficient', 5, 2)->default(1);
                $table->decimal('additional_commission_percent', 5, 2)->default(0);
                $table->decimal('base_logistics_cost', 12, 2)->default(0);
                $table->decimal('logistics_cost', 12, 2)->default(0);
                $table->decimal('last_mile_cost', 12, 2)->default(0);
                $table->decimal('processing_cost', 12, 2)->default(0);
                $table->decimal('storage_cost', 12, 2)->default(0);
                
                $table->decimal('redemption_rate', 5, 2)->default(80);
                $table->string('redemption_source', 50)->default('default');
                $table->integer('orders_count')->nullable();
                $table->integer('returns_count')->nullable();
                $table->decimal('return_logistics_cost', 12, 2)->default(0);
                $table->decimal('return_processing_cost', 12, 2)->default(0);
                $table->decimal('expected_return_cost', 12, 2)->default(0);
                $table->decimal('effective_logistics', 12, 2)->default(0);
                
                $table->decimal('acquiring_percent', 5, 2)->default(0);
                $table->decimal('acquiring_amount', 12, 2)->default(0);
                
                $table->decimal('cost_price', 12, 2)->default(0);
                $table->decimal('drr_percent', 5, 2)->default(0);
                $table->decimal('drr_amount', 12, 2)->default(0);
                $table->decimal('our_share_percent', 5, 2)->default(0);
                $table->decimal('our_share_amount', 12, 2)->default(0);
                $table->decimal('tax_percent', 5, 2)->default(6);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->decimal('vat_percent', 5, 2)->default(0);
                $table->decimal('vat_amount', 12, 2)->default(0);
                
                $table->decimal('revenue', 12, 2)->default(0);
                $table->decimal('total_costs', 12, 2)->default(0);
                $table->decimal('gross_profit', 12, 2)->default(0);
                $table->decimal('net_profit', 12, 2)->default(0);
                $table->decimal('to_settlement_account', 12, 2)->default(0);
                $table->decimal('margin_percent', 8, 2)->default(0);
                $table->decimal('markup_percent', 8, 2)->default(0);
                $table->decimal('roi_percent', 8, 2)->default(0);
                
                $table->timestamp('calculated_at')->nullable();
                $table->integer('data_version')->default(1);
                $table->timestamps();
                
                $table->unique(['integration_id', 'sku', 'fulfillment_type'], 'uec_integration_sku_scheme');
                $table->index(['integration_id', 'marketplace', 'fulfillment_type'], 'uec_integration_mp_scheme');
                $table->index('product_id');
            });
            
            DB::statement('PRAGMA foreign_keys=on');
        } else {
            Schema::table('unit_economics_cache', function (Blueprint $table) {
                $table->string('fulfillment_type', 20)->default('FBO')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Данные пересчитаются при следующей синхронизации
    }
};
