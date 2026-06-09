<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\LocalizationIndexService;
use Tests\TestCase;

class LocalizationIndexExclusionsTest extends TestCase
{
    public function test_aggregate_indices_applies_fbs_and_oversized_exclusions(): void
    {
        $service = new LocalizationIndexService();

        $salesByRegion = [
            // A: FBW-only, доля локализации 40% → КТР 1.30, КРП 2.10
            '100' => ['total' => 10, 'local' => 4, 'excluded_fbs' => 0],
            // B: FBS 60% > 35% → весь артикул исключение (КТР 1.00, КРП 0)
            '200' => ['total' => 10, 'local' => 5, 'excluded_fbs' => 6],
            // C: КГТ/СГТ → весь артикул исключение независимо от локализации
            '300' => ['total' => 5, 'local' => 5, 'excluded_fbs' => 0],
            // D: FBS 20% ≤ 35% → зачётных 8, локализация 100% → КТР 0.50; FBS по 1.00
            '400' => ['total' => 10, 'local' => 8, 'excluded_fbs' => 2],
        ];

        $oversizedNmIds = ['300' => true];

        $result = $service->aggregateIndices($salesByRegion, $oversizedNmIds);

        // Всего заказов учтено (включая исключения): 10 + 10 + 5 + 10 = 35
        $this->assertSame(35, $result['total_orders']);

        $byArticle = $result['ktr_by_article'];

        // A — обычный расчёт по таблице
        $this->assertEqualsWithDelta(40.0, $byArticle['100']['localization_rate'], 0.001);
        $this->assertEqualsWithDelta(1.30, $byArticle['100']['ktr'], 0.001);
        $this->assertEqualsWithDelta(2.10, $byArticle['100']['krp'], 0.001);
        $this->assertFalse($byArticle['100']['fully_excluded']);

        // B — исключён по правилу 35%
        $this->assertTrue($byArticle['200']['fully_excluded']);
        $this->assertFalse($byArticle['200']['is_oversized']);
        $this->assertEqualsWithDelta(1.00, $byArticle['200']['ktr'], 0.001);
        $this->assertEqualsWithDelta(0.00, $byArticle['200']['krp'], 0.001);

        // C — исключён как КГТ/СГТ
        $this->assertTrue($byArticle['300']['is_oversized']);
        $this->assertTrue($byArticle['300']['fully_excluded']);
        $this->assertEqualsWithDelta(1.00, $byArticle['300']['ktr'], 0.001);

        // D — частичные FBS-исключения, расчёт по зачётным заказам
        $this->assertFalse($byArticle['400']['fully_excluded']);
        $this->assertSame(8, $byArticle['400']['counted_orders']);
        $this->assertEqualsWithDelta(100.0, $byArticle['400']['localization_rate'], 0.001);
        $this->assertEqualsWithDelta(0.50, $byArticle['400']['ktr'], 0.001);

        // Средневзвешенный ИЛ:
        // A 10*1.30=13.0; B 10*1.00=10.0; C 5*1.00=5.0; D 8*0.50 + 2*1.00=6.0
        // Σ=34.0 / 35 = 0.9714 → 0.97
        $this->assertEqualsWithDelta(0.97, $result['localization_index'], 0.001);

        // Средневзвешенный ИРП:
        // A 10*2.10=21.0; остальные 0 → 21 / 35 = 0.60
        $this->assertEqualsWithDelta(0.60, $result['sales_distribution_index'], 0.001);
    }

    public function test_no_exclusions_matches_plain_weighted_average(): void
    {
        $service = new LocalizationIndexService();

        // Без FBS и КГТ поведение совпадает со старым (local/total).
        $salesByRegion = [
            '100' => ['total' => 10, 'local' => 8, 'excluded_fbs' => 0], // 80% → 0.80
        ];

        $result = $service->aggregateIndices($salesByRegion, []);

        $this->assertEqualsWithDelta(80.0, $result['ktr_by_article']['100']['localization_rate'], 0.001);
        $this->assertEqualsWithDelta(0.80, $result['localization_index'], 0.001);
    }

    public function test_is_product_oversized_thresholds(): void
    {
        $service = new LocalizationIndexService();

        // Размеры в мм, вес в граммах.
        $this->assertTrue($service->isProductOversized(
            new Product(['depth' => 1300, 'width' => 100, 'height' => 100, 'weight' => 500])
        ), 'одна сторона ≥ 120 см');

        $this->assertTrue($service->isProductOversized(
            new Product(['depth' => 700, 'width' => 700, 'height' => 700, 'weight' => 500])
        ), 'сумма сторон ≥ 200 см');

        $this->assertTrue($service->isProductOversized(
            new Product(['depth' => 100, 'width' => 100, 'height' => 100, 'weight' => 26000])
        ), 'вес ≥ 25 кг');

        $this->assertFalse($service->isProductOversized(
            new Product(['depth' => 100, 'width' => 100, 'height' => 100, 'weight' => 500])
        ), 'мелкогабарит');

        $this->assertFalse($service->isProductOversized(
            new Product(['depth' => null, 'width' => null, 'height' => null, 'weight' => null])
        ), 'нет габаритов — не КГТ');
    }
}
