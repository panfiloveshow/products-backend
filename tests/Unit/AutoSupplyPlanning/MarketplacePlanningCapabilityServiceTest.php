<?php

namespace Tests\Unit\AutoSupplyPlanning;

use App\Services\AutoSupplyPlanning\MarketplacePlanningCapabilityService;
use PHPUnit\Framework\TestCase;

class MarketplacePlanningCapabilityServiceTest extends TestCase
{
    public function test_ozon_capabilities_are_user_facing_russian_text(): void
    {
        $capabilities = (new MarketplacePlanningCapabilityService())->forMarketplace('ozon');

        $this->assertSame('Ozon', $capabilities['marketplace']);
        $this->assertStringContainsString('предварительный просмотр', $capabilities['planning_flow']);
        $this->assertStringContainsString('Автобронирование не выполняется', $capabilities['autobooking_policy']);
        $this->assertSame('кластеры', $capabilities['storage_point_label']);
        $this->assertStringContainsString('собственный расчёт', $capabilities['api_contracts']['needs']);
        $this->assertSame('marketplace-capabilities-3', $capabilities['capability_version']);
        $this->assertTrue($capabilities['territorial_distribution']['ktr_metric']['supported']);
        $this->assertSame('ktr-4', $capabilities['territorial_distribution']['ktr_metric']['metric_version']);
        $this->assertContains('есть предпросмотр → ручное подтверждение → создание черновика Ozon', $capabilities['implemented_features']);
        $this->assertContains('есть отдельный рейтинг кластеров, которые быстрее закрывают региональный спрос', $capabilities['implemented_features']);
        $this->assertContains('период анализа продаж', $capabilities['request_parameters_ru']);
        $this->assertContains('заказы FBO Ozon', $capabilities['api_inputs_ru']);
        $this->assertContains('на какие кластеры лучше везти', $capabilities['calculation_outputs_ru']);
        $this->assertContains('черновик Ozon создаётся только после безопасного предпросмотра и ручного подтверждения', $capabilities['marketplace_rules_ru']);

        $userFacingText = implode(' ', array_merge(
            [$capabilities['planning_flow'], $capabilities['autobooking_policy']],
            $capabilities['marketplace_rules_ru'],
            $capabilities['implemented_features'],
            $capabilities['partial_features'],
        ));

        $this->assertStringNotContainsString('dry-run preview', $userFacingText);
        $this->assertStringNotContainsString('draft-flow', $userFacingText);
        $this->assertStringNotContainsString('OOS', $userFacingText);
    }

    public function test_wb_capabilities_do_not_promise_autobooking(): void
    {
        $capabilities = (new MarketplacePlanningCapabilityService())->forMarketplace('wildberries');

        $this->assertFalse($capabilities['supports_autobooking']);
        $this->assertFalse($capabilities['supports_draft_creation']);
        $this->assertStringContainsString('не обещаем', $capabilities['autobooking_policy']);
        $this->assertSame('склады', $capabilities['storage_point_label']);
        $this->assertSame('marketplace-capabilities-3', $capabilities['capability_version']);
        $this->assertTrue($capabilities['territorial_distribution']['ktr_metric']['supported']);
        $this->assertSame('КТР — текущий коэффициент территориального распределения', $capabilities['territorial_distribution']['ktr_metric']['label']);
        $this->assertArrayNotHasKey('future_metric', $capabilities['territorial_distribution']);
        $this->assertContains('для A-товаров быстрые склады получают повышенный вес', $capabilities['implemented_features']);
        $this->assertContains('есть отдельный рейтинг складов, которые быстрее закрывают региональный спрос', $capabilities['implemented_features']);
        $this->assertContains('нужные склады', $capabilities['request_parameters_ru']);
        $this->assertContains('коэффициенты приёмки, доставки и хранения', $capabilities['api_inputs_ru']);
        $this->assertContains('на какие склады лучше везти', $capabilities['calculation_outputs_ru']);
        $this->assertContains('WB работает как рекомендации и экспорт, без обещания автобронирования', $capabilities['marketplace_rules_ru']);

        $userFacingText = implode(' ', array_merge(
            [$capabilities['planning_flow'], $capabilities['autobooking_policy']],
            $capabilities['implemented_features'],
            $capabilities['partial_features'],
            $capabilities['limitations'],
        ));

        $this->assertStringNotContainsString('Первая версия', $userFacingText);
        $this->assertStringNotContainsString('пока не реализован', $userFacingText);
        $this->assertStringContainsString('КТР считается по текущему плану', $userFacingText);
    }

    public function test_yandex_capabilities_mark_sku_granularity(): void
    {
        $capabilities = (new MarketplacePlanningCapabilityService())->forMarketplace('yandex');

        $this->assertSame('Яндекс Маркет', $capabilities['marketplace']);
        $this->assertSame('SKU', $capabilities['demand_granularity']);
        $this->assertStringContainsString('без автоматического создания FBY-заявки', $capabilities['planning_flow']);
        $this->assertFalse($capabilities['territorial_distribution']['ktr_metric']['supported']);
        $this->assertContains('КТР и складское территориальное ранжирование не включены', $capabilities['partial_features']);
        $this->assertContains('спрос маркируется как SKU-гранулярность', $capabilities['marketplace_rules_ru']);
    }
}
