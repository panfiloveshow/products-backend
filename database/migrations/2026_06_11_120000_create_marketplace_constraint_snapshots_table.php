<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_constraint_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('marketplace', 50)->index();

            // Нормализованный к формату плана срез ограничений (как у AutoSupplyConstraintFile).
            $table->json('cluster_constraints_json')->nullable();   // ozon
            $table->json('warehouse_constraints_json')->nullable(); // прочие МП

            $table->json('summary_json')->nullable();  // coverage, кол-во складов, доступно/закрыто
            $table->json('sources_json')->nullable();  // какие API дали данные (timeslot/draft/coef/box)

            $table->string('sync_status', 20)->default('ok'); // ok | partial | error
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'marketplace'], 'mp_constraint_snap_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_constraint_snapshots');
    }
};
