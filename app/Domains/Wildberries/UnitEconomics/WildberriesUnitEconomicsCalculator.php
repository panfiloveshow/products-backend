<?php

namespace App\Domains\Wildberries\UnitEconomics;

use App\Domains\UnitEconomics\Contracts\UnitEconomicsCalculatorInterface;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;
use App\Domains\UnitEconomics\DTO\CostBreakdown;
use App\Domains\Wildberries\Tariffs\WildberriesTariffs;
use App\Domains\Wildberries\Tariffs\CommissionCalculator;

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
        $this->tariffs = new WildberriesTariffs();
        $this->commissions = new CommissionCalculator();
    }

    /**
     * Рассчитать юнит-экономику
     * 
     * @param CalculationInput $input Входные данные
     * @param array $options Дополнительные параметры (spp_percent, warehouse_coef и т.д.)
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
        // ИЛ (индекс локализации) — множитель логистики (1.0 = без изменений, < 1.0 = скидка, > 1.0 = наценка)
        $localizationIndex = $options['localization_index'] ?? $input->localizationIndex ?? 1.0;
        $salesDistributionIndexPercent = $this->normalizeSalesDistributionIndexPercent(
            (float) ($options['sales_distribution_index'] ?? $input->salesDistributionIndex ?? 0.0)
        );
        $redemptionRate = $input->redemptionRate ?? 100; // % выкупа
        $drrPercent = $input->drrPercent ?? 0; // ДРР, %
        $taxPercent = $input->taxPercent ?? 6; // Налог, %
        $costPrice = $input->costPrice ?? 0; // Себестоимость

        // === РАССЧИТЫВАЕМЫЕ ПОЛЯ ===
        
        // Наценка, x = цена / себестоимость
        $markupMultiplier = $costPrice > 0 ? round($price / $costPrice, 2) : 0;
        
        // Цена покупателя = цена × (1 - СПП%)
        $customerPrice = $price * (1 - $sppPercent / 100);
        
        // Комиссия маркетплейса
        $commissionRate = $input->commissionRate 
            ?? $this->commissions->getCommissionRate($input->categoryId ?? 'default');
        $commission = $customerPrice * ($commissionRate / 100);
        
        // СПП, ₽ = цена × СПП%
        $sppAmount = $price * ($sppPercent / 100);
        
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
        if ($usesOfficialWarehouseCoef) {
            $warehouseCoef = $officialWarehouseCoef;
        }
        $warehouseCoefPercent = $warehouseCoef * 100;
        $warehouseCoefForCalculation = $usesOwnDelivery ? 1.0 : $warehouseCoef;
        $localizationIndexForCalculation = $usesOwnDelivery ? 1.0 : $localizationIndex;

        // baseLogistics — логистика до КС и ИЛ. Для официальных тарифов WB
        // восстанавливаем базу делением на КС, потому что WB уже включил КС в base/liter.
        $baseLogistics = ($usesOfficialWarehouseCoef && $warehouseCoefForCalculation > 0)
            ? $tariffLogistics / $warehouseCoefForCalculation
            : $tariffLogistics;
        
        // КС, ₽ = базовая логистика × (КС - 1) — надбавка к логистике от КС
        $warehouseCoefAmount = $baseLogistics * ($warehouseCoefForCalculation - 1);
        
        // ИЛ, ₽ = базовая логистика × КС × (ИЛ - 1) — надбавка/скидка от ИЛ
        $localizationAmount = $baseLogistics * $warehouseCoefForCalculation * ($localizationIndexForCalculation - 1);
        
        $wbDiscountBasePrice = max(0.0, (float) ($input->oldPrice ?? $price));
        $salesDistributionAmount = $usesOwnDelivery
            ? 0.0
            : ($wbDiscountBasePrice * ($salesDistributionIndexPercent / 100));

        // Логистика WB с 23.03.2026:
        // (базовая логистика × КС × ИЛ) + цена до скидки WB × ИРП.
        $logistics = $usesOwnDelivery
            ? $baseLogistics
            : (($baseLogistics * $warehouseCoefForCalculation * $localizationIndexForCalculation) + $salesDistributionAmount);

        // Обратная логистика (возврат)
        $returnLogistics = in_array($scheme, ['DBS', 'EDBS'], true)
            ? ($input->ownReturnCost ?? 0)
            : $this->tariffs->calculateReturnLogisticsCost($volumeInLiters, $weight, [
                'tariff_breakdown' => $input->tariffBreakdown,
            ]);
        
        // Ожидаемые возвраты = обр.логистика × (100 - %выкупа) / 100
        $returnRate = (100 - $redemptionRate) / 100;
        $expectedReturnCost = $returnLogistics * $returnRate;
        
        // Эффективная логистика = логистика + ожид.возвраты
        $effectiveLogistics = $logistics + $expectedReturnCost;
        
        // Хранение (если есть данные о днях хранения)
        $daysInStock = $options['days_in_stock'] ?? 30;
        $storageCost = $input->storageCost ?? $this->tariffs->calculateStorageCost($volumeInLiters, $daysInStock);
        if (! in_array($scheme, ['FBO', 'FBW'], true)) {
            $storageCost = 0.0;
        }

        $acceptanceCost = $input->acceptanceCost ?? 0;
        $penaltyCost = $input->penaltyCost ?? 0;

        $acquiringRate = $options['acquiring_percent'] ?? $input->acquiringPercent ?? 1.5;
        $acquiring = $price * ($acquiringRate / 100);

        // === ИТОГОВЫЕ РАСЧЁТЫ ===
        
        // Всего затрат (без себестоимости)
        $marketplaceCosts = $commission + $logistics + $expectedReturnCost + $storageCost + $acceptanceCost + $penaltyCost + $acquiring;
        
        // Всего затрат, % = затраты / цена × 100
        $totalExpensesPercent = $price > 0 ? ($marketplaceCosts / $price) * 100 : 0;
        
        // На р/с = цена покупателя - комиссия - логистика - ожид.возвраты - хранение
        $toSettlementAccount = $customerPrice - $marketplaceCosts;
        
        // ДРР, ₽ = цена × ДРР%
        $drrAmount = $price * ($drrPercent / 100);
        
        // Налог, ₽ = действующая цена × налог%.
        // База налога едина для WB/Ozon/Yandex и не зависит от суммы к перечислению на р/с.
        $taxAmount = $price * ($taxPercent / 100);
        
        // Чистая прибыль = на р/с - себестоимость - ДРР - налог
        $netProfit = $toSettlementAccount - $costPrice - $drrAmount - $taxAmount;
        
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
            revenue: $customerPrice,
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

    private function normalizeSalesDistributionIndexPercent(float $value): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        // Иногда коэффициенты хранятся дробью: 0.0115 = 1.15%.
        // Значения больше 0.05 считаем уже процентами: 1.15 = 1.15%.
        if ($value <= 0.05) {
            return $value * 100;
        }

        return $value;
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
