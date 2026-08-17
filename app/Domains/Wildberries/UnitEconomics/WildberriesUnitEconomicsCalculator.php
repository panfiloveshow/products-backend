<?php

namespace App\Domains\Wildberries\UnitEconomics;

use App\Domains\UnitEconomics\Contracts\UnitEconomicsCalculatorInterface;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\CostBreakdown;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;
use App\Domains\Wildberries\Tariffs\CommissionCalculator;
use App\Domains\Wildberries\Tariffs\WildberriesTariffs;

/**
 * Калькулятор юнит-экономики для Wildberries
 *
 * Расчёт всех полей:
 * - Схема, Объём, Габариты, Вес — из API
 * - Себестоимость, СПП%, %выкупа, ДРР%, Налог% — редактируемые
 * - Остальные — рассчитываемые
 */
class WildberriesUnitEconomicsCalculator implements UnitEconomicsCalculatorInterface
{
    private WildberriesTariffs $tariffs;

    private CommissionCalculator $commissions;

    public function __construct()
    {
        $this->tariffs = new WildberriesTariffs;
        $this->commissions = new CommissionCalculator;
    }

    /**
     * Рассчитать юнит-экономику
     *
     * @param  CalculationInput  $input  Входные данные
     * @param  array  $options  Дополнительные параметры (spp_percent, warehouse_coef и т.д.)
     */
    public function calculate(CalculationInput $input, array $options = []): UnitEconomicsResult
    {
        $price = $input->price;
        $volumeInLiters = $input->getVolumeInLiters();
        $weight = $input->weight;
        $scheme = strtoupper($input->fulfillmentType);
        $usesOwnDelivery = in_array($scheme, ['DBS', 'EDBS'], true);

        // === РЕДАКТИРУЕМЫЕ ПОЛЯ ===
        $sppPercent = $options['spp_percent'] ?? $input->sppPercent ?? 0; // СПП, %
        // КС (коэффициент склада) — множитель логистики (1.0 = 100%, 1.4 = 140%)
        // Приоритет: options > input > 1.0 (по умолчанию)
        $warehouseCoef = $options['warehouse_coefficient'] ?? $input->warehouseCoefficient ?? 1.0;
        $warehouseCoefPercent = $warehouseCoef * 100; // Для отображения КС: 1.4 = 140%
        // ИЛ отключён WB с 15.08.2026 для НОВЫХ поставок (новость от 14.08.2026),
        // но на уже отгруженных FBW-остатках действует до конца 60/90-дневной
        // фиксации тарифа склада. WB в отчётах не отдаёт, с какой поставки товар,
        // поэтому переходный режим: ИЛ применяем только на складских схемах
        // (FBO/FBW); на FBS он не действовал и раньше (заказы-исключения, КТР=1).
        // ponytail: выпилить ИЛ полностью после ~15.11.2026 (конец фиксаций).
        $localizationIndex = in_array($scheme, ['FBO', 'FBW'], true)
            ? ($options['localization_index'] ?? $input->localizationIndex ?? 1.0)
            : 1.0;
        // ИРП отключён WB с 13.07.2026 (новость WB Partners от 08.07.2026): индекс
        // исключён из формулы логистики, действует формула до 23.03.2026.
        // Сохранённые/ручные значения игнорируем; поля в выдаче остаются нулями.
        $salesDistributionIndexPercent = 0.0;
        $redemptionRate = $input->redemptionRate ?? 100; // % выкупа
        $drrPercent = $input->drrPercent ?? 0; // ДРР, %
        $taxPercent = $input->taxPercent ?? 0; // Налог, %
        $costPrice = $input->costPrice ?? 0; // Себестоимость

        // === РАССЧИТЫВАЕМЫЕ ПОЛЯ ===

        // Наценка, x = цена / себестоимость
        $markupMultiplier = $costPrice > 0 ? round($price / $costPrice, 2) : 0;

        // СПП на WB финансирует маркетплейс, а не продавец: он НЕ уменьшает выручку.
        // Поэтому выручка, комиссия и сумма к перечислению считаются от действующей
        // цены ($price). Цена покупателя информационная: действующая цена продавца
        // × (1 - СПП%). oldPrice — зачёркнутая цена до скидки продавца и к базе
        // СПП не относится.
        $sppBasePrice = max(0.0, (float) $price);
        $customerPrice = $sppBasePrice > 0 ? $sppBasePrice * (1 - $sppPercent / 100) : $price;

        // Комиссия маркетплейса — от действующей цены (СПП её не уменьшает)
        $commissionRate = $input->commissionRate
            ?? $this->commissions->getCommissionRate($input->categoryId ?? 'default');
        $commission = $price * ($commissionRate / 100);

        // СПП, ₽ — абсолютная скидка покупателю (от цены до СПП), информационно
        $sppAmount = max(0.0, $sppBasePrice - $customerPrice);

        // Тарифная логистика WB. В официальных box tariffs base/liter уже приходят
        // с учётом boxDeliveryCoefExpr / boxDeliveryMarketplaceCoefExpr.
        $tariffLogistics = $usesOwnDelivery
            ? ($input->ownDeliveryCost ?? 0)
            : $this->tariffs->calculateLogisticsCost(
                $input->fulfillmentType,
                $volumeInLiters,
                $weight,
                [
                    'own_delivery_cost' => $input->ownDeliveryCost ?? 0,
                    'tariff_breakdown' => $input->tariffBreakdown,
                ]
            );

        $officialWarehouseCoef = $this->resolveOfficialWarehouseCoefficient($scheme, $input->tariffBreakdown['box'] ?? null);
        $usesOfficialWarehouseCoef = ! $usesOwnDelivery && $officialWarehouseCoef !== null;
        $manualWarehouseCoef = ($options['warehouse_coefficient_is_manual'] ?? $input->warehouseCoefficientIsManual)
            && ! $usesOwnDelivery;
        // Ручной КС не перетираем коэффициентом из тарифа: на FBS тариф отдаёт один
        // статичный коэффициент склада WB, а менеджер ставит фактический.
        if ($usesOfficialWarehouseCoef && ! $manualWarehouseCoef) {
            $warehouseCoef = $officialWarehouseCoef;
        }
        $warehouseCoefPercent = $warehouseCoef * 100;
        $warehouseCoefForCalculation = $usesOwnDelivery ? 1.0 : $warehouseCoef;
        $localizationIndexForCalculation = $usesOwnDelivery ? 1.0 : $localizationIndex;

        // baseLogistics — логистика до КС и ИЛ. Для официальных тарифов WB
        // восстанавливаем базу делением на КС, зашитый WB в base/liter, — он может
        // отличаться от применяемого (ручной КС), тогда логистика реально меняется.
        $embeddedWarehouseCoef = ($usesOfficialWarehouseCoef && $officialWarehouseCoef > 0)
            ? $officialWarehouseCoef
            : 1.0;
        $baseLogistics = $tariffLogistics / $embeddedWarehouseCoef;

        // КС, ₽ = базовая логистика × (КС - 1) — надбавка к логистике от КС
        $warehouseCoefAmount = $baseLogistics * ($warehouseCoefForCalculation - 1);

        // ИЛ, ₽ = базовая логистика × КС × (ИЛ - 1) — надбавка/скидка от ИЛ
        $localizationAmount = $baseLogistics * $warehouseCoefForCalculation * ($localizationIndexForCalculation - 1);

        $wbDiscountBasePrice = max(0.0, (float) ($input->oldPrice ?? $price));
        $salesDistributionAmount = $usesOwnDelivery
            ? 0.0
            : ($wbDiscountBasePrice * ($salesDistributionIndexPercent / 100));

        // Логистика WB с 15.08.2026: базовая логистика × КС (единый 170% / 100% СГТ)
        // × ИЛ (переходно, только FBO/FBW — см. выше). ИРП отключён с 13.07.2026.
        $logistics = $usesOwnDelivery
            ? $baseLogistics
            : (($baseLogistics * $warehouseCoefForCalculation * $localizationIndexForCalculation) + $salesDistributionAmount);

        // Обратная логистика (возврат) — тариф зависит от схемы: FBS/DBW
        // возвращаются в ПВЗ (25+4/л), складские схемы — обратной магистралью.
        $returnLogistics = in_array($scheme, ['DBS', 'EDBS'], true)
            ? ($input->ownReturnCost ?? 0)
            : $this->tariffs->calculateReturnLogisticsCost($volumeInLiters, $weight, [
                'tariff_breakdown' => $input->tariffBreakdown,
                'scheme' => $scheme,
            ]);

        // Ожидаемые возвраты: WB списывает логистику за КАЖДУЮ поездку к клиенту
        // (включая невыкупленные) плюс обратную логистику за возврат — невыкуп
        // стоит оба плеча. Потери считаем на единицу выкупа, а не на заказ:
        // при выкупе 70% на каждую продажу приходится 0.3/0.7 невыкупа
        // (см. ReturnEconomics::fractionPerSoldUnit — кап 3, как у Ozon).
        $returnFraction = \App\Domains\UnitEconomics\ReturnEconomics::fractionPerSoldUnit(
            max(0.0, (100 - $redemptionRate) / 100)
        );
        $expectedReturnCost = ($logistics + $returnLogistics) * $returnFraction;

        // Эффективная логистика = логистика + ожид.возвраты
        $effectiveLogistics = $logistics + $expectedReturnCost;

        // Хранение (если есть данные о днях хранения)
        $daysInStock = $options['days_in_stock'] ?? 30;
        $storageCost = $input->storageCost ?? $this->tariffs->calculateStorageCost($volumeInLiters, $daysInStock, [
            'tariff_breakdown' => $input->tariffBreakdown,
        ]);
        if (! in_array($scheme, ['FBO', 'FBW'], true)) {
            $storageCost = 0.0;
        }

        // Приёмка: FBO/FBW — поставка на склад WB; FBS — платная приёмка
        // отправлений на СЦ (дефолт ~25 ₽/ед задаёт сборщик входа). На DBS/EDBS/DBW нет.
        $acceptanceCost = in_array($scheme, ['FBO', 'FBW', 'FBS'], true) ? ($input->acceptanceCost ?? 0) : 0.0;
        $penaltyCost = $input->penaltyCost ?? 0;

        $acquiringRate = $options['acquiring_percent'] ?? $input->acquiringPercent ?? 1.5;
        $acquiring = $price * ($acquiringRate / 100);

        // === ИТОГОВЫЕ РАСЧЁТЫ ===

        // Всего затрат (без себестоимости)
        $marketplaceCosts = $commission + $logistics + $expectedReturnCost + $storageCost + $acceptanceCost + $penaltyCost + $acquiring;

        // Всего затрат, % = затраты / цена × 100
        $totalExpensesPercent = $price > 0 ? ($marketplaceCosts / $price) * 100 : 0;

        // ДРР, ₽ = цена × ДРР%
        $drrAmount = $price * ($drrPercent / 100);

        // На р/с = деньги, которые перечисляет маркетплейс: действующая цена −
        // удержания WB (комиссия, логистика, возвраты, хранение, эквайринг) − реклама/ДРР.
        // ДРР WB удерживает из выплаты, поэтому он ВХОДИТ в «На РС» (как у Ozon, см.
        // фронтовый пересчёт WBProductsTable). СПП финансирует WB → база = действующая цена.
        $toSettlementAccount = $price - $marketplaceCosts - $drrAmount;

        // Налог, ₽ = действующая цена × налог%.
        // База налога едина для WB/Ozon/Yandex и не зависит от суммы к перечислению на р/с.
        $taxAmount = $price * ($taxPercent / 100);

        // Чистая прибыль = на р/с − себестоимость − налог (ДРР уже в «На РС»)
        $netProfit = $toSettlementAccount - $costPrice - $taxAmount;

        // Маржа, % = чистая прибыль / цена × 100
        $marginPercent = $price > 0 ? ($netProfit / $price) * 100 : 0;

        // Разбивка расходов
        $costs = new CostBreakdown(
            commission: $commission,
            acquiring: $acquiring,
            logistics: $logistics,
            lastMile: 0,
            processingFee: 0,
            deliveryCost: $logistics,
            storageCost: $storageCost,
            returnLogistics: $returnLogistics,
            returnProcessing: 0,
            expectedReturnCost: $expectedReturnCost,
            costPrice: $costPrice,
            packagingCost: $input->packagingCost ?? 0,
            additionalCosts: $input->additionalCosts ?? 0,
            acceptanceCost: $acceptanceCost,
            penaltyCost: $penaltyCost,
        );

        $totalCosts = $costs->getTotalCosts() + $drrAmount + $taxAmount;

        $result = new UnitEconomicsResult(
            sku: $input->sku,
            marketplace: $this->getMarketplace(),
            fulfillmentType: $input->fulfillmentType,
            price: $price,
            costs: $costs,
            revenue: $price,
            totalCosts: $totalCosts,
            netProfit: $netProfit,
            marginPercent: $marginPercent,
            marginAbsolute: $netProfit,
            commissionPercent: $commissionRate,
            acquiringPercent: $acquiringRate,
            isProfitable: $netProfit > 0,
            hasCostPrice: $costPrice > 0,
            oldPrice: $input->oldPrice,
            isActualScheme: false,
            productName: $input->productName,
            calculatedAt: now()->toIso8601String(),
        );

        // Добавляем WB-специфичные поля через metadata
        $result->metadata = [
            'spp_percent' => $sppPercent,
            'spp_amount' => round($sppAmount, 2),
            'warehouse_coef_percent' => $usesOwnDelivery ? 100.0 : $warehouseCoefPercent,
            'warehouse_coef_amount' => round($warehouseCoefAmount, 2),
            'localization_index' => $localizationIndex,
            'localization_amount' => round($localizationAmount, 2),
            'sales_distribution_index' => $usesOwnDelivery ? 0.0 : round($salesDistributionIndexPercent, 4),
            'sales_distribution_amount' => round($salesDistributionAmount, 2),
            'sales_distribution_price_base' => round($wbDiscountBasePrice, 2),
            'customer_price' => round($customerPrice, 2),
            'markup_multiplier' => $markupMultiplier,
            'base_logistics' => round($baseLogistics, 2),
            'warehouse_coef_included_in_tariff' => $usesOfficialWarehouseCoef,
            'warehouse_coef_is_manual' => (bool) $manualWarehouseCoef,
            'tariff_source' => $input->tariffBreakdown['source'] ?? $input->tariffSource,
            'tariff_effective_from' => $input->tariffBreakdown['effective_date'] ?? $input->tariffEffectiveFrom,
            'tariff_warehouse_name' => $input->tariffBreakdown['warehouse_name'] ?? null,
            'return_logistics' => round($returnLogistics, 2),
            'expected_return_cost' => round($expectedReturnCost, 2),
            'effective_logistics' => round($effectiveLogistics, 2),
            'total_expenses_percent' => round($totalExpensesPercent, 2),
            'to_settlement_account' => round($toSettlementAccount, 2),
            'drr_percent' => $drrPercent,
            'drr_amount' => round($drrAmount, 2),
            'tax_percent' => $taxPercent,
            'tax_amount' => round($taxAmount, 2),
            'redemption_rate' => $redemptionRate,
            'acquiring_percent' => round($acquiringRate, 2),
            'acquiring_amount' => round($acquiring, 2),
            'volume_liters' => round($volumeInLiters, 4),
            'acceptance_cost' => round($acceptanceCost, 2),
            'penalty_cost' => round($penaltyCost, 2),
            'own_delivery_cost' => round($input->ownDeliveryCost ?? 0, 2),
        ];

        return $result;
    }

    private function resolveOfficialWarehouseCoefficient(string $scheme, mixed $boxTariff): ?float
    {
        if (! is_array($boxTariff)) {
            return null;
        }

        $keys = in_array($scheme, ['FBS', 'DBW'], true)
            ? ['delivery_marketplace_coef_percent', 'boxDeliveryMarketplaceCoefExpr']
            : ['delivery_coef_percent', 'boxDeliveryCoefExpr'];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $boxTariff) || $boxTariff[$key] === null || $boxTariff[$key] === '') {
                continue;
            }

            $value = is_string($boxTariff[$key])
                ? str_replace(',', '.', $boxTariff[$key])
                : $boxTariff[$key];

            if (! is_numeric($value)) {
                continue;
            }

            $percent = (float) $value;

            return $percent > 0 ? $percent / 100 : null;
        }

        return null;
    }

    /**
     * Получить код маркетплейса
     */
    public function getMarketplace(): string
    {
        return 'wildberries';
    }

    /**
     * Получить поддерживаемые схемы
     */
    public function getSupportedSchemes(): array
    {
        return ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'];
    }
}
