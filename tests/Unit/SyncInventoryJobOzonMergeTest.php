<?php

namespace Tests\Unit;

use App\Jobs\SyncInventoryJob;
use App\Models\SyncLog;
use ReflectionMethod;
use Tests\TestCase;

class SyncInventoryJobOzonMergeTest extends TestCase
{
    public function test_ozon_aggregate_inventory_fills_missing_fulfillment_rows_without_duplicating_detailed_rows(): void
    {
        $job = new SyncInventoryJob(new SyncLog([
            'marketplace' => 'ozon',
            'integration_id' => 18,
        ]));

        $merge = new ReflectionMethod($job, 'mergeOzonAggregateInventory');
        $merge->setAccessible(true);

        $result = $merge->invoke($job, [
            [
                'sku' => 'sku-1',
                'warehouses' => [[
                    'warehouse_id' => 'ozon_real_fbo',
                    'warehouse_name' => 'Real FBO',
                    'fulfillment_type' => 'FBO',
                    'quantity' => 2,
                ]],
            ],
        ], [
            [
                'sku' => 'sku-1',
                'warehouses' => [
                    [
                        'warehouse_id' => 'ozon_fbo',
                        'warehouse_name' => 'Ozon FBO',
                        'fulfillment_type' => 'FBO',
                        'quantity' => 2,
                    ],
                    [
                        'warehouse_id' => 'ozon_fbs',
                        'warehouse_name' => 'Ozon FBS',
                        'fulfillment_type' => 'FBS',
                        'quantity' => 10,
                    ],
                ],
            ],
            [
                'sku' => 'sku-2',
                'warehouses' => [[
                    'warehouse_id' => 'ozon_fbs',
                    'warehouse_name' => 'Ozon FBS',
                    'fulfillment_type' => 'FBS',
                    'quantity' => 7,
                ]],
            ],
        ]);

        $flat = collect($result)->flatMap(fn (array $item) => collect($item['warehouses'])->map(fn (array $warehouse) => [
            'sku' => $item['sku'],
            'warehouse_id' => $warehouse['warehouse_id'],
            'quantity' => $warehouse['quantity'],
        ]))->values()->all();

        $this->assertContains(['sku' => 'sku-1', 'warehouse_id' => 'ozon_real_fbo', 'quantity' => 2], $flat);
        $this->assertContains(['sku' => 'sku-1', 'warehouse_id' => 'ozon_fbs', 'quantity' => 10], $flat);
        $this->assertContains(['sku' => 'sku-2', 'warehouse_id' => 'ozon_fbs', 'quantity' => 7], $flat);
        $this->assertNotContains(['sku' => 'sku-1', 'warehouse_id' => 'ozon_fbo', 'quantity' => 2], $flat);
    }

    public function test_ozon_aggregate_inventory_is_used_when_detailed_inventory_is_empty(): void
    {
        $job = new SyncInventoryJob(new SyncLog([
            'marketplace' => 'ozon',
            'integration_id' => 18,
        ]));

        $merge = new ReflectionMethod($job, 'mergeOzonAggregateInventory');
        $merge->setAccessible(true);

        $aggregate = [[
            'sku' => 'sku-1',
            'warehouses' => [[
                'warehouse_id' => 'ozon_fbs',
                'warehouse_name' => 'Ozon FBS',
                'fulfillment_type' => 'FBS',
                'quantity' => 10,
            ]],
        ]];

        $this->assertSame($aggregate, $merge->invoke($job, [], $aggregate));
    }
}
