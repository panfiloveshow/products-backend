<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->uuid('supply_plan_id')->nullable()->after('id');
            $table->string('external_supply_id')->nullable()->after('supply_plan_id');
            $table->string('external_status')->nullable()->after('status');
            $table->timestamp('synced_at')->nullable()->after('delivered_at');
            
            $table->index('supply_plan_id');
            $table->index('external_supply_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['supply_plan_id']);
            $table->dropIndex(['external_supply_id']);
            $table->dropColumn([
                'supply_plan_id',
                'external_supply_id',
                'external_status',
                'synced_at',
            ]);
        });
    }
};
