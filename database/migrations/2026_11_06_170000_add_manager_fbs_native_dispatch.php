<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_fbs_operations', function (Blueprint $table) {
            $table->timestamp('native_started_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            if (DB::table('manager_fbs_operations')->where('operation_type', 'fbs_cancel')->orWhereNotNull('native_started_at')->exists()) {
                throw new RuntimeException('FBS native dispatch/cancellation history prevents downgrade');
            }
            Schema::table('manager_fbs_operations', fn (Blueprint $table) => $table->dropColumn('native_started_at'));
        });
    }
};
