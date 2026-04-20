<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->string('tariff_version', 32)->nullable()->after('additional_commission_amount');
            $table->date('tariff_effective_from')->nullable()->after('tariff_version');
            $table->string('tariff_source', 32)->nullable()->after('tariff_effective_from');
            $table->string('route_key', 120)->nullable()->after('tariff_source');
            $table->string('route_label', 255)->nullable()->after('route_key');
            $table->boolean('is_local_sale')->nullable()->after('route_label');
            $table->decimal('non_local_markup_percent', 5, 2)->nullable()->after('is_local_sale');
            $table->string('price_segment', 32)->nullable()->after('non_local_markup_percent');
            $table->decimal('sales_fee_percent', 5, 2)->nullable()->after('price_segment');
        });

        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->string('tariff_version', 32)->nullable()->after('additional_commission_percent');
            $table->date('tariff_effective_from')->nullable()->after('tariff_version');
            $table->string('tariff_source', 32)->nullable()->after('tariff_effective_from');
            $table->string('route_key', 120)->nullable()->after('tariff_source');
            $table->string('route_label', 255)->nullable()->after('route_key');
            $table->boolean('is_local_sale')->nullable()->after('route_label');
            $table->decimal('non_local_markup_percent', 5, 2)->nullable()->after('is_local_sale');
            $table->string('price_segment', 32)->nullable()->after('non_local_markup_percent');
            $table->decimal('sales_fee_percent', 5, 2)->nullable()->after('price_segment');
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn([
                'tariff_version',
                'tariff_effective_from',
                'tariff_source',
                'route_key',
                'route_label',
                'is_local_sale',
                'non_local_markup_percent',
                'price_segment',
                'sales_fee_percent',
            ]);
        });

        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->dropColumn([
                'tariff_version',
                'tariff_effective_from',
                'tariff_source',
                'route_key',
                'route_label',
                'is_local_sale',
                'non_local_markup_percent',
                'price_segment',
                'sales_fee_percent',
            ]);
        });
    }
};
