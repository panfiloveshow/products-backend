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

        $local = $matrix->resolveClusterLogistics('FBO', 0.2, 500, 'Казань', 'Казань', '2026-06-20');
        $nonLocal = $matrix->resolveClusterLogistics('FBO', 0.2, 500, 'Казань', 'Москва, МО и Дальние регионы', '2026-06-20');

        $this->assertSame(57.0, $local['base_cost']);
        $this->assertSame(60.0, $nonLocal['base_cost']);
        $this->assertSame(8.0, $nonLocal['non_local_markup_percent']);
        $this->assertSame(0.0, $nonLocal['estimate_markup_percent']);
    }

    public function test_unknown_route_uses_universal_excel_tariff_with_configurable_safety_reserve(): void
    {
        $matrix = new OzonPricingMatrix();

        $estimate = $matrix->resolveClusterLogistics('FBO', 0.2, 500, null, null);

        $this->assertSame('universal', $estimate['tariff_source']);
        $this->assertTrue($estimate['used_universal_tariff']);
        $this->assertSame(56.0, $estimate['unadjusted_base_cost']);
        $this->assertSame(50.0, $estimate['estimate_markup_percent']);
        $this->assertSame(84.0, $estimate['base_cost']);
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

    public function test_non_local_markup_is_cancelled_from_july_9_2026(): void
    {
        $matrix = new OzonPricingMatrix();

        $this->assertTrue($matrix->isNonLocalMarkupActive('2026-07-08'));
        $this->assertFalse($matrix->isNonLocalMarkupActive('2026-07-09'));

        $this->assertSame(8.0, $matrix->resolveDestinationMarkupPercent('Казань', '2026-07-08'));
        $this->assertSame(0.0, $matrix->resolveDestinationMarkupPercent('Казань', '2026-07-09'));
        $this->assertSame(0.0, $matrix->resolveDestinationMarkupPercent('Омск', '2026-08-04'));

        $logistics = $matrix->resolveClusterLogistics('FBO', 0.2, 500, 'Казань', 'Омск', '2026-08-04');
        $this->assertSame(0.0, $logistics['non_local_markup_percent']);
    }

    public function test_locality_commission_discount_starts_only_on_august_30(): void
    {
        $matrix = new OzonPricingMatrix();

        // Обещанные на 31.07 шесть пунктов Ozon отменил 27.07 — до 30.08 скидки нет.
        $this->assertSame(0.0, $matrix->resolveLocalityCommissionDiscountPp('FBO', 'Казань', '2026-07-31'));
        $this->assertSame(0.0, $matrix->resolveLocalityCommissionDiscountPp('FBO', 'Казань', '2026-08-29'));

        // С 30.08 — 3 п.п., и часть кластеров исключена.
        $this->assertSame(3.0, $matrix->resolveLocalityCommissionDiscountPp('FBO', 'Казань', '2026-08-30'));
        $this->assertSame(0.0, $matrix->resolveLocalityCommissionDiscountPp('FBO', 'Москва, МО и Дальние регионы', '2026-08-30'));
        $this->assertSame(0.0, $matrix->resolveLocalityCommissionDiscountPp('FBO', 'Санкт-Петербург и СЗО', '2026-08-30'));
        $this->assertSame(0.0, $matrix->resolveLocalityCommissionDiscountPp('FBO', 'Беларусь', '2026-08-30'));
    }

    public function test_locality_commission_discount_applies_to_fbo_only(): void
    {
        $matrix = new OzonPricingMatrix();

        $this->assertSame(3.0, $matrix->resolveLocalityCommissionDiscountPp('FBO', 'Казань', '2026-08-30'));
        $this->assertSame(0.0, $matrix->resolveLocalityCommissionDiscountPp('FBS', 'Казань', '2026-08-30'));
        $this->assertSame(0.0, $matrix->resolveLocalityCommissionDiscountPp('RFBS', 'Казань', '2026-08-30'));
    }

    public function test_official_category_table_applies_from_august_28(): void
    {
        $matrix = new OzonPricingMatrix();

        // Спецусловия Ozon для дешёвых товаров: до 100 ₽ — 20%, 101–300 ₽ — 26%.
        $this->assertSame(20.0, $matrix->resolveCommissionFromOfficialTable('FBO', 'Автозвук', 90));
        $this->assertSame(26.0, $matrix->resolveCommissionFromOfficialTable('FBO', 'Автозвук', 300));
        $this->assertSame(50.0, $matrix->resolveCommissionFromOfficialTable('FBO', 'Автозвук', 1500));

        // Матч по типу товара и по основной категории, регистр и пробелы не важны.
        $this->assertSame(20.0, $matrix->resolveCommissionFromOfficialTable('FBS', '  автозвук  ', 90));
        $this->assertNull($matrix->resolveCommissionFromOfficialTable('FBO', 'Такой категории нет', 500));

        // Товар хранит категорию как «Основная категория > Категория» —
        // берём самую конкретную часть, а не строку целиком.
        $this->assertSame(55.0, $matrix->resolveCommissionFromOfficialTable('FBO', 'Галантерея и аксессуары > Аксессуары', 1500));
        $this->assertSame(50.0, $matrix->resolveCommissionFromOfficialTable('FBO', 'Автотовары > Запчасти для легковых автомобилей', 1500));
        // Если конкретная часть незнакома, откатываемся на основную категорию.
        $this->assertSame(55.0, $matrix->resolveCommissionFromOfficialTable('FBO', 'Галантерея и аксессуары > Неизвестный тип', 1500));

        // До 28.08 расчёт остаётся на прежних ставках, с 28.08 — по таблице.
        $before = $matrix->resolveCommission('FBO', 'Автозвук', 1500, '2026-08-27');
        $after = $matrix->resolveCommission('FBO', 'Автозвук', 1500, '2026-08-28');
        $this->assertNotSame('ozon_category_table', $before['tariff_source']);
        $this->assertSame('ozon_category_table', $after['tariff_source']);
        $this->assertSame(50.0, $after['sales_fee_percent']);
    }

    public function test_exposes_announcement_date_for_current_version(): void
    {
        $matrix = new OzonPricingMatrix();

        $this->assertSame('2026-07-09', $matrix->getVersionForDate('2026-08-01'));
        $this->assertSame('2026-07-08', $matrix->getAnnouncementDateForVersion('2026-07-09'));
        $this->assertSame('2026-06-16', $matrix->getVersionForDate('2026-06-17'));
        $this->assertSame('2026-05-01', $matrix->getAnnouncementDateForVersion('2026-06-16'));
        $this->assertSame('2026-04-06', $matrix->getVersionForDate('2026-04-07'));
        $this->assertSame('2026-02-05', $matrix->getAnnouncementDateForVersion('2026-04-06'));
    }
}
