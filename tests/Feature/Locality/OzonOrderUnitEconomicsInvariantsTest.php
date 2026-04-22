<?php

namespace Tests\Feature\Locality;

use App\Models\OzonOrderUnitEconomics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Инварианты для `ozon_order_unit_economics`, которые защищают от регрессии
 * багов, найденных 22.04.2026:
 *
 * - updateOrCreate должен плодить одну строку на бизнес-ключ (а не по posting_item_id,
 *   который перегенерируется при каждом синке постингов).
 * - UNIQUE-констрейнт БД должен быть на (integration_id, posting_number, sku, offer_id),
 *   а не на posting_item_id.
 *
 * Сценарий бага: PostingService пересоздаёт posting_items при каждом синке с
 * новыми UUID; старый ключ updateOrCreate по posting_item_id плодил по 30+
 * дубликатов на один реальный заказ Ozon. После dedup-миграции 2026-04-22
 * 1.1M строк превратились в 180k.
 */
class OzonOrderUnitEconomicsInvariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_constraint_blocks_duplicates_on_business_key(): void
    {
        $base = [
            'integration_id' => 17,
            'posting_id' => '019d684f-fd0e-71e7-b0cb-8ec26eac26ad',
            'posting_item_id' => '00000000-0000-0000-0000-000000000001',
            'posting_number' => '0180682028-0086-1',
            'sku' => '5309/black',
            'offer_id' => '5309/black',
            'order_date' => '2026-04-22 10:00:00',
            'sale_price' => 16789.00,
            'base_logistics_tariff' => 215.0,
            'non_local_markup_percent' => 0.0,
            'non_local_markup_amount' => 0.0,
            'markup_applied' => false,
            'calculation_mode' => 'factual',
            'calculation_confidence' => 'high',
        ];

        OzonOrderUnitEconomics::create($base);

        // Попытка вставить второй row с тем же business-key, но другим posting_item_id
        // должна упасть на UNIQUE: это и был корневой баг.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $base['posting_item_id'] = '00000000-0000-0000-0000-000000000002';
        OzonOrderUnitEconomics::create($base);
    }

    public function test_updateOrCreate_with_business_key_preserves_single_row(): void
    {
        $businessKey = [
            'integration_id' => 17,
            'posting_number' => '0180682028-0086-1',
            'sku' => '5309/black',
            'offer_id' => '5309/black',
        ];

        // Сценарий: три синка подряд, каждый раз с НОВЫМ posting_item_id
        // (как это делает PostingService при пересоздании posting_items).
        foreach ([1, 2, 3] as $i) {
            OzonOrderUnitEconomics::updateOrCreate(
                $businessKey,
                [
                    'posting_id' => '019d684f-fd0e-71e7-b0cb-8ec26eac26ad',
                    'posting_item_id' => "0000000{$i}-0000-0000-0000-000000000000",
                    'order_date' => '2026-04-22 10:00:00',
                    'sale_price' => 16789.00,
                    'base_logistics_tariff' => 215.0 + $i,
                    'non_local_markup_percent' => 0.0,
                    'non_local_markup_amount' => 0.0,
                    'markup_applied' => false,
                    'calculation_mode' => 'factual',
                    'calculation_confidence' => 'high',
                ]
            );
        }

        $count = OzonOrderUnitEconomics::where($businessKey)->count();
        $this->assertSame(
            1,
            $count,
            'Три updateOrCreate с одним business-key должны дать ровно одну строку'
        );

        // И последний sync должен был перезаписать поля.
        $row = OzonOrderUnitEconomics::where($businessKey)->first();
        $this->assertSame(218.0, (float) $row->base_logistics_tariff);
    }
}
