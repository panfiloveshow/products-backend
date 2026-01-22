<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Проверяем текущий тип колонки id
        $result = DB::select("
            SELECT data_type 
            FROM information_schema.columns 
            WHERE table_name = 'supply_recommendations' AND column_name = 'id'
        ");
        
        if (!empty($result) && $result[0]->data_type === 'uuid') {
            // Удаляем старые данные (если есть)
            DB::statement('TRUNCATE TABLE supply_recommendations CASCADE');
            
            // Удаляем колонку uuid
            DB::statement('ALTER TABLE supply_recommendations DROP COLUMN id');
            
            // Добавляем bigserial колонку
            DB::statement('ALTER TABLE supply_recommendations ADD COLUMN id BIGSERIAL PRIMARY KEY');
        }
    }

    public function down(): void
    {
        // Откат не поддерживается
    }
};
