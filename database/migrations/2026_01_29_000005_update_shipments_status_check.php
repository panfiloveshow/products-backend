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

        // Удаляем старый check constraint
        DB::statement('ALTER TABLE shipments DROP CONSTRAINT IF EXISTS shipments_status_check');
        
        // Создаём новый с добавлением cancelled
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'sent', 'delivered', 'cancelled'))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE shipments DROP CONSTRAINT IF EXISTS shipments_status_check');
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'sent', 'delivered'))");
    }
};
