<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_slots', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouse_slots', 'from_datetime')) {
                $table->timestamp('from_datetime')->nullable()->after('time_to');
            }
            if (!Schema::hasColumn('warehouse_slots', 'to_datetime')) {
                $table->timestamp('to_datetime')->nullable()->after('from_datetime');
            }
            if (!Schema::hasColumn('warehouse_slots', 'allow_unload')) {
                $table->boolean('allow_unload')->default(false)->after('is_available');
            }
            if (!Schema::hasColumn('warehouse_slots', 'box_type_id')) {
                $table->integer('box_type_id')->nullable()->after('pallets_limit');
            }
            if (!Schema::hasColumn('warehouse_slots', 'is_sorting_center')) {
                $table->boolean('is_sorting_center')->default(false)->after('box_type_id');
            }
            if (!Schema::hasColumn('warehouse_slots', 'storage_coefficient')) {
                $table->decimal('storage_coefficient', 8, 3)->nullable()->after('is_sorting_center');
            }
            if (!Schema::hasColumn('warehouse_slots', 'delivery_coefficient')) {
                $table->decimal('delivery_coefficient', 8, 3)->nullable()->after('storage_coefficient');
            }
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
