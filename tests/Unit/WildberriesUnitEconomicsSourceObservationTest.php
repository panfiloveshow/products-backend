<?php

namespace Tests\Unit;

use App\Console\Commands\SyncUnitEconomicsCommand;
use App\Models\Product;
use ReflectionClass;
use Tests\TestCase;

class WildberriesUnitEconomicsSourceObservationTest extends TestCase
{
    public function test_wb_calculation_keeps_actual_observation_times_through_persistence_payload(): void
    {
        $observedAt = '2026-07-15T10:00:00+00:00';
        $product = new Product([
            'sku' => '2038000000001',
            'price' => 1500,
            'characteristics' => [
                'Длина упаковки' => 10,
                'Ширина упаковки' => 20,
                'Высота упаковки' => 30,
            ],
            'wb_data' => [
                'nmID' => 111,
                'subjectID' => 42,
                'dimensions_observed_at' => $observedAt,
            ],
        ]);
        $command = new SyncUnitEconomicsCommand;
        $reflection = new ReflectionClass($command);
        $build = $reflection->getMethod('buildCalculationData');

        $data = $build->invoke(
            $command,
            $product,
            null,
            'wildberries',
            ['wb_acquiring_avg' => 3.5, 'wb_acquiring_observed_at' => $observedAt],
            null,
            null,
            null,
            null,
            null,
            'FBO',
            ['sales_30_days' => 8],
            null,
            [['delivery_coefficient' => 1]],
            null,
            [
                'redemption_rate' => 80,
                'orders_count' => 10,
                'returns_count' => 2,
                'observed_at' => $observedAt,
            ],
            null,
            [42 => ['fbo' => 20]],
        );

        $this->assertSame($observedAt, $data['dimensions_observed_at']);
        $this->assertSame($observedAt, $data['redemption_observed_at']);
        $this->assertSame($observedAt, $data['acquiring_observed_at']);

        $extract = $reflection->getMethod('extractDetailedData');
        $detailed = $extract->invoke($command, $data, [], 'wildberries');

        $this->assertSame($observedAt, $detailed['dimensions_observed_at']);
        $this->assertSame($observedAt, $detailed['redemption_observed_at']);
        $this->assertSame($observedAt, $detailed['acquiring_observed_at']);
    }
}
