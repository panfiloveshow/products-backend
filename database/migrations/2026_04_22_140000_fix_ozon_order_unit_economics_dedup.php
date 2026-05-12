<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk-исправление мусора в ozon_order_unit_economics:
 *
 * 1. Почистить дубликаты: до фикса в сервисе ключ updateOrCreate шёл по
 *    posting_item_id, а PostingService пересоздаёт posting_items при каждом
 *    синке → на один posting_number накапливалось до 30+ сирот с разными
 *    item_id. Оставляем по самой свежей записи на бизнес-ключ
 *    (integration_id, posting_number, sku, offer_id).
 *
 * 2. Обновить order_date: раньше в cascade первыми шли delivered_at/
 *    shipment_date (обычно NULL), и fallback падал на Posting.created_at =
 *    время INSERT в нашу БД. Подтягиваем реальные in_process_at из postings.
 *
 * 3. Убрать старый UNIQUE по posting_item_id и повесить правильный
 *    по бизнес-ключу, чтобы баг больше не воспроизводился на БД-уровне.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        DB::statement('ANALYZE ozon_order_unit_economics');

        // 1. Dedup: оставляем по одной записи на (integration_id, posting_number, sku, offer_id).
        // Оставляем самый свежий id (тот что был обновлён последним).
        $deleted = DB::delete('
            DELETE FROM ozon_order_unit_economics
            WHERE id IN (
                SELECT id FROM (
                    SELECT id,
                        ROW_NUMBER() OVER (
                            PARTITION BY integration_id, posting_number, sku, offer_id
                            ORDER BY updated_at DESC, id DESC
                        ) AS rn
                    FROM ozon_order_unit_economics
                ) dupes
                WHERE rn > 1
            )
        ');

        // 2. Обновить order_date из postings.in_process_at (реальная дата Ozon-заказа).
        // Трогаем только те строки, где есть соответствующий posting с непустым in_process_at.
        if ($driver === 'sqlite') {
            DB::statement('
                UPDATE ozon_order_unit_economics
                SET order_date = (
                    SELECT p.in_process_at
                    FROM postings p
                    WHERE CAST(p.integration_id AS TEXT) = CAST(ozon_order_unit_economics.integration_id AS TEXT)
                      AND p.posting_number = ozon_order_unit_economics.posting_number
                      AND p.in_process_at IS NOT NULL
                    LIMIT 1
                )
                WHERE EXISTS (
                    SELECT 1
                    FROM postings p
                    WHERE CAST(p.integration_id AS TEXT) = CAST(ozon_order_unit_economics.integration_id AS TEXT)
                      AND p.posting_number = ozon_order_unit_economics.posting_number
                      AND p.in_process_at IS NOT NULL
                      AND (
                          ozon_order_unit_economics.order_date IS NULL
                          OR ozon_order_unit_economics.order_date != p.in_process_at
                      )
                )
            ');
        } else {
            DB::statement('
                UPDATE ozon_order_unit_economics oue
                SET order_date = p.in_process_at
                FROM postings p
                WHERE p.integration_id = oue.integration_id::text
                  AND p.posting_number = oue.posting_number
                  AND p.in_process_at IS NOT NULL
                  AND (oue.order_date IS NULL OR oue.order_date != p.in_process_at)
            ');
        }

        // 3. Заменить индекс: старый UNIQUE по posting_item_id → новый по бизнес-ключу.
        // Старый оставим как обычный INDEX для lookup'ов по posting_item_id.
        Schema::table('ozon_order_unit_economics', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropUnique('ozon_order_unit_economics_posting_item_unique');
            $table->index('posting_item_id', 'ozon_order_unit_economics_posting_item_idx');
            $table->unique(
                ['integration_id', 'posting_number', 'sku', 'offer_id'],
                'ozon_order_unit_economics_business_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ozon_order_unit_economics', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropUnique('ozon_order_unit_economics_business_key_unique');
            $table->dropIndex('ozon_order_unit_economics_posting_item_idx');
            $table->unique('posting_item_id', 'ozon_order_unit_economics_posting_item_unique');
        });
    }
};
