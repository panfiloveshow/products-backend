<?php

namespace App\Services;

use App\Models\UnitEconomics;
use App\Models\Product;
use App\Models\InventoryWarehouse;
use App\Models\Integration;
use App\Domains\Marketplace\MarketplaceFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class UnitEconomicsService
{
    /**
     * Текущий индекс локализации (для Ozon) - устанавливается в syncFromRealData
     */
    private ?array $currentLocalizationIndex = null;
    
    public function calculate(string $marketplace, array $data): array
    {
        $price = $data['price'];
        $costPrice = $data['cost_price'];
        $salesCount = $data['sales_count'] ?? 1;

        $revenue = $price * $salesCount;

        $calculator = match ($marketplace) {
            'wildberries' => $this->calculateWB($data),
            'ozon' => $this->calculateOzon($data),
            'yandex_market', 'yandex' => $this->calculateYandex($data),
            default => throw new \InvalidArgumentException("Unknown marketplace: {$marketplace}"),
        };

        $totalCosts = $costPrice * $salesCount + $calculator['total_fees'];
        $grossProfit = $revenue - $totalCosts;
        $advertisingCost = $data['advertising_cost'] ?? 0;
        
        // Наценка = во сколько раз цена больше себестоимости (коэффициент)
        $markupPercent = $costPrice > 0 ? ($price / $costPrice) : 0;
        
        // Налоги (из входных данных или дефолт)
        $taxPercent = $data['tax_percent'] ?? 6; // УСН 6% по умолчанию
        $vatPercent = $data['vat_percent'] ?? 0; // НДС 0% по умолчанию (УСН без НДС)
        
        // Расчёт налогов от выручки (за единицу)
        $taxAmount = $price * ($taxPercent / 100);
        $vatAmount = $price * ($vatPercent / 100);
        
        // Наша часть % — произвольный процент (по умолчанию 0%)
        $ourSharePercent = $data['our_share_percent'] ?? 0;
        $ourShareAmount = $price * ($ourSharePercent / 100);
        
        // РК % (ДРР) — рекламные расходы в процентах от цены (по умолчанию 0%)
        $drrPercent = $data['drr_percent'] ?? 0;
        $drrAmount = $price * ($drrPercent / 100);
        
        // На РС (На расчётный счёт) = Цена - Комиссии МП - Логистика - РК% - Налоги (БЕЗ себестоимости)
        // Это деньги которые упадут на расчётный счёт от маркетплейса
        $commissionAmount = $calculator['details']['commission_amount'] ?? 0;
        $logisticsCost = $calculator['details']['effective_logistics'] ?? $calculator['details']['delivery_cost'] ?? 0;
        $processingCost = $calculator['details']['processing_cost'] ?? 0; // Обработка отправления (FBS)
        $acquiringAmount = $calculator['details']['acquiring_amount'] ?? 0;
        $storageCost = $calculator['details']['storage_cost'] ?? 0;
        $additionalCommission = $calculator['details']['additional_commission_amount'] ?? 0;
        
        // Затраты МП (без себестоимости)
        $marketplaceCosts = $commissionAmount + $logisticsCost + $processingCost + $acquiringAmount + $storageCost + $additionalCommission;
        
        // На РС = Цена - Затраты МП - РК% - Налоги - НДС - Наша часть%
        $toSettlementAccount = $price - $marketplaceCosts - $drrAmount - $taxAmount - $vatAmount - $ourShareAmount;
        
        // Прибыль = На РС - Себестоимость (за единицу)
        $profitPerUnit = $toSettlementAccount - $costPrice;
        
        // Маржа = (прибыль / цена) × 100 — процент прибыли от цены продажи
        $marginPercent = $price > 0 ? ($profitPerUnit / $price) * 100 : 0;
        
        // ROI = (прибыль / затраты) × 100
        $roiPercent = $totalCosts > 0 ? (($profitPerUnit * $salesCount) / $totalCosts) * 100 : 0;

        return array_merge([
            'revenue' => round($revenue, 2),
            'total_costs' => round($totalCosts, 2),
            'gross_profit' => round($grossProfit, 2),
            'net_profit' => round($profitPerUnit, 2),  // Прибыль = На РС - Себестоимость
            'margin_percent' => round($marginPercent, 2),
            'markup_percent' => round($markupPercent, 2),
            'roi_percent' => round($roiPercent, 2),
            'advertising_cost' => round($advertisingCost, 2),
            'to_settlement_account' => round($toSettlementAccount, 2),
            // Проценты (вводятся вручную, по умолчанию 0%)
            'drr_percent' => round($drrPercent, 2),           // РК %
            'drr_amount' => round($drrAmount, 2),             // РК сумма за единицу
            'our_share_percent' => round($ourSharePercent, 2), // Наша часть %
            'our_share_amount' => round($ourShareAmount, 2),   // Наша часть сумма за единицу
            // Налоги
            'tax_percent' => round($taxPercent, 2),
            'vat_percent' => round($vatPercent, 2),
            'tax_amount' => round($taxAmount, 2),
            'vat_amount' => round($vatAmount, 2),
        ], $calculator['details']);
    }

    /**
     * Расчёт юнит-экономики Wildberries (с 15 сентября 2025)
     * 
     * Схемы работы:
     * - FBO (FBW): товар на складе WB
     * - FBS: товар на своём складе, доставка через WB
     * - DBS: своя доставка
     * 
     * Тарифы логистики (с 15.09.2025):
     * - До 1л: 23-32₽ (зависит от объёма)
     * - Более 1л: 46₽ за первый литр + 14₽ за каждый доп. литр
     * - Обратная логистика FBO: 50₽ за единицу
     * - Обратная логистика FBS на ПВЗ: 128₽ + 9.5₽/л
     */
    private function calculateWB(array $data): array
    {
        $price = $data['price'];
        $costPrice = $data['cost_price'] ?? 0;
        $salesCount = $data['sales_count'] ?? 1;
        // Схемы WB: FBW, FBS, DBS, EDBS, DBW
        $fulfillmentType = strtoupper($data['fulfillment_type'] ?? 'FBW');
        // Поддержка старого названия FBO -> FBW
        if ($fulfillmentType === 'FBO') {
            $fulfillmentType = 'FBW';
        }

        // === ГАБАРИТЫ И ВЕС ===
        // Для WB габариты приходят в см (поля называются _mm для совместимости)
        $lengthCm = $data['length_mm'] ?? 10;
        $widthCm = $data['width_mm'] ?? 10;
        $heightCm = $data['height_mm'] ?? 10;
        $weightG = $data['weight_g'] ?? 500;
        
        // Объём в литрах (см³ -> л = /1000)
        $volumeLiters = $data['volume_liters'] ?? (($lengthCm * $widthCm * $heightCm) / 1000);
        $volumeWeight = $data['volume_weight'] ?? ($volumeLiters / 5);
        $actualWeight = $data['actual_weight'] ?? ($weightG / 1000);

        // === КОМИССИЯ ЗА ПРОДАЖУ (0.5% - 29.5% в зависимости от категории) ===
        $commissionPercent = $data['commission_percent'] ?? $data['wb_commission_percent'] ?? 15;
        // Комиссия ЗА ЕДИНИЦУ (не умножаем на salesCount)
        $commissionAmountPerUnit = $price * $commissionPercent / 100;
        $commissionAmount = $commissionAmountPerUnit * max(1, $salesCount);

        // === ПРОЦЕНТ ВЫКУПА ===
        $redemptionRate = $data['redemption_rate'] ?? 100;
        $returnRate = (100 - $redemptionRate) / 100;

        // === КОЭФФИЦИЕНТЫ ===
        // Коэффициент склада (логистики) — зависит от склада
        $warehouseCoefficient = $data['warehouse_coefficient'] ?? 1.0;
        
        // КТР (коэффициент территориального распределения) — зависит от ИЛ
        $localizationIndex = $data['localization_index'] ?? 70; // ИЛ в процентах
        $ktr = $this->calculateWbKtr($localizationIndex);
        
        // Коэффициент габаритов (штраф за превышение)
        $dimensionsCoefficient = $data['dimensions_coefficient'] ?? 1.0;

        // === РАСЧЁТ ЛОГИСТИКИ ===
        $baseLogisticsCost = $this->calculateWbLogistics($volumeLiters);
        
        // Итоговая логистика = базовая × коэф.склада × КТР × коэф.габаритов
        // Для DBS логистика WB не применяется (своя доставка)
        $logisticsCostPerUnit = 0;
        $logisticsCost = 0;
        
        if ($fulfillmentType !== 'DBS') {
            $logisticsCostPerUnit = $baseLogisticsCost * $warehouseCoefficient * $ktr * $dimensionsCoefficient;
            $logisticsCost = $logisticsCostPerUnit * max(1, $salesCount);
        }

        // === ХРАНЕНИЕ (только FBW) ===
        $storageCost = 0;
        $storageTariff = $data['storage_tariff'] ?? 0.08; // ₽/день/литр
        $storageDays = $data['storage_days'] ?? $data['turnover_days'] ?? 30;
        $storageCoefficient = $data['storage_coefficient'] ?? 1.0;
        
        if ($fulfillmentType === 'FBW') {
            $storageCost = $volumeLiters * $storageTariff * $storageCoefficient * $storageDays * max(1, $salesCount);
        }

        // === ПРИЁМКА ===
        $acceptanceCost = ($data['acceptance_cost'] ?? 0) * max(1, $salesCount);

        // === ОБРАТНАЯ ЛОГИСТИКА (ВОЗВРАТЫ) ===
        // Актуальные тарифы с 15.09.2025
        $returnLogisticsCostPerUnit = 0;
        
        switch ($fulfillmentType) {
            case 'FBW':
                // Обратная логистика FBW: 50₽ за единицу (фиксированно)
                $returnLogisticsCostPerUnit = 50;
                break;
            case 'FBS':
                // Обратная логистика FBS на ПВЗ (с марта 2024):
                // НЕ СГТ: 127.5₽ за первый литр + 8.75₽ за каждый литр сверху
                // СГТ: 120₽ за первый литр + 7₽ за каждый литр сверху
                // Используем тариф для НЕ СГТ (большинство товаров)
                $returnLogisticsCostPerUnit = 127.5 + (max(0, ceil($volumeLiters) - 1) * 8.75);
                break;
            case 'DBS':
                // Своя логистика возврата
                $returnLogisticsCostPerUnit = $data['own_return_cost'] ?? 0;
                break;
        }
        
        // Ожидаемая стоимость возвратов
        $expectedReturnCost = $returnLogisticsCostPerUnit * max(1, $salesCount) * $returnRate;

        // === СПП (Скидка постоянного покупателя) ===
        // WB компенсирует часть СПП, но селлер тоже участвует
        $sppPercent = $data['spp_percent'] ?? 0;
        $sppAmount = ($price * $sppPercent / 100) * max(1, $salesCount);

        // === ШТРАФЫ И УДЕРЖАНИЯ ===
        $penaltyCost = ($data['penalty_cost'] ?? 0) * max(1, $salesCount);

        // === СВОЯ ДОСТАВКА (DBS) ===
        $ownDeliveryCost = 0;
        if ($fulfillmentType === 'DBS') {
            $ownDeliveryCost = ($data['own_delivery_cost'] ?? 200) * max(1, $salesCount);
        }

        // === ИТОГО ЗАТРАТЫ ===
        $totalFees = $commissionAmount 
            + $logisticsCost 
            + $storageCost 
            + $acceptanceCost 
            + $expectedReturnCost
            + $sppAmount
            + $penaltyCost
            + $ownDeliveryCost;

        // === ЭФФЕКТИВНАЯ ЛОГИСТИКА ===
        $deliveryCost = $logisticsCostPerUnit;
        $effectiveLogistics = $deliveryCost + ($returnLogisticsCostPerUnit * $returnRate);
        
        // === НАЦЕНКА (множитель x) ===
        $markupMultiplier = $costPrice > 0 ? round($price / $costPrice, 2) : 0;
        
        // === ЦЕНА ПОКУПАТЕЛЯ (с учётом СПП) ===
        $customerPrice = $price * (1 - $sppPercent / 100);
        
        // === КС (Коэффициент склада) в % и ₽ ===
        // КС% = (warehouseCoefficient - 1) * 100 (показывает надбавку)
        $warehouseCoefficientPercent = ($warehouseCoefficient - 1) * 100;
        // КС₽ = базовая логистика * (коэффициент - 1) — надбавка к логистике
        $warehouseCoefficientAmount = $baseLogisticsCost * ($warehouseCoefficient - 1);
        
        // === ЛОГИСТИКА + КС ===
        $logisticsWithWarehouse = $logisticsCostPerUnit; // Уже включает КС
        
        // === ЭКВАЙРИНГ (WB: 0%) ===
        $acquiringPercent = $data['acquiring_percent'] ?? 0;
        $acquiringAmount = $price * ($acquiringPercent / 100);
        
        // === ИТОГОВЫЙ % РАСХОДОВ ===
        // (Все расходы МП / Цена) * 100
        $totalExpensesPerUnit = ($commissionAmount / max(1, $salesCount)) 
            + $logisticsCostPerUnit 
            + ($storageCost / max(1, $salesCount))
            + ($expectedReturnCost / max(1, $salesCount))
            + ($sppAmount / max(1, $salesCount))
            + $acquiringAmount;
        $totalExpensesPercent = $price > 0 ? ($totalExpensesPerUnit / $price) * 100 : 0;

        return [
            'total_fees' => round($totalFees, 2),
            'details' => [
                // Схема работы
                'fulfillment_type' => $fulfillmentType,
                
                // Габариты (см для WB, г, л)
                'length_mm' => round($lengthCm, 2),
                'width_mm' => round($widthCm, 2),
                'height_mm' => round($heightCm, 2),
                'weight_g' => round($weightG, 0),
                'volume_liters' => round($volumeLiters, 2),
                'volume_weight' => round($volumeWeight, 2),
                'actual_weight' => round($actualWeight, 2),
                
                // Наценка (множитель x)
                'markup_multiplier' => $markupMultiplier,
                
                // Цена покупателя (с СПП)
                'customer_price' => round($customerPrice, 2),
                
                // Комиссия — ЗА ЕДИНИЦУ
                'commission_percent' => round($commissionPercent, 2),
                'commission_amount' => round($commissionAmount / max(1, $salesCount), 2),
                
                // СПП — ЗА ЕДИНИЦУ
                'spp_percent' => round($sppPercent, 2),
                'spp_amount' => round($sppAmount / max(1, $salesCount), 2),
                
                // КС (коэффициент склада) — ЗА ЕДИНИЦУ
                'warehouse_coefficient' => $warehouseCoefficient,
                'warehouse_coefficient_percent' => round($warehouseCoefficientPercent, 2),
                'warehouse_coefficient_amount' => round($warehouseCoefficientAmount, 2),
                
                // Логистика — ЗА ЕДИНИЦУ
                'base_logistics_cost' => round($baseLogisticsCost, 2),
                'localization_index' => $localizationIndex,
                'ktr' => round($ktr, 2),
                'dimensions_coefficient' => $dimensionsCoefficient,
                'logistics_cost' => round($logisticsCost / max(1, $salesCount), 2),
                'logistics_with_warehouse' => round($logisticsWithWarehouse, 2),
                
                // Хранение (FBO) — ЗА ЕДИНИЦУ
                'storage_tariff' => $storageTariff,
                'storage_coefficient' => $storageCoefficient,
                'storage_days' => $storageDays,
                'storage_cost' => round($storageCost / max(1, $salesCount), 2),
                
                // Приёмка — ЗА ЕДИНИЦУ
                'acceptance_cost' => round($acceptanceCost / max(1, $salesCount), 2),
                
                // Возвраты (% выкупа 28д)
                'redemption_rate' => round($redemptionRate, 2),
                'return_logistics_cost' => round($returnLogisticsCostPerUnit, 2),
                'expected_return_cost' => round($expectedReturnCost / max(1, $salesCount), 2),
                
                // Эффективная логистика ЗА ЕДИНИЦУ
                'delivery_cost' => round($deliveryCost, 2),
                'effective_logistics' => round($effectiveLogistics, 2),
                
                // Эквайринг — ЗА ЕДИНИЦУ
                'acquiring_percent' => round($acquiringPercent, 2),
                'acquiring_amount' => round($acquiringAmount, 2),
                
                // Итоговый % расходов
                'total_expenses_percent' => round($totalExpensesPercent, 2),
                
                // Штрафы — ЗА ЕДИНИЦУ
                'penalty_cost' => round($penaltyCost / max(1, $salesCount), 2),
                
                // Своя доставка (DBS) — ЗА ЕДИНИЦУ
                'own_delivery_cost' => round($ownDeliveryCost / max(1, $salesCount), 2),
            ],
        ];
    }

    /**
     * Расчёт базовой логистики WB по объёму (с 15.09.2025)
     * - До 1л: 23-32₽ (зависит от объёма)
     * - Более 1л: 46₽ за первый литр + 14₽ за каждый доп. литр
     */
    private function calculateWbLogistics(float $volumeLiters): float
    {
        if ($volumeLiters <= 0) {
            return 23; // Минимум
        }
        
        // Товары до 1 литра — прогрессивная шкала
        if ($volumeLiters <= 1) {
            if ($volumeLiters <= 0.2) return 23;
            if ($volumeLiters <= 0.4) return 26;
            if ($volumeLiters <= 0.6) return 29;
            if ($volumeLiters <= 0.8) return 30;
            return 32;
        }
        
        // Товары более 1 литра: 46₽ + 14₽ за каждый доп. литр
        $additionalLiters = ceil($volumeLiters) - 1;
        return 46 + ($additionalLiters * 14);
    }

    /**
     * Расчёт КТР (коэффициента территориального распределения) по ИЛ
     * - ИЛ > 75%: КТР < 1 (скидка до 50%)
     * - ИЛ 60-75%: КТР = 1
     * - ИЛ < 60%: КТР > 1 (наценка до 100%)
     */
    private function calculateWbKtr(float $localizationIndex): float
    {
        if ($localizationIndex >= 90) return 0.5;
        if ($localizationIndex >= 85) return 0.6;
        if ($localizationIndex >= 80) return 0.7;
        if ($localizationIndex >= 75) return 0.8;
        if ($localizationIndex >= 70) return 0.9;
        if ($localizationIndex >= 65) return 1.0;
        if ($localizationIndex >= 60) return 1.0;
        if ($localizationIndex >= 55) return 1.2;
        if ($localizationIndex >= 50) return 1.4;
        if ($localizationIndex >= 45) return 1.6;
        if ($localizationIndex >= 40) return 1.8;
        return 2.0; // Максимальная наценка
    }

    /**
     * Пересчитать юнит-экономику для другой схемы работы
     * Берёт базовые данные товара и пересчитывает тарифы для указанной схемы
     * 
     * @param UnitEconomics $item Исходная запись юнит-экономики
     * @param string $targetScheme Целевая схема (FBO, FBS, REALFBS, EXPRESS)
     * @return array Пересчитанные данные
     */
    public function recalculateForScheme(UnitEconomics $item, string $targetScheme): array
    {
        $marketplace = $item->marketplace;
        $targetSchemeUpper = strtoupper($targetScheme);
        
        // Получаем комиссии из ozon_data связанного товара
        $commissionPercent = (float) ($item->commission_percent ?? 15);
        $product = $item->product;
        if ($product && $marketplace === 'ozon') {
            $ozonData = $product->ozon_data ?? [];
            $commissions = $ozonData['commissions'] ?? [];
            
            // Выбираем комиссию по целевой схеме
            $schemeKey = strtolower($targetSchemeUpper);
            if ($schemeKey === 'realfbs' || $schemeKey === 'dbs') {
                $schemeKey = 'rfbs';
            }
            
            if (isset($commissions[$schemeKey]['percent'])) {
                $commissionPercent = (float) $commissions[$schemeKey]['percent'];
            } elseif (isset($commissions['fbo']['percent'])) {
                $commissionPercent = (float) $commissions['fbo']['percent'];
            }
        }
        
        // Собираем базовые данные товара (не зависят от схемы)
        $baseData = [
            'price' => (float) $item->price,
            'cost_price' => (float) $item->cost_price,
            'sales_count' => max(1, (int) $item->sales_count),
            'volume_liters' => (float) ($item->volume_liters ?? 1),
            'volume_weight' => (float) ($item->volume_weight ?? 0.2),
            'actual_weight' => (float) ($item->actual_weight ?? 0.5),
            'redemption_rate' => (float) ($item->redemption_rate ?? 100),
            'avg_delivery_time_hours' => (int) ($item->avg_delivery_time_hours ?? 29),
            'turnover_days' => (int) ($item->turnover_days ?? 30),
            // Ручные проценты (сохраняем)
            'drr_percent' => (float) ($item->drr_percent ?? 0),
            'our_share_percent' => (float) ($item->our_share_percent ?? 0),
            'tax_percent' => (float) ($item->tax_percent ?? 6),
            'vat_percent' => (float) ($item->vat_percent ?? 0),
            // Комиссия из ozon_data (реальные данные API)
            'commission_percent' => $commissionPercent,
            'acquiring_percent' => (float) ($item->acquiring_percent ?? 1.5),
            // Для realFBS
            'own_delivery_cost' => (float) ($item->own_delivery_cost ?? 200),
            'ozon_compensation' => (float) ($item->ozon_compensation ?? 0),
            // Целевая схема
            'fulfillment_type' => $targetSchemeUpper,
        ];
        
        // Пересчитываем
        $calculated = $this->calculate($marketplace, $baseData);
        
        // Габариты из связанного товара
        $dimensions = $item->dimensions;
        
        // Добавляем исходные данные для фронтенда
        return array_merge($calculated, [
            'id' => $item->id,
            'sku' => $item->sku,
            'product_name' => $item->product_name,
            'product_id' => $item->product_id,
            'integration_id' => $item->integration_id,
            'price' => $baseData['price'],
            'cost_price' => $baseData['cost_price'],
            'sales_count' => $baseData['sales_count'],
            'fulfillment_type' => $targetScheme,
            'is_actual_scheme' => $item->fulfillment_type === strtoupper($targetScheme),
            'original_scheme' => $item->fulfillment_type,
            // Габариты (вложенный объект)
            'dimensions' => $dimensions,
            // Габариты (отдельные поля для фронтенда)
            'depth' => $dimensions['length'] ?? null,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
            'weight' => $dimensions['weight'] ?? null,
            'commissions' => $item->commissions,
            'redemption' => $item->redemption,
        ]);
    }

    /**
     * Универсальный расчёт для Ozon (FBO / FBS / realFBS / DBS / Express)
     * Тарифы актуальны с 10 декабря 2025
     * 
     * Формулы по схемам:
     * - FBO: Комиссия + Логистика×Коэффициент + Доп.% + Последняя миля + Хранение + Эквайринг + Возвраты
     * - FBS: Комиссия + Обработка + Логистика + Последняя миля + Эквайринг + Возвраты
     * - realFBS/DBS: Комиссия + Своя логистика - Компенсация Ozon + Эквайринг
     * - Express: Комиссия + Своя логистика + Эквайринг (без коэффициентов)
     */
    private function calculateOzon(array $data): array
    {
        $price = $data['price'];
        $salesCount = $data['sales_count'] ?? 1;
        $fulfillmentType = strtoupper($data['fulfillment_type'] ?? 'FBO');

        // === ГАБАРИТЫ И ВЕС ===
        $volumeLiters = $data['volume_liters'] ?? 1;
        $volumeWeight = $data['volume_weight'] ?? ($volumeLiters / 5);
        $actualWeight = $data['actual_weight'] ?? 0.5;

        // === КОМИССИЯ (из API или дефолт) ===
        $commissionPercent = $data['commission_percent'] 
            ?? ($fulfillmentType === 'FBO' 
                ? ($data['fbo_commission_percent'] ?? 15)
                : ($data['fbs_commission_percent'] ?? 21));

        $commissionAmount = isset($data['commission_value']) && $data['commission_value'] > 0
            ? $data['commission_value']
            : $price * $commissionPercent / 100;

        // === ЭКВАЙРИНГ (до 1.5%) — ЗА ЕДИНИЦУ ===
        $acquiringPercent = $data['acquiring_percent'] ?? 1.5;
        $acquiringAmount = $price * $acquiringPercent / 100;

        // === ПРОЦЕНТ ВЫКУПА ===
        $redemptionRate = $data['redemption_rate'] ?? 100;
        $returnRate = (100 - $redemptionRate) / 100;

        // === СРЕДНЕЕ ВРЕМЯ ДОСТАВКИ И КОЭФФИЦИЕНТЫ (из API или по таблице) ===
        $avgDeliveryTimeHours = $data['avg_delivery_time_hours'] ?? 29;
        
        // Приоритет: коэффициенты из API Ozon > расчёт по таблице
        $apiLogisticsCoefficient = $data['localization_index'] ?? null;
        $apiAdditionalPercent = $data['localization_additional_percent'] ?? null;

        // === РАСЧЁТ ПО СХЕМЕ РАБОТЫ ===
        $baseLogisticsCost = 0;
        $logisticsCoefficient = 1.0;
        $additionalCommissionPercent = 0;
        $additionalCommissionAmount = 0;
        $logisticsWithCoefficient = 0;
        $logisticsCost = 0;
        $processingCost = 0;
        $lastMileCost = 0;
        $storageCost = 0;
        $returnLogisticsCost = 0;
        $returnProcessingCost = 0;
        $expectedReturnCost = 0;
        $ownDeliveryCost = 0;
        $ozonCompensation = 0;
        $litrobonus = 0;
        $turnoverDays = $data['turnover_days'] ?? 30;

        switch ($fulfillmentType) {
            case 'FBO':
                // Полный расчёт FBO с коэффициентами (декабрь 2025)
                // Передаём коэффициенты из API (если есть) для использования вместо расчёта по таблице
                $fboData = $this->calculateFboLogisticsFull(
                    $price, 
                    $volumeLiters, 
                    $avgDeliveryTimeHours, 
                    $redemptionRate,
                    $apiLogisticsCoefficient,
                    $apiAdditionalPercent
                );
                
                // Все значения ЗА ЕДИНИЦУ (не умножаем на salesCount)
                $baseLogisticsCost = $fboData['base_logistics_cost'];
                $logisticsCoefficient = $fboData['logistics_coefficient'];
                $additionalCommissionPercent = $fboData['additional_commission_percent'];
                $additionalCommissionAmount = $fboData['additional_commission_amount'];
                $logisticsWithCoefficient = $fboData['logistics_with_coefficient'];
                $logisticsCost = $fboData['total_logistics_cost'];
                $lastMileCost = $fboData['last_mile_cost'];
                $returnLogisticsCost = $fboData['return_logistics_cost'];
                $returnProcessingCost = $fboData['return_processing_cost'];
                $expectedReturnCost = $fboData['expected_return_cost'];
                
                // Хранение (зависит от оборачиваемости) — ЗА ЕДИНИЦУ
                $storageCost = $this->calculateFboStorage($volumeLiters, $turnoverDays);
                
                // Литробонусы (компенсация за хранение)
                $litrobonus = $data['litrobonus'] ?? 0;
                $storageCost = max(0, $storageCost - $litrobonus);
                break;

            case 'FBS':
                // Обработка отправления (с 10.12.2025):
                // - СЦ: 20₽ (с доверительной приёмкой 10₽)
                // - ПВЗ/ППЗ: 30₽
                // По умолчанию используем 20₽ (СЦ) — ЗА ЕДИНИЦУ
                $processingCost = $data['processing_cost'] ?? 20;
                
                // Логистика FBS (с 10.12.2025) — ЗА ЕДИНИЦУ
                $baseLogisticsCost = $this->calculateFbsLogistics($volumeLiters, $price);
                $logisticsCost = $baseLogisticsCost;
                
                // Доставка до места выдачи (с 10.12.2025): 10₽ — ЗА ЕДИНИЦУ
                $lastMileCost = 10.00;
                
                // Возвраты по FBS — продавец платит за обратную логистику
                // % выкупа влияет на ожидаемые расходы на возвраты — ЗА ЕДИНИЦУ
                $returnProcessingCost = 20.00; // Обработка возврата
                $returnLogisticsCost = $baseLogisticsCost + $returnProcessingCost;
                $expectedReturnCost = $returnLogisticsCost * $returnRate;
                break;

            case 'RFBS':
            case 'DBS':
            case 'REALFBS':
                // Своя логистика (внешние данные) — ЗА ЕДИНИЦУ
                $ownDeliveryCost = $data['own_delivery_cost'] ?? 200;
                
                // Компенсация от Ozon (до 799₽ за КГТ) — ЗА ЕДИНИЦУ
                $ozonCompensation = $data['ozon_compensation'] ?? 0;
                
                // Возвраты за свой счёт — ЗА ЕДИНИЦУ
                $expectedReturnCost = $ownDeliveryCost * $returnRate;
                break;

            case 'EXPRESS':
                // Express доставка — своя логистика, без коэффициентов времени
                // Комиссия обычно выше (до 25%) — ЗА ЕДИНИЦУ
                $commissionPercent = $data['commission_percent'] ?? $data['express_commission_percent'] ?? 25;
                $commissionAmount = $price * $commissionPercent / 100;
                
                // Своя логистика (курьерская служба) — ЗА ЕДИНИЦУ
                $ownDeliveryCost = $data['own_delivery_cost'] ?? 300;
                
                // Нет компенсации от Ozon для Express
                $ozonCompensation = 0;
                
                // Возвраты за свой счёт — ЗА ЕДИНИЦУ
                $expectedReturnCost = $ownDeliveryCost * $returnRate;
                break;
        }

        // === ИТОГО ЗАТРАТЫ ===
        $totalFeesPerUnit = $commissionAmount 
            + $additionalCommissionAmount
            + $logisticsCost 
            + $processingCost 
            + $lastMileCost 
            + $storageCost 
            + $acquiringAmount 
            + $expectedReturnCost
            + $ownDeliveryCost
            - $ozonCompensation;

        $totalFees = $totalFeesPerUnit * max(0, (int) $salesCount);

        return [
            'total_fees' => round($totalFees, 2),
            'details' => [
                // Схема работы
                'fulfillment_type' => $fulfillmentType,
                
                // Габариты
                'volume_liters' => round($volumeLiters, 2),
                'volume_weight' => round($volumeWeight, 2),
                'actual_weight' => round($actualWeight, 2),
                
                // Комиссия — ЗА ЕДИНИЦУ
                'commission_percent' => round($commissionPercent, 2),
                'commission_amount' => round($commissionAmount, 2),
                
                // Логистика (декабрь 2025) — ВСЕ ЗНАЧЕНИЯ ЗА ЕДИНИЦУ
                'avg_delivery_time_hours' => $avgDeliveryTimeHours,
                'base_logistics_cost' => round($baseLogisticsCost, 2),
                'logistics_coefficient' => $logisticsCoefficient,
                'additional_commission_percent' => $additionalCommissionPercent,
                'additional_commission_amount' => round($additionalCommissionAmount, 2),
                'logistics_with_coefficient' => round($logisticsWithCoefficient, 2),
                'logistics_cost' => round($logisticsCost, 2),
                'processing_cost' => round($processingCost, 2),
                'last_mile_cost' => round($lastMileCost, 2),
                
                // Хранение (FBO) — ЗА ЕДИНИЦУ
                'storage_cost' => round($storageCost, 2),
                'turnover_days' => $turnoverDays,
                'litrobonus' => round($litrobonus, 2),
                
                // Возвраты — ЗА ЕДИНИЦУ
                'redemption_rate' => round($redemptionRate, 2),
                'return_logistics_cost' => round($returnLogisticsCost, 2),
                'return_processing_cost' => round($returnProcessingCost, 2),
                'expected_return_cost' => round($expectedReturnCost, 2),
                
                // Эффективная логистика ЗА ЕДИНИЦУ (прямая + ожидаемые возвраты)
                'delivery_cost' => round($logisticsCost + $lastMileCost, 2),
                'effective_logistics' => round($logisticsCost + $lastMileCost + $expectedReturnCost, 2),
                
                // Эквайринг — ЗА ЕДИНИЦУ
                'acquiring_percent' => $acquiringPercent,
                'acquiring_amount' => round($acquiringAmount, 2),
                
                // Своя логистика (realFBS/DBS) — ЗА ЕДИНИЦУ
                'own_delivery_cost' => round($ownDeliveryCost, 2),
                'ozon_compensation' => round($ozonCompensation, 2),
                
                // === ПОЛЯ *_per_unit ДЛЯ ФРОНТЕНДА (дублируют основные) ===
                'logistics_per_unit' => round($logisticsCost, 2),
                'last_mile_per_unit' => round($lastMileCost, 2),
                'commission_per_unit' => round($commissionAmount, 2),
                'acquiring_per_unit' => round($acquiringAmount, 2),
                'storage_per_unit' => round($storageCost, 2),
                'total_costs_per_unit' => round($totalFeesPerUnit, 2),
                'expected_returns_per_unit' => round($expectedReturnCost, 2),
            ],
        ];
    }

    /**
     * Расчёт базового тарифа логистики FBO (декабрь 2025)
     * Для товаров от 301₽:
     * - 0-1л: 46.77₽
     * - 1-3л: +10.17₽ за литр
     * - 3-190л: +15.25₽ за литр
     * - 190-1000л: +6.10₽ за литр
     * - >1000л: 7859.86₽ фиксировано
     */
    private function calculateFboBaseLogistics(float $volumeLiters, float $price): float
    {
        $volume = ceil($volumeLiters);
        
        if ($volume <= 0) {
            return 0;
        }
        
        // Для товаров до 300₽ — фиксированный тариф 17.28₽/л
        if ($price <= 300) {
            return $volume * 17.28;
        }
        
        // Для товаров свыше 1000л — фиксированная ставка
        if ($volume > 1000) {
            return 7859.86;
        }
        
        $cost = 46.77; // Первый литр
        
        if ($volume > 1) {
            // Литры 2-3 (до 2 доп. литров по 10.17₽)
            $liters_1_3 = min($volume - 1, 2);
            $cost += $liters_1_3 * 10.17;
        }
        
        if ($volume > 3) {
            // Литры 4-190 (до 187 доп. литров по 15.25₽)
            $liters_3_190 = min($volume - 3, 187);
            $cost += $liters_3_190 * 15.25;
        }
        
        if ($volume > 190) {
            // Литры 191-1000 (по 6.10₽)
            $liters_190_1000 = $volume - 190;
            $cost += $liters_190_1000 * 6.10;
        }
        
        return round($cost, 2);
    }

    /**
     * Получить коэффициенты времени доставки FBO (с 07.04.2025)
     */
    private function getDeliveryTimeCoefficients(int $avgDeliveryTimeHours): array
    {
        $coefficients = [
            29 => ['coefficient' => 1.000, 'additional_percent' => 0.00],
            30 => ['coefficient' => 1.050, 'additional_percent' => 0.25],
            31 => ['coefficient' => 1.110, 'additional_percent' => 0.55],
            32 => ['coefficient' => 1.160, 'additional_percent' => 0.80],
            33 => ['coefficient' => 1.230, 'additional_percent' => 1.15],
            34 => ['coefficient' => 1.280, 'additional_percent' => 1.40],
            35 => ['coefficient' => 1.320, 'additional_percent' => 1.60],
            36 => ['coefficient' => 1.360, 'additional_percent' => 1.80],
            37 => ['coefficient' => 1.400, 'additional_percent' => 2.00],
            38 => ['coefficient' => 1.440, 'additional_percent' => 2.20],
            39 => ['coefficient' => 1.480, 'additional_percent' => 2.40],
            40 => ['coefficient' => 1.510, 'additional_percent' => 2.55],
            41 => ['coefficient' => 1.540, 'additional_percent' => 2.70],
            42 => ['coefficient' => 1.570, 'additional_percent' => 2.85],
            43 => ['coefficient' => 1.600, 'additional_percent' => 3.00],
            44 => ['coefficient' => 1.630, 'additional_percent' => 3.15],
            45 => ['coefficient' => 1.660, 'additional_percent' => 3.30],
            46 => ['coefficient' => 1.690, 'additional_percent' => 3.45],
            47 => ['coefficient' => 1.710, 'additional_percent' => 3.55],
            48 => ['coefficient' => 1.730, 'additional_percent' => 3.65],
            49 => ['coefficient' => 1.750, 'additional_percent' => 3.75],
            50 => ['coefficient' => 1.760, 'additional_percent' => 3.80],
            51 => ['coefficient' => 1.770, 'additional_percent' => 3.85],
            52 => ['coefficient' => 1.774, 'additional_percent' => 3.87],
            53 => ['coefficient' => 1.780, 'additional_percent' => 3.90],
            54 => ['coefficient' => 1.784, 'additional_percent' => 3.92],
            55 => ['coefficient' => 1.788, 'additional_percent' => 3.94],
            56 => ['coefficient' => 1.790, 'additional_percent' => 3.95],
            57 => ['coefficient' => 1.792, 'additional_percent' => 3.96],
            58 => ['coefficient' => 1.794, 'additional_percent' => 3.97],
            59 => ['coefficient' => 1.796, 'additional_percent' => 3.98],
            60 => ['coefficient' => 1.798, 'additional_percent' => 3.99],
            61 => ['coefficient' => 1.800, 'additional_percent' => 4.00],
        ];
        
        if ($avgDeliveryTimeHours <= 29) {
            return $coefficients[29];
        }
        
        if ($avgDeliveryTimeHours >= 61) {
            return $coefficients[61];
        }
        
        return $coefficients[$avgDeliveryTimeHours] ?? $coefficients[29];
    }

    /**
     * Полный расчёт логистики FBO (декабрь 2025)
     * 
     * @param float $price Цена товара
     * @param float $volumeLiters Объём в литрах
     * @param int $avgDeliveryTimeHours Среднее время доставки (часы)
     * @param float $redemptionRate Процент выкупа
     * @param float|null $apiCoefficient Коэффициент из API Ozon (приоритет над таблицей)
     * @param float|null $apiAdditionalPercent Доп.% из API Ozon (приоритет над таблицей)
     */
    private function calculateFboLogisticsFull(
        float $price, 
        float $volumeLiters, 
        int $avgDeliveryTimeHours, 
        float $redemptionRate,
        ?float $apiCoefficient = null,
        ?float $apiAdditionalPercent = null
    ): array
    {
        // 1. Базовый тариф по объёму
        $baseLogistics = $this->calculateFboBaseLogistics($volumeLiters, $price);
        
        // 2. Коэффициенты времени доставки
        // Приоритет: данные из API Ozon > расчёт по таблице
        if ($apiCoefficient !== null && $apiCoefficient > 0) {
            // Используем коэффициенты из API Ozon (актуальные данные магазина)
            $coefficient = $apiCoefficient;
            $additionalPercent = $apiAdditionalPercent ?? 0;
        } else {
            // Fallback: расчёт по таблице на основе времени доставки
            $coefficients = $this->getDeliveryTimeCoefficients($avgDeliveryTimeHours);
            $coefficient = $coefficients['coefficient'];
            $additionalPercent = $coefficients['additional_percent'];
        }
        
        // 3. Логистика с коэффициентом (БЕЗ доп.комиссии — она от цены, не от логистики)
        $logisticsWithCoefficient = $baseLogistics * $coefficient;
        $additionalCommission = $price * ($additionalPercent / 100); // Отдельная статья расходов
        
        // 4. Последняя миля (с 01.06.2025 — фиксировано 25₽)
        $lastMile = 25.00;
        
        // 5. Стоимость доставки = логистика с коэфф. + последняя миля (БЕЗ доп.комиссии)
        $deliveryCost = $logisticsWithCoefficient + $lastMile;
        
        // 6. Обратная логистика (базовый тариф без коэффициента + обработка 15₽)
        $returnProcessing = 15.00;
        $returnLogistics = $baseLogistics + $returnProcessing;
        
        // 7. Ожидаемая стоимость возвратов с учётом % выкупа
        $returnRate = (100 - $redemptionRate) / 100;
        $expectedReturnCost = $returnLogistics * $returnRate;
        
        // 8. Эффективная логистика за единицу (прямая + ожидаемые возвраты)
        // Формула: logistics_cost + expected_return_cost
        $effectiveLogistics = $deliveryCost + $expectedReturnCost;
        
        return [
            'base_logistics_cost' => round($baseLogistics, 2),
            'logistics_coefficient' => $coefficient,
            'additional_commission_percent' => $additionalPercent,
            'additional_commission_amount' => round($additionalCommission, 2),
            'logistics_with_coefficient' => round($logisticsWithCoefficient, 2),
            'total_logistics_cost' => round($logisticsWithCoefficient, 2), // Без доп.комиссии
            'last_mile_cost' => $lastMile,
            'delivery_cost' => round($deliveryCost, 2),
            'return_logistics_cost' => round($returnLogistics, 2),
            'return_processing_cost' => $returnProcessing,
            'expected_return_cost' => round($expectedReturnCost, 2),
            'effective_logistics' => round($effectiveLogistics, 2),
        ];
    }

    /**
     * Расчёт логистики FBS по объёмному весу (с 10 декабря 2025)
     * Для товаров от 301₽:
     * - до 1л: 81.34₽
     * - от 1.001 до 2л: 99.64₽ (т.е. +18.30₽ за второй литр)
     * - от 2.001 до 3л: 117.94₽ (т.е. +18.30₽ за третий литр)
     * - от 3.001 до 190л: +23.39₽ за каждый доп. литр
     * - от 190.001 до 1000л: +6.1₽ за каждый доп. литр
     * - свыше 1000л: 9432.87₽ фиксировано
     */
    private function calculateFbsLogistics(float $volumeLiters, float $price): float
    {
        $volume = ceil($volumeLiters);
        
        if ($volume <= 0) {
            return 0;
        }
        
        // Для товаров до 300₽ — используем тариф на литр отгрузки
        if ($price <= 300) {
            return $volume * 1.9; // 1.9₽/л для объёма до 1000л
        }
        
        // Свыше 1000 литров — фиксированная ставка
        if ($volume > 1000) {
            return 9432.87;
        }
        
        // Базовая ставка для первого литра
        $cost = 81.34;
        
        if ($volume > 1) {
            // Литры 2-3 (по 18.30₽ за литр)
            $liters_1_3 = min($volume - 1, 2);
            $cost += $liters_1_3 * 18.30;
        }
        
        if ($volume > 3) {
            // Литры 4-190 (по 23.39₽ за литр)
            $liters_3_190 = min($volume - 3, 187);
            $cost += $liters_3_190 * 23.39;
        }
        
        if ($volume > 190) {
            // Литры 191-1000 (по 6.10₽ за литр)
            $liters_190_1000 = $volume - 190;
            $cost += $liters_190_1000 * 6.10;
        }
        
        return round($cost, 2);
    }

    /**
     * Расчёт стоимости хранения FBO по оборачиваемости
     * - До 160 дней: бесплатно
     * - 161-180 дней: 0.75₽/л
     * - Более 180 дней: 1.5₽/л
     */
    private function calculateFboStorage(float $volumeLiters, int $turnoverDays): float
    {
        if ($turnoverDays <= 160) {
            return 0;
        }
        
        if ($turnoverDays <= 180) {
            return $volumeLiters * 0.75;
        }
        
        return $volumeLiters * 1.5;
    }

    private function calculateYandex(array $data): array
    {
        $price = $data['price'];
        $salesCount = $data['sales_count'] ?? 1;

        $referralFeePercent = $data['referral_fee_percent'] ?? 5;
        $referralFeeAmount = ($price * $referralFeePercent / 100) * $salesCount;

        $fbyPlacement = ($data['fby_placement'] ?? 0) * $salesCount;
        $fbyPickupTransfer = ($data['fby_pickup_transfer'] ?? 0) * $salesCount;
        $fbyDelivery = ($data['fby_delivery'] ?? 50) * $salesCount;
        $fbyMiddleMile = ($data['fby_middle_mile'] ?? 0) * $salesCount;
        $fbyTotal = $fbyPlacement + $fbyPickupTransfer + $fbyDelivery + $fbyMiddleMile;

        $fbsPlacement = ($data['fbs_placement'] ?? 0) * $salesCount;
        $fbsPickupTransfer = ($data['fbs_pickup_transfer'] ?? 0) * $salesCount;
        $fbsDelivery = ($data['fbs_delivery'] ?? 40) * $salesCount;
        $fbsMiddleMile = ($data['fbs_middle_mile'] ?? 0) * $salesCount;
        $fbsTotal = $fbsPlacement + $fbsPickupTransfer + $fbsDelivery + $fbsMiddleMile;

        $totalFees = $referralFeeAmount + $fbyTotal;

        return [
            'total_fees' => $totalFees,
            'details' => [
                'referral_fee_percent' => $referralFeePercent,
                'referral_fee_amount' => round($referralFeeAmount, 2),
                'fby_placement' => round($fbyPlacement, 2),
                'fby_pickup_transfer' => round($fbyPickupTransfer, 2),
                'fby_delivery' => round($fbyDelivery, 2),
                'fby_middle_mile' => round($fbyMiddleMile, 2),
                'fby_total' => round($fbyTotal, 2),
                'fbs_placement' => round($fbsPlacement, 2),
                'fbs_pickup_transfer' => round($fbsPickupTransfer, 2),
                'fbs_delivery' => round($fbsDelivery, 2),
                'fbs_middle_mile' => round($fbsMiddleMile, 2),
                'fbs_total' => round($fbsTotal, 2),
            ],
        ];
    }

    public function createOrUpdate(array $data): UnitEconomics
    {
        $calculated = $this->calculate($data['marketplace'], $data);

        // Уникальный ключ: sku + integration_id (себестоимость привязана к магазину)
        return UnitEconomics::updateOrCreate(
            [
                'sku' => $data['sku'],
                'integration_id' => $data['integration_id'] ?? null,
            ],
            [
                'marketplace' => $data['marketplace'],
                'product_name' => $data['product_name'] ?? null,
                'price' => $data['price'],
                // cost_price исключён из автоматической синхронизации — вводится только вручную
                'sales_count' => $data['sales_count'] ?? 0,
                'revenue' => $calculated['revenue'],
                'total_costs' => $calculated['total_costs'],
                'gross_profit' => $calculated['gross_profit'],
                'net_profit' => $calculated['net_profit'],
                'margin_percent' => $calculated['margin_percent'],
                'roi_percent' => $calculated['roi_percent'],
                'advertising_cost' => $calculated['advertising_cost'] ?? 0,
                'to_settlement_account' => $calculated['to_settlement_account'] ?? null,
                // drr_percent, our_share_percent, tax_percent, vat_percent НЕ перезаписываем — вводятся вручную
                'tax_amount' => $calculated['tax_amount'] ?? null,
                'vat_amount' => $calculated['vat_amount'] ?? null,
                'drr_amount' => $calculated['drr_amount'] ?? null,
                'our_share_amount' => $calculated['our_share_amount'] ?? null,
                'period_start' => $data['period_start'] ?? now()->startOfMonth()->toDateString(),
                'period_end' => $data['period_end'] ?? now()->endOfMonth()->toDateString(),
                'marketplace_data' => $calculated,
            ]
        );
    }

    public function getStats(array $filters = []): array
    {
        $query = UnitEconomics::query();

        if (!empty($filters['marketplace'])) {
            $query->marketplace($filters['marketplace']);
        }

        return [
            'total_revenue' => round($query->sum('revenue'), 2),
            'total_costs' => round($query->sum('total_costs'), 2),
            'total_profit' => round($query->sum('net_profit'), 2),
            'average_margin' => round($query->avg('margin_percent'), 2),
            'average_roi' => round($query->avg('roi_percent'), 2),
            'total_sales' => $query->sum('sales_count'),
            'profitable_products' => (clone $query)->profitable()->count(),
            'unprofitable_products' => (clone $query)->unprofitable()->count(),
        ];
    }

    public function getStatsByMarketplace(string $marketplace): array
    {
        $query = UnitEconomics::marketplace($marketplace);

        return [
            'total_revenue' => round($query->sum('revenue'), 2),
            'total_costs' => round($query->sum('total_costs'), 2),
            'total_profit' => round($query->sum('net_profit'), 2),
            'average_margin' => round($query->avg('margin_percent'), 2),
            'average_roi' => round($query->avg('roi_percent'), 2),
            'total_sales' => $query->sum('sales_count'),
            'profitable_products' => (clone $query)->profitable()->count(),
            'unprofitable_products' => (clone $query)->unprofitable()->count(),
        ];
    }

    public function getOverallStats(): array
    {
        $stats = [];

        foreach (['wildberries', 'ozon', 'yandex_market'] as $marketplace) {
            $stats[$marketplace] = $this->getStatsByMarketplace($marketplace);
        }

        $stats['total'] = [
            'total_revenue' => array_sum(array_column($stats, 'total_revenue')),
            'total_profit' => array_sum(array_column($stats, 'total_profit')),
            'total_sales' => array_sum(array_column($stats, 'total_sales')),
        ];

        return $stats;
    }

    public function getMarketplaceComparison(): array
    {
        $comparison = [];

        foreach (['wildberries', 'ozon', 'yandex_market'] as $marketplace) {
            $stats = $this->getStatsByMarketplace($marketplace);
            $comparison[$marketplace] = [
                'revenue' => $stats['total_revenue'],
                'profit' => $stats['total_profit'],
                'margin' => $stats['average_margin'],
                'roi' => $stats['average_roi'],
                'products' => $stats['profitable_products'] + $stats['unprofitable_products'],
            ];
        }

        return $comparison;
    }

    public function getCommissions(string $marketplace): array
    {
        return Cache::remember("commissions_{$marketplace}", 3600, function () use ($marketplace) {
            return match ($marketplace) {
                'wildberries' => [
                    ['category' => 'Одежда', 'commission' => 15],
                    ['category' => 'Обувь', 'commission' => 15],
                    ['category' => 'Электроника', 'commission' => 10],
                    ['category' => 'Красота', 'commission' => 18],
                    ['category' => 'Дом и сад', 'commission' => 15],
                ],
                'ozon' => [
                    ['category' => 'Одежда', 'fbo' => 15, 'fbs' => 12],
                    ['category' => 'Обувь', 'fbo' => 15, 'fbs' => 12],
                    ['category' => 'Электроника', 'fbo' => 8, 'fbs' => 6],
                    ['category' => 'Красота', 'fbo' => 18, 'fbs' => 15],
                ],
                'yandex_market' => [
                    ['category' => 'Одежда', 'referral_fee' => 6],
                    ['category' => 'Электроника', 'referral_fee' => 4],
                    ['category' => 'Красота', 'referral_fee' => 8],
                ],
                default => [],
            };
        });
    }

    public function getTariffs(string $marketplace): array
    {
        return Cache::remember("tariffs_{$marketplace}", 3600, function () use ($marketplace) {
            return match ($marketplace) {
                'wildberries' => [
                    'storage' => ['per_liter_per_day' => 0.5],
                    'logistics' => ['base' => 50, 'per_kg' => 5],
                    'acceptance' => ['per_item' => 2],
                ],
                'ozon' => [
                    'last_mile' => ['base' => 40],
                    'storage' => ['per_liter_per_day' => 0.4],
                    'acquiring' => ['percent' => 1.5],
                ],
                'yandex_market' => [
                    'fby_delivery' => ['base' => 50],
                    'fbs_delivery' => ['base' => 40],
                    'storage' => ['per_liter_per_day' => 0.3],
                ],
                default => [],
            };
        });
    }

    /**
     * Синхронизация юнит-экономики для интеграции из реальных данных
     * Берет данные из Products и InventoryWarehouse
     */
    public function syncFromRealData(Integration $integration, ?string $periodStart = null, ?string $periodEnd = null, ?array $localizationIndex = null): array
    {
        $periodStart = $periodStart ?? now()->subDays(30)->toDateString();
        $periodEnd = $periodEnd ?? now()->toDateString();
        
        $marketplace = $integration->marketplace;
        $integrationId = $integration->id;
        
        // Сохраняем индекс локализации для использования в buildCalculationData
        $this->currentLocalizationIndex = $localizationIndex;
        
        Log::info("UnitEconomics sync started", [
            'integration_id' => $integrationId,
            'marketplace' => $marketplace,
            'period' => "{$periodStart} - {$periodEnd}"
        ]);
        
        // Получаем товары интеграции
        $products = Product::where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->get();
        
        if ($products->isEmpty()) {
            Log::info("No products found for integration", ['integration_id' => $integrationId]);
            return ['synced' => 0, 'errors' => 0];
        }
        
        // Получаем данные о продажах из InventoryWarehouse
        $inventoryData = InventoryWarehouse::where('marketplace', $marketplace)
            ->whereIn('sku', $products->pluck('sku'))
            ->get()
            ->keyBy('sku');
        
        $synced = 0;
        $errors = 0;
        
        foreach ($products as $product) {
            try {
                $inventory = $inventoryData->get($product->sku);
                
                // Собираем данные для расчета
                $data = $this->buildCalculationData($product, $inventory, $marketplace);
                
                if ($data['price'] <= 0) {
                    continue;
                }
                
                // Рассчитываем юнит-экономику
                $calculated = $this->calculate($marketplace, $data);
                
                // Сохраняем результат
                // Уникальный ключ: sku + integration_id + fulfillment_type
                $fulfillmentType = $calculated['fulfillment_type'] ?? $data['fulfillment_type'] ?? 'FBW';
                
                // Сбрасываем is_actual_scheme для всех записей этого SKU
                UnitEconomics::where('sku', $product->sku)
                    ->where('integration_id', $integrationId)
                    ->update(['is_actual_scheme' => false]);
                
                // Получаем существующую запись для сохранения redemption_rate
                $existingRecord = UnitEconomics::where('sku', $product->sku)
                    ->where('integration_id', $integrationId)
                    ->where('fulfillment_type', $fulfillmentType)
                    ->first();
                
                // Если redemption_rate не задан в data, берём из существующей записи или marketplace_data
                $redemptionRate = $data['redemption_rate'] 
                    ?? $existingRecord?->redemption_rate 
                    ?? $existingRecord?->marketplace_data['redemption_rate'] 
                    ?? 100;
                
                UnitEconomics::updateOrCreate(
                    [
                        'sku' => $product->sku,
                        'integration_id' => $integrationId,
                        'fulfillment_type' => $fulfillmentType,
                    ],
                    [
                        'product_id' => $product->id,
                        'marketplace' => $marketplace,
                        'product_name' => $product->name,
                        'price' => $data['price'],
                        // cost_price исключён — вводится только вручную
                        'sales_count' => $data['sales_count'],
                        'revenue' => $calculated['revenue'],
                        'total_costs' => $calculated['total_costs'],
                        'gross_profit' => $calculated['gross_profit'],
                        'net_profit' => $calculated['net_profit'],
                        'margin_percent' => $calculated['margin_percent'],
                        'roi_percent' => $calculated['roi_percent'],
                        'advertising_cost' => $calculated['advertising_cost'] ?? 0,
                        'to_settlement_account' => $calculated['to_settlement_account'] ?? null,
                        // drr_percent, our_share_percent, tax_percent, vat_percent НЕ перезаписываем — вводятся вручную
                        'tax_amount' => $calculated['tax_amount'] ?? null,
                        'vat_amount' => $calculated['vat_amount'] ?? null,
                        'drr_amount' => $calculated['drr_amount'] ?? null,
                        'our_share_amount' => $calculated['our_share_amount'] ?? null,
                        // Поля WB/Ozon
                        'commission_percent' => $calculated['commission_percent'] ?? null,
                        'commission_amount' => $calculated['commission_amount'] ?? null,
                        'logistics_cost' => $calculated['logistics_cost'] ?? null,
                        'base_logistics_cost' => $calculated['base_logistics_cost'] ?? null,
                        'last_mile_cost' => $calculated['last_mile_cost'] ?? null,
                        'delivery_cost' => $calculated['delivery_cost'] ?? null,
                        'return_logistics_cost' => $calculated['return_logistics_cost'] ?? null,
                        'expected_return_cost' => $calculated['expected_return_cost'] ?? null,
                        'effective_logistics' => $calculated['effective_logistics'] ?? null,
                        'storage_cost' => $calculated['storage_cost'] ?? null,
                        'redemption_rate' => $redemptionRate,
                        'volume_liters' => $calculated['volume_liters'] ?? null,
                        'length_mm' => $calculated['length_mm'] ?? null,
                        'width_mm' => $calculated['width_mm'] ?? null,
                        'height_mm' => $calculated['height_mm'] ?? null,
                        'weight_g' => $calculated['weight_g'] ?? null,
                        'markup_multiplier' => $calculated['markup_multiplier'] ?? null,
                        'customer_price' => $calculated['customer_price'] ?? null,
                        'spp_percent' => $calculated['spp_percent'] ?? 0,
                        'spp_amount' => $calculated['spp_amount'] ?? 0,
                        'warehouse_coefficient_percent' => $calculated['warehouse_coefficient_percent'] ?? 0,
                        'warehouse_coefficient_amount' => $calculated['warehouse_coefficient_amount'] ?? 0,
                        'total_expenses_percent' => $calculated['total_expenses_percent'] ?? null,
                        'is_actual_scheme' => true,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'marketplace_data' => array_merge($calculated, [
                            'has_cost_price' => $data['cost_price'] > 0,
                            'has_sales_data' => $data['sales_count'] > 0,
                            'redemption_rate' => $redemptionRate,
                        ]),
                    ]
                );
                
                $synced++;
                
            } catch (\Exception $e) {
                Log::error("UnitEconomics sync error for SKU", [
                    'sku' => $product->sku,
                    'error' => $e->getMessage()
                ]);
                $errors++;
            }
        }
        
        Log::info("UnitEconomics sync completed", [
            'integration_id' => $integrationId,
            'synced' => $synced,
            'errors' => $errors
        ]);
        
        return ['synced' => $synced, 'errors' => $errors];
    }

    /**
     * Собирает данные для расчета юнит-экономики из Product и InventoryWarehouse
     */
    private function buildCalculationData(Product $product, ?InventoryWarehouse $inventory, string $marketplace): array
    {
        $price = (float) $product->price;
        $costPrice = $inventory?->cost_price ?? 0;
        $salesCount = $inventory?->sales_30_days ?? 0;
        $storageCost = $inventory?->storage_cost_per_month ?? 0;
        
        // Базовые данные
        $data = [
            'sku' => $product->sku,
            'price' => $price,
            'cost_price' => $costPrice,
            'sales_count' => $salesCount,
            'storage_cost' => $storageCost,
        ];
        
        // Специфичные данные по маркетплейсам
        switch ($marketplace) {
            case 'wildberries':
                $wbData = $product->wb_data ?? [];
                
                // Схемы WB: FBW, FBS, DBS, EDBS, DBW (по умолчанию FBW)
                $data['fulfillment_type'] = $product->fulfillment_type 
                    ?? $wbData['fulfillment_type'] 
                    ?? $inventory?->fulfillment_type 
                    ?? 'FBW';
                
                // Комиссия WB (0.5-29.5% в зависимости от категории)
                $data['commission_percent'] = $this->getCommissionByCategory($marketplace, $product->category);
                
                // Габариты из wb_data (в см для WB)
                $data['length_mm'] = $wbData['length'] ?? $wbData['dimensions']['length'] ?? 10;
                $data['width_mm'] = $wbData['width'] ?? $wbData['dimensions']['width'] ?? 10;
                $data['height_mm'] = $wbData['height'] ?? $wbData['dimensions']['height'] ?? 10;
                $data['weight_g'] = $wbData['weight'] ?? $wbData['dimensions']['weight'] ?? 500;
                
                // Объём в литрах
                $data['volume_liters'] = $this->calculateVolume($wbData);
                
                // Тарифы хранения
                $data['storage_tariff'] = 0.08; // ₽/день/литр
                $data['storage_days'] = $inventory?->turnover_days ?? 30;
                
                // % выкупа (по умолчанию 100%)
                $data['redemption_rate'] = $inventory?->redemption_rate ?? 100;
                
                // КС (коэффициент склада) — средний взвешенный по всем складам товара
                $data['warehouse_coefficient'] = $this->getAverageWarehouseCoefficient($product->sku, $marketplace);
                break;
                
            case 'ozon':
                $ozonData = $product->ozon_data ?? [];
                // Приоритет: Product.fulfillment_type > ozon_data.fulfillment_type > inventory > FBO
                $fulfillmentType = $product->fulfillment_type 
                    ?? $ozonData['fulfillment_type'] 
                    ?? $inventory?->fulfillment_type 
                    ?? 'FBO';
                $data['fulfillment_type'] = $fulfillmentType;
                
                // Комиссии из реальных данных API (ozon_data.commissions)
                $commissions = $ozonData['commissions'] ?? [];
                $fboCommission = $commissions['fbo']['percent'] ?? null;
                $fbsCommission = $commissions['fbs']['percent'] ?? null;
                
                // Если комиссии есть в API — используем их, иначе fallback на категорию
                if ($fboCommission !== null || $fbsCommission !== null) {
                    $data['fbo_commission_percent'] = (float) ($fboCommission ?? $fbsCommission ?? 15);
                    $data['fbs_commission_percent'] = (float) ($fbsCommission ?? $fboCommission ?? 15);
                    // Также сохраняем commission_value если есть
                    if ($fulfillmentType === 'FBO' && isset($commissions['fbo']['value'])) {
                        $data['commission_value'] = (float) $commissions['fbo']['value'];
                    } elseif ($fulfillmentType === 'FBS' && isset($commissions['fbs']['value'])) {
                        $data['commission_value'] = (float) $commissions['fbs']['value'];
                    }
                } else {
                    // Fallback на статический маппинг по категории
                    $commission = $this->getCommissionByCategory($marketplace, $product->category);
                    $data['fbo_commission_percent'] = $commission;
                    $data['fbs_commission_percent'] = max(0, $commission - 3);
                }
                
                $data['last_mile_cost'] = 40;
                $data['acquiring_percent'] = 1.5;
                // Габариты из ozon_data если есть
                if (!empty($ozonData['volume_liters'])) {
                    $data['volume_liters'] = (float) $ozonData['volume_liters'];
                }
                
                // % выкупа: берём из inventory или из ozon_data['redemption'] или дефолт
                // НЕ сбрасываем на 100% — это значение обновляется командой unit-economics:sync
                $data['redemption_rate'] = $inventory?->redemption_rate 
                    ?? $ozonData['redemption'] 
                    ?? null; // null означает "не перезаписывать существующее значение"
                
                // Индекс локализации (среднее время доставки) из API или кэша
                if ($this->currentLocalizationIndex) {
                    $data['avg_delivery_time_hours'] = $this->currentLocalizationIndex['average_delivery_time'] ?? 29;
                    $data['localization_index'] = $this->currentLocalizationIndex['tariff_coefficient'] ?? 1.0;
                    $data['localization_additional_percent'] = $this->currentLocalizationIndex['additional_fee_percent'] ?? 0;
                }
                break;
                
            case 'yandex_market':
            case 'yandex':
                $data['referral_fee_percent'] = $this->getCommissionByCategory('yandex_market', $product->category);
                $data['fby_delivery'] = 50;
                $data['fbs_delivery'] = 40;
                break;
        }
        
        return $data;
    }

    /**
     * Получает комиссию по категории товара
     */
    private function getCommissionByCategory(string $marketplace, ?string $category): float
    {
        if (!$category) {
            return match ($marketplace) {
                'wildberries' => 15,
                'ozon' => 15,
                'yandex_market' => 5,
                default => 15,
            };
        }
        
        $categoryLower = mb_strtolower($category);
        
        // Маппинг категорий к комиссиям
        $commissions = match ($marketplace) {
            'wildberries' => [
                'электроника' => 10,
                'одежда' => 15,
                'обувь' => 15,
                'красота' => 18,
                'дом' => 15,
                'детские' => 13,
                'спорт' => 11,
                'книги' => 9,
            ],
            'ozon' => [
                'электроника' => 8,
                'одежда' => 15,
                'обувь' => 15,
                'красота' => 18,
                'дом' => 12,
                'детские' => 13,
                'спорт' => 11,
            ],
            'yandex_market' => [
                'электроника' => 4,
                'одежда' => 6,
                'красота' => 8,
                'дом' => 5,
            ],
            default => [],
        };
        
        foreach ($commissions as $key => $value) {
            if (str_contains($categoryLower, $key)) {
                return $value;
            }
        }
        
        // Дефолтная комиссия
        return match ($marketplace) {
            'wildberries' => 15,
            'ozon' => 15,
            'yandex_market' => 5,
            default => 15,
        };
    }

    /**
     * Рассчитывает объем товара в литрах из габаритов
     */
    private function calculateVolume(array $data): float
    {
        $length = $data['length'] ?? $data['length_mm'] ?? 0;
        $width = $data['width'] ?? $data['width_mm'] ?? 0;
        $height = $data['height'] ?? $data['height_mm'] ?? 0;
        
        if ($length > 0 && $width > 0 && $height > 0) {
            // Если размеры в мм, конвертируем в литры
            if ($length > 100 || $width > 100 || $height > 100) {
                return ($length * $width * $height) / 1000000;
            }
            // Если в см
            return ($length * $width * $height) / 1000;
        }
        
        return 1; // Дефолтный объем 1 литр
    }

    /**
     * Массовое сохранение юнит-экономики (для endpoint /save)
     */
    public function bulkSave(array $items): array
    {
        $updated = 0;
        $errors = [];
        
        DB::beginTransaction();
        
        try {
            foreach ($items as $item) {
                if (empty($item['sku']) || empty($item['marketplace'])) {
                    $errors[] = ['item' => $item, 'error' => 'Missing sku or marketplace'];
                    continue;
                }
                
                $unitEconomics = UnitEconomics::where('sku', $item['sku'])
                    ->where('marketplace', $item['marketplace'])
                    ->latest()
                    ->first();
                
                if (!$unitEconomics) {
                    $errors[] = ['sku' => $item['sku'], 'error' => 'Not found'];
                    continue;
                }
                
                // Обновляем редактируемые поля
                $updateData = [];
                $recalculate = false;
                
                if (isset($item['cost_price'])) {
                    $updateData['cost_price'] = $item['cost_price'];
                    $recalculate = true;
                }
                
                // Обновляем marketplace_data с пользовательскими настройками
                $marketplaceData = $unitEconomics->marketplace_data ?? [];
                
                $editableFields = [
                    'taxes', 'vat', 'length_mm', 'width_mm', 'height_mm', 'weight_g',
                    'advertising', 'our_part', 'spp_percent', 'ks_percent', 
                    'marketing_percent', 'storage', 'tax',
                    // Ручные проценты
                    'drr_percent', 'our_share_percent', 'tax_percent', 'vat_percent'
                ];
                
                foreach ($editableFields as $field) {
                    if (isset($item[$field])) {
                        $marketplaceData[$field] = $item[$field];
                        $recalculate = true;
                    }
                }
                
                if ($recalculate) {
                    // Пересчитываем с новыми данными
                    $calcData = array_merge([
                        'price' => $unitEconomics->price,
                        'cost_price' => $updateData['cost_price'] ?? $unitEconomics->cost_price,
                        'sales_count' => $unitEconomics->sales_count,
                    ], $marketplaceData);
                    
                    $calculated = $this->calculate($unitEconomics->marketplace, $calcData);
                    
                    $updateData = array_merge($updateData, [
                        'revenue' => $calculated['revenue'],
                        'total_costs' => $calculated['total_costs'],
                        'gross_profit' => $calculated['gross_profit'],
                        'net_profit' => $calculated['net_profit'],
                        'margin_percent' => $calculated['margin_percent'],
                        'roi_percent' => $calculated['roi_percent'],
                        'to_settlement_account' => $calculated['to_settlement_account'] ?? null,
                        // Сохраняем ручные проценты напрямую в модель
                        'drr_percent' => $marketplaceData['drr_percent'] ?? $unitEconomics->drr_percent,
                        'our_share_percent' => $marketplaceData['our_share_percent'] ?? $unitEconomics->our_share_percent,
                        'tax_percent' => $marketplaceData['tax_percent'] ?? $unitEconomics->tax_percent,
                        'vat_percent' => $marketplaceData['vat_percent'] ?? $unitEconomics->vat_percent,
                        'drr_amount' => $calculated['drr_amount'] ?? null,
                        'our_share_amount' => $calculated['our_share_amount'] ?? null,
                        'tax_amount' => $calculated['tax_amount'] ?? null,
                        'vat_amount' => $calculated['vat_amount'] ?? null,
                        'marketplace_data' => array_merge($marketplaceData, $calculated),
                    ]);
                }
                
                if (!empty($updateData)) {
                    $unitEconomics->update($updateData);
                    $updated++;
                }
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('UnitEconomics bulkSave error', ['error' => $e->getMessage()]);
            throw $e;
        }
        
        return [
            'success' => true,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * Получает сравнение юнит-экономики по товарам между маркетплейсами
     */
    public function getProductComparison(?int $integrationId = null): array
    {
        $query = UnitEconomics::query()
            ->select('sku', 'product_name', 'marketplace', 'price', 'margin_percent', 'roi_percent', 'net_profit')
            ->whereNotNull('product_name');
        
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }
        
        $items = $query->get();
        
        // Группируем по SKU
        $grouped = [];
        foreach ($items as $item) {
            $sku = $item->sku;
            if (!isset($grouped[$sku])) {
                $grouped[$sku] = [
                    'sku' => $sku,
                    'product_name' => $item->product_name,
                    'marketplaces' => [],
                ];
            }
            
            $grouped[$sku]['marketplaces'][$item->marketplace] = [
                'price' => (float) $item->price,
                'margin_percent' => (float) $item->margin_percent,
                'roi_percent' => (float) $item->roi_percent,
                'profit' => (float) $item->net_profit,
            ];
        }
        
        // Определяем лучший маркетплейс для каждого товара
        $result = [];
        foreach ($grouped as $sku => $data) {
            $bestMarketplace = null;
            $bestMargin = -999;
            $bestRoi = -999;
            
            foreach ($data['marketplaces'] as $mp => $mpData) {
                if ($mpData['margin_percent'] > $bestMargin) {
                    $bestMargin = $mpData['margin_percent'];
                    $bestMarketplace = $mp;
                }
                if ($mpData['roi_percent'] > $bestRoi) {
                    $bestRoi = $mpData['roi_percent'];
                }
            }
            
            $result[] = array_merge($data, [
                'best_marketplace' => $bestMarketplace,
                'best_margin' => $bestMargin,
                'best_roi' => $bestRoi,
            ]);
        }
        
        return $result;
    }

    /**
     * Получает статистику с фильтром по integration_id
     * 
     * Формулы:
     * - total_revenue: Σ (price × sales_count)
     * - total_costs: Σ (total_costs_per_unit × sales_count) — где total_costs_per_unit = total_costs / sales_count
     * - total_profit: Σ (net_profit × sales_count) — net_profit это прибыль за единицу
     * - average_margin: Σ (margin_percent × revenue) / Σ revenue — взвешенная по выручке
     */
    public function getStatsWithFilters(array $filters = []): array
    {
        $query = UnitEconomics::query();

        if (!empty($filters['marketplace'])) {
            $query->marketplace($filters['marketplace']);
        }
        
        if (!empty($filters['integration_id'])) {
            $query->where('integration_id', $filters['integration_id']);
        }
        
        if (!empty($filters['period_start'])) {
            $query->where('period_start', '>=', $filters['period_start']);
        }
        
        if (!empty($filters['period_end'])) {
            $query->where('period_end', '<=', $filters['period_end']);
        }

        // Получаем все записи для точного расчёта
        $items = $query->get();
        
        $totalRevenue = 0;
        $totalCosts = 0;
        $totalProfit = 0;
        $weightedMarginSum = 0;
        $totalSales = 0;
        $profitableCount = 0;
        $unprofitableCount = 0;
        
        foreach ($items as $item) {
            $salesCount = max(1, (int) $item->sales_count);
            $price = (float) $item->price;
            $netProfitPerUnit = (float) $item->net_profit; // Прибыль за единицу
            $marginPercent = (float) $item->margin_percent;
            
            // Выручка = цена × количество продаж
            $itemRevenue = $price * $salesCount;
            $totalRevenue += $itemRevenue;
            
            // Затраты = total_costs (уже рассчитаны как cost_price × sales_count + fees)
            $totalCosts += (float) $item->total_costs;
            
            // Прибыль = net_profit_per_unit × sales_count
            $totalProfit += $netProfitPerUnit * $salesCount;
            
            // Взвешенная маржа: margin × revenue
            $weightedMarginSum += $marginPercent * $itemRevenue;
            
            // Общее количество продаж
            $totalSales += $salesCount;
            
            // Подсчёт прибыльных/убыточных
            if ($netProfitPerUnit > 0) {
                $profitableCount++;
            } else {
                $unprofitableCount++;
            }
        }
        
        // Средняя маржа взвешенная по выручке
        $averageMargin = $totalRevenue > 0 ? $weightedMarginSum / $totalRevenue : 0;
        
        // ROI = (profit / costs) × 100
        $averageRoi = $totalCosts > 0 ? ($totalProfit / $totalCosts) * 100 : 0;

        return [
            'total_revenue' => round($totalRevenue, 2),
            'total_costs' => round($totalCosts, 2),
            'total_profit' => round($totalProfit, 2),
            'average_margin' => round($averageMargin, 2),
            'average_roi' => round($averageRoi, 2),
            'total_sales' => $totalSales,
            'profitable_products' => $profitableCount,
            'unprofitable_products' => $unprofitableCount,
        ];
    }

    /**
     * Получает средний взвешенный КС (коэффициент склада) по всем складам товара
     * 
     * КС влияет на всю логистику WB: логистика = базовая × КС × КТР × коэф.габаритов
     * 
     * @param string $sku SKU товара
     * @param string $marketplace Маркетплейс
     * @return float Средний КС (1.0 = 100%, 1.4 = 140%)
     */
    private function getAverageWarehouseCoefficient(string $sku, string $marketplace): float
    {
        $warehouses = InventoryWarehouse::where('sku', $sku)
            ->where('marketplace', $marketplace)
            ->get(['warehouse_coefficient', 'quantity']);
        
        if ($warehouses->isEmpty()) {
            return 1.0; // По умолчанию 100%
        }
        
        // Склады с остатками — взвешенное среднее
        $warehousesWithStock = $warehouses->filter(fn($w) => $w->quantity > 0);
        $totalQuantity = $warehousesWithStock->sum('quantity');
        
        if ($totalQuantity > 0) {
            $weightedSum = 0;
            foreach ($warehousesWithStock as $wh) {
                $coef = (float) ($wh->warehouse_coefficient ?? 1.0);
                $weightedSum += $coef * $wh->quantity;
            }
            return $weightedSum / $totalQuantity;
        }
        
        // Нет остатков — простое среднее по всем складам
        return $warehouses->avg(fn($w) => (float) ($w->warehouse_coefficient ?? 1.0)) ?? 1.0;
    }
}
