<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_slots', function (Blueprint $table) {
            // Datetime поля для Ozon
            $table->timestamp('from_datetime')->nullable()->after('time_to');
            $table->timestamp('to_datetime')->nullable()->after('from_datetime');
            
            // WB специфичные поля
            $table->boolean('allow_unload')->default(false)->after('is_available');
            $table->integer('box_type_id')->nullable()->after('pallets_limit');
            $table->boolean('is_sorting_center')->default(false)->after('box_type_id');
            $table->decimal('storage_coefficient', 8, 3)->nullable()->after('is_sorting_center');
            $table->decimal('delivery_coefficient', 8, 3)->nullable()->after('storage_coefficient');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_slots', function (Blueprint $table) {
            $table->dropColumn([
                'from_datetime',
                'to_datetime',
                'allow_unload',
                'box_type_id',
                'is_sorting_center',
                'storage_coefficient',
                'delivery_coefficient',
            ]);
        });
    }
};
