<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // PostgreSQL требует явного преобразования типа
        DB::statement('ALTER TABLE shipments ALTER COLUMN integration_id TYPE VARCHAR(255) USING integration_id::VARCHAR');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE shipments ALTER COLUMN integration_id TYPE UUID USING integration_id::UUID');
    }
};
