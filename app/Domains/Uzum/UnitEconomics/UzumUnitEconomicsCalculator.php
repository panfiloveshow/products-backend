<?php

namespace App\Domains\Uzum\UnitEconomics;

use App\Domains\UnitEconomics\Contracts\UnitEconomicsCalculatorInterface;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\CostBreakdown;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;

/**
 * Калькулятор юнит-экономики Uzum Market (индивидуальная модель, НЕ копия Ozon).
 *
 * По тарифам Uzum удержаний всего ДВА: комиссия (% по категории) + логистический
 * сбор (по объёму). Эквайринг, доставка и обработка возвратов УЖЕ включены в них —
 * отдельных статей нет; лог. сбор при возврате возвращается.
 *
 * Прибыль = Цена − Комиссия − Логистический сбор − Себестоимость − Хранение(FBO) − Налог.
 *
 * Источник логистики — finance API (`logisticDeliveryFee`), прокидывается per-unit
 * через CalculationInput::ownDeliveryCost. sellerProfit (эталон Uzum) хранится
 * в строке отдельно (marketplace_data), здесь не пересчитывается.
 */
class UzumUnitEconomicsCalculator implements UnitEconomicsCalculatorInterface
{
    public function getMarketplace(): string
    {
        return 'uzum';
    }

    public function getSupportedSchemes(): array
    {
        return ['FBS', 'FBO', 'DBS'];
    }

    public function calculate(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;
        $scheme = strtoupper($input->fulfillmentType ?: 'FBS');

        // Комиссия (% по категории, per-SKU из API)
        $commissionRate = $input->commissionRate ?? 0.0;
        $commission = $price * ($commissionRate / 100);

        // Логистический сбор Uzum, per unit (из finance API через ownDeliveryCost)
        $logistics = (float) ($input->ownDeliveryCost ?? 0);

        // Платное хранение — только FBO (склад Uzum)
        $storage = $scheme === 'FBO' ? (float) ($input->storageCost ?? 0) : 0.0;

        // Себестоимость / упаковка / прочее
        $costPrice = (float) ($input->costPrice ?? 0);
        $packaging = (float) ($input->packagingCost ?? 0);

        // Налог (UZ: оборотный/НДС) — ручной %
        $taxPercent = (float) ($input->taxPercent ?? 0);
        $taxAmount = $price * ($taxPercent / 100);
        $additional = (float) ($input->additionalCosts ?? 0) + $taxAmount;

        // Эквайринг и возвраты отдельно НЕ считаем — у Uzum они внутри комиссии/логистики.
        $costs = new CostBreakdown(
            commission: $commission,
            acquiring: 0,
            logistics: $logistics,
            lastMile: 0,
            processingFee: 0,
            deliveryCost: $logistics,
            storageCost: $storage,
            returnLogistics: 0,
            returnProcessing: 0,
            expectedReturnCost: 0,
            costPrice: $costPrice,
            packagingCost: $packaging,
            additionalCosts: $additional,
        );

        $totalCosts = $costs->getTotalCosts();
        $netProfit = $price - $totalCosts;
        $marginPercent = $price > 0 ? ($netProfit / $price) * 100 : 0;
        // На расчётный счёт = цена − всё, что удерживает Uzum (комиссия + лог. сбор +
        // платное хранение FBO). БЕЗ себестоимости и налога — это деньги, которые упадут на счёт.
        $toSettlementAccount = $price - $commission - $logistics - $storage;

        $result = new UnitEconomicsResult(
            sku: $input->sku,
            marketplace: $this->getMarketplace(),
            fulfillmentType: $scheme,
            price: $price,
            costs: $costs,
            revenue: $price,
            totalCosts: $totalCosts,
            netProfit: $netProfit,
            marginPercent: $marginPercent,
            marginAbsolute: $netProfit,
            commissionPercent: $commissionRate,
            acquiringPercent: 0,
            isProfitable: $netProfit > 0,
            hasCostPrice: $costPrice > 0,
            oldPrice: $input->oldPrice,
            isActualScheme: false,
            productName: $input->productName,
            calculatedAt: now()->toIso8601String(),
        );

        $result->metadata = [
            'commission_amount' => round($commission, 2),
            'logistics_fee' => round($logistics, 2),
            'storage_cost' => round($storage, 2),
            'tax_amount' => round($taxAmount, 2),
            'redemption_rate' => round((float) ($input->redemptionRate ?? 100), 2),
            'to_settlement_account' => round($toSettlementAccount, 2),
            'currency' => 'UZS',
        ];

        return $result;
    }
}
