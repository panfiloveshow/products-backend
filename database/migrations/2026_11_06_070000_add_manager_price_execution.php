<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void { Schema::table('manager_price_proposals', function (Blueprint $t) { $t->json('approval')->nullable(); $t->json('executor_receipt')->nullable(); $t->timestampTz('dispatch_started_at')->nullable(); }); }
    public function down(): void {
        if (DB::table('manager_price_proposals')->whereIn('status', ['executing', 'verifying', 'unknown'])->exists()) throw new RuntimeException('Unresolved price commands prevent downgrade');
        Schema::table('manager_price_proposals', fn (Blueprint $t) => $t->dropColumn(['approval', 'executor_receipt', 'dispatch_started_at']));
    }
};
