<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Индексы под актуальный паттерн запросов:
 *
 * 1. `postings(integration_id, in_process_at) WHERE marketplace='ozon'` —
 *    основной фильтр в OzonPostingsBuyoutCalculator и OzonLocalityService.
 *    Раньше индексы были (integration_id, status) и (integration_id, shipment_date),
 *    но `in_process_at` не покрыт — seq-scan + фильтр в памяти на 177k строк.
 *
 * 2. `posting_items(sku, posting_id)` — для single-SKU buyout-калькулятора.
 *    Позволяет index-scan по SKU, затем nested-loop в postings.
 *
 * CONCURRENTLY — чтобы не блокировать запись в postings во время создания индекса.
 */
return new class extends Migration
{
    // Обязательно для CREATE INDEX CONCURRENTLY в Postgres: нельзя внутри транзакции.
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('
                CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_postings_integration_in_process_at
                ON postings (integration_id, in_process_at)
                WHERE marketplace = \'ozon\'
            ');

            DB::statement('
                CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_posting_items_sku_posting
                ON posting_items (sku, posting_id)
            ');
        } else {
            DB::statement('CREATE INDEX idx_postings_integration_in_process_at ON postings (integration_id, in_process_at)');
            DB::statement('CREATE INDEX idx_posting_items_sku_posting ON posting_items (sku, posting_id)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_postings_integration_in_process_at');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_posting_items_sku_posting');
        } else {
            DB::statement('DROP INDEX idx_postings_integration_in_process_at ON postings');
            DB::statement('DROP INDEX idx_posting_items_sku_posting ON posting_items');
        }
    }
};
