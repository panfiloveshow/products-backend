<?php

namespace Tests\Unit\Locality;

use App\Domains\Locality\Ingestion\FinanceTransactionSyncer;
use PHPUnit\Framework\TestCase;

/**
 * Парсинг форм ответа /v1/finance/accrual/by-day (реальные примеры с прода
 * 2026-08-25): категория ITEM несёт сборы в item_fees.fees[].fees[],
 * категория POSTING — в posting.products[].delivery.services[].
 */
class FinanceTransactionSyncerParsingTest extends TestCase
{
    private function aggregate(array $accrual): array
    {
        $syncer = new FinanceTransactionSyncer();
        $method = new \ReflectionMethod($syncer, 'aggregateAccrualFees');
        $aggregated = [];
        $args = [&$aggregated, $accrual, '2026-08-15'];
        $method->invokeArgs($syncer, $args);

        return array_values($aggregated);
    }

    public function test_item_category_fees_are_extracted_with_sku_and_type_name(): void
    {
        $rows = $this->aggregate([
            'accrual_id' => 59770870544,
            'date' => '2026-08-15',
            'total_amount' => ['amount' => '-55.5', 'currency' => 'RUB'],
            'unit_number' => '0139874677-0047',
            'accrued_category' => 'ITEM',
            'posting' => null,
            'item_fees' => ['fees' => [[
                'sku' => 2804622817,
                'fees' => [['type_id' => 1, 'accrued' => ['amount' => '-55.5', 'currency' => 'RUB']]],
            ]]],
            'non_item_fee' => null,
            'container_fees' => null,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Acquiring', $rows[0]['operation_type']);
        $this->assertSame('2804622817', $rows[0]['sku']);
        $this->assertSame(-55.5, $rows[0]['amount']);
        $this->assertSame('0139874677-0047', $rows[0]['posting_number']);
        $this->assertStringContainsString(':', $rows[0]['operation_id']);
    }

    public function test_posting_category_delivery_services_are_extracted(): void
    {
        $rows = $this->aggregate([
            'accrual_id' => 59770910603,
            'date' => '2026-08-15',
            'total_amount' => ['amount' => '-144', 'currency' => 'RUB'],
            'unit_number' => '29416401-0701-11',
            'accrued_category' => 'POSTING',
            'posting' => [
                'delivery_schema' => 'Fbo',
                'products' => [[
                    'sku' => 1105098075,
                    'delivery' => [
                        'total_accrued' => ['amount' => '-144', 'currency' => 'RUB'],
                        'services' => [['type_id' => 32, 'accrued' => ['amount' => '-144', 'currency' => 'RUB']]],
                    ],
                    'commission' => null,
                ]],
            ],
            'item_fees' => null,
            'non_item_fee' => null,
            'container_fees' => null,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Logistic', $rows[0]['operation_type']);
        $this->assertSame('1105098075', $rows[0]['sku']);
        $this->assertSame(-144.0, $rows[0]['amount']);
    }

    public function test_unknown_type_id_and_missing_fees_fall_back_gracefully(): void
    {
        $rows = $this->aggregate([
            'accrual_id' => 111,
            'unit_number' => null,
            'accrued_category' => 'OTHER',
            'item_fees' => ['fees' => [[
                'sku' => 42,
                'fees' => [['type_id' => 9999, 'accrued' => ['amount' => '-10', 'currency' => 'RUB']]],
            ]]],
            'total_amount' => ['amount' => '-10', 'currency' => 'RUB'],
        ]);
        $this->assertSame('AccrualType_9999', $rows[0]['operation_type']);

        // Совсем без разложения — фиксируем сумму строки типом 0.
        $rows = $this->aggregate([
            'accrual_id' => 222,
            'total_amount' => ['amount' => '-33.3', 'currency' => 'RUB'],
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame(-33.3, $rows[0]['amount']);
        $this->assertSame('AccrualType_0', $rows[0]['operation_type']);
    }

    public function test_same_type_and_sku_within_accrual_is_aggregated_not_duplicated(): void
    {
        $rows = $this->aggregate([
            'accrual_id' => 333,
            'unit_number' => 'p-1',
            'item_fees' => ['fees' => [[
                'sku' => 7,
                'fees' => [
                    ['type_id' => 28, 'accrued' => ['amount' => '-10', 'currency' => 'RUB']],
                    ['type_id' => 28, 'accrued' => ['amount' => '-5', 'currency' => 'RUB']],
                ],
            ]]],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(-15.0, $rows[0]['amount']);
        $this->assertSame('LastMile', $rows[0]['operation_type']);
    }
}
