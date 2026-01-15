<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->decimal('expected_return_cost', 10, 2)->nullable()->after('return_processing_cost')
                ->comment('Ожидаемая стоимость возвратов с учётом % выкупа');
            $table->decimal('effective_logistics', 10, 2)->nullable()->after('expected_return_cost')
                ->comment('Эффективная логистика = прямая + ожидаемые возвраты');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn(['expected_return_cost', 'effective_logistics']);
        });
    }
};
