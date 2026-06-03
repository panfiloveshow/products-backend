<?php

namespace Tests\Unit;

use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use PHPUnit\Framework\TestCase;

class OzonPricingMatrixTest extends TestCase
{
    public function test_resolves_price_segment(): void
    {
        $matrix = new OzonPricingMatrix();

        $this->assertSame('0-100', $matrix->resolvePriceSegment(99));
        $this->assertSame('100.01-300', $matrix->resolvePriceSegment(300));
        $this->assertSame('10000+', $matrix->resolvePriceSegment(12500));
    }

    public function test_resolves_commission_by_category_and_segment(): void
    {
        $matrix = new OzonPricingMatrix();
        $commission = $matrix->resolveCommission('FBO', 'Смартфоны и электроника', 2000);

        $this->assertSame('электроника', $commission['category_key']);
        $this->assertSame('1500.01-5000', $commission['price_segment']);
        $this->assertGreaterThan(0, $commission['sales_fee_percent']);
    }

    public function test_resolves_route_with_repo_fallback_alias(): void
    {
        $matrix = new OzonPricingMatrix();
        $route = $matrix->resolveRoute(null, 'Новосибирск кластер');

        $this->assertSame('cluster_regional', $route['route_key']);
        $this->assertSame('repo_fallback', $route['tariff_source']);
    }

    public function test_resolves_exact_cluster_logistics_from_excel_matrix(): void
    {
        $matrix = new OzonPricingMatrix();

        $local = $matrix->resolveClusterLogistics('FBO', 0.2, 500, 'Казань', 'Казань');
        $nonLocal = $matrix->resolveClusterLogistics('FBO', 0.2, 500, 'Казань', 'Москва, МО и Дальние регионы');

        $this->assertSame(57.0, $local['base_cost']);
        $this->assertSame(60.0, $nonLocal['base_cost']);
        $this->assertSame(8.0, $nonLocal['non_local_markup_percent']);
    }

    public function test_resolves_june_2026_ozon_markup_rules(): void
    {
        $matrix = new OzonPricingMatrix();

        $this->assertSame(0.0, $matrix->resolveDestinationMarkupPercent('Дальний Восток', '2026-06-18'));
        $this->assertSame(8.0, $matrix->resolveDestinationMarkupPercent('Дальний Восток', '2026-06-19'));
        $this->assertSame(0.0, $matrix->resolveDestinationMarkupPercent('Воронеж', '2026-06-16'));
        $this->assertSame(8.0, $matrix->resolveDestinationMarkupPercent('Воронеж', '2026-07-01'));
        $this->assertSame(8.0, $matrix->resolveDestinationMarkupPercent('Ростов', '2026-06-16'));
        $this->assertSame(8.0, $matrix->resolveDestinationMarkupPercent('Новосибирск', '2026-06-16'));
        $this->assertSame(0.0, $matrix->resolveDestinationMarkupPercent('Туркменистан', '2026-06-16'));
    }

    public function test_resolves_new_turkmenistan_destination_from_excel_matrix(): void
    {
        $matrix = new OzonPricingMatrix();

        $route = $matrix->resolveClusterLogistics(
            'FBO',
            0.4,
            500,
            'Москва, МО и Дальние регионы',
            'Туркменистан',
            '2026-06-16'
        );

        $this->assertSame('Туркменистан', $route['destination_cluster']);
        $this->assertSame('official', $route['tariff_source']);
        $this->assertFalse($route['used_universal_tariff']);
        $this->assertSame(0.0, $route['non_local_markup_percent']);
        $this->assertSame(72.0, $route['base_cost']);
    }

    public function test_exposes_announcement_date_for_current_version(): void
    {
        $matrix = new OzonPricingMatrix();

        $this->assertSame('2026-06-16', $matrix->getVersionForDate('2026-06-17'));
        $this->assertSame('2026-05-01', $matrix->getAnnouncementDateForVersion('2026-06-16'));
        $this->assertSame('2026-04-06', $matrix->getVersionForDate('2026-04-07'));
        $this->assertSame('2026-02-05', $matrix->getAnnouncementDateForVersion('2026-04-06'));
    }
}
