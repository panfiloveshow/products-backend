<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Раньше здесь создавалась другая схема с тем же именем таблицы, что ломало
        // migrate (конфликт с 2026_01_22_140000_create_supply_recommendations_table).
        // Актуальная структура задаётся в миграции от 2026_01_22.
    }

    public function down(): void
    {
        // Откат схемы — в миграции 2026_01_22_140000.
    }
};
