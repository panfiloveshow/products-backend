<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
        });

        Schema::table('shipment_recommendations', function (Blueprint $table): void {
            $table->unsignedBigInteger('integration_id')->nullable()->index();
        });

        Schema::table('inventory_alerts', function (Blueprint $table): void {
            $table->unsignedBigInteger('integration_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_alerts', function (Blueprint $table): void {
            $table->dropColumn('integration_id');
        });

        Schema::table('shipment_recommendations', function (Blueprint $table): void {
            $table->dropColumn('integration_id');
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('workspace_id');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('workspace_id');
        });
    }
};
