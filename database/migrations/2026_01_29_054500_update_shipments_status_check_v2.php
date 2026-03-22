<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Удаляем старый constraint
        DB::statement('ALTER TABLE shipments DROP CONSTRAINT IF EXISTS shipments_status_check');
        
        // Добавляем новый constraint со всеми статусами
        DB::statement("
            ALTER TABLE shipments ADD CONSTRAINT shipments_status_check 
            CHECK (status IN (
                'draft',
                'pending_logistics',
                'submitted',
                'pending_confirmation',
                'confirmed',
                'approved',
                'sent',
                'in_transit',
                'arrived',
                'processing',
                'partially_accepted',
                'delivered',
                'rejected',
                'cancelled'
            ))
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE shipments DROP CONSTRAINT IF EXISTS shipments_status_check');
    }
};
