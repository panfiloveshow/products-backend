<?php

namespace Tests\Unit;

use App\Models\Posting;
use App\Services\Ozon\OzonOrderUnitEconomicsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OzonOrderUnitEconomicsServiceTest extends TestCase
{
    public function test_cancelled_order_takes_priority_over_local_cluster_reason(): void
    {
        $posting = new Posting([
            'status' => Posting::STATUS_CANCELLED,
        ]);

        $method = new ReflectionMethod(OzonOrderUnitEconomicsService::class, 'resolveMarkupDecision');
        $method->setAccessible(true);

        $result = $method->invoke(
            new OzonOrderUnitEconomicsService(),
            $posting,
            'sku-1',
            'Москва, МО и Дальние регионы',
            'Москва, МО и Дальние регионы',
            1000.0
        );

        $this->assertSame([false, 'cancelled_order', 'Надбавка не применяется: заказ отменён', 'confirmed'], $result);
    }
}
