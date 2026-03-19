<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для управления кэшем юнит-экономики
 * 
 * Отвечает за:
 * - Пересчёт кэша для товаров
 * - Обновление при изменении настроек
 * - Массовый пересчёт при синхронизации
 * 
 * Использует новую доменную архитектуру (app/Domains/)
 */
class UnitEconomicsCacheService
{
    private UnitEconomicsOrchestrator $orchestrator;
    private ?UnitEconomicsService $legacyCalculator;
    /** @var array<int, Integration> */
    private array $integrationCache = [];
    /** @var array<string, ?UnitEconomics> */
    private array $unitEconomicsCache = [];
    /** @var array<string, float> */
    private array $warehouseCoefficientCache = [];
    /** @var array<string, ?UnitEconomicsSettings> */
    private array $settingsCache = [];

    public function __construct(
        UnitEconomicsOrchestrator $orchestrator,
        ?UnitEconomicsService $legacyCalculator = null
    ) {
        $this->orchestrator = $orchestrator;
        $this->legacyCalculator = $legacyCalculator;
    }

    /**
     * Пересчитать кэш для одного товара
     * 
     * Использует реальную схему товара (Product.fulfillment_type):
     * - FBO: только FBO
     * - FBS: только FBS
     * - MIXED: обе схемы (FBO и FBS)
     * - NULL: все схемы маркетплейса (fallback)
     */
    public function recalculateProduct(Product $product): array
    {
        $schemes = $this->getSchemesForProduct($product);
        $results = [];
        
        foreach ($schemes as $scheme) {
            $results[$scheme] = $this->calculateAndCache($product, $scheme);
        }
        
        return $results;
    }
    
    /**
     * Получить схемы для конкретного товара на основе его fulfillment_type
     * 
     * WB схемы: FBO, FBS, DBS, EDBS, DBW
     * Для WB всегда рассчитываем все 5 схем, чтобы продавец мог сравнить
     * 
     * Ozon схемы: FBO, FBS, RFBS, EXPRESS
     * Для Ozon рассчитываем по реальной схеме товара
     */
    private function getSchemesForProduct(Product $product): array
    {
        // Для WB всегда рассчитываем все 5 схем
        if ($product->marketplace === 'wildberries') {
            return ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'];
        }
        
        $productScheme = $product->fulfillment_type;
        
        // Если схема не определена — используем все схемы маркетплейса
        if (empty($productScheme)) {
            return $this->getSchemesForMarketplace($product->marketplace);
        }
        
        // MIXED — товар на разных типах складов, рассчитываем все активные схемы
        if ($productScheme === 'MIXED') {
            return $this->getSchemesForMarketplace($product->marketplace);
        }
        
        // Конкретная схема (FBO, FBS, RFBS, EXPRESS)
        return [strtoupper($productScheme)];
    }

    /**
     * Пересчитать кэш для товара по конкретной схеме
     * Использует новую доменную архитектуру
     */
    public function calculateAndCache(Product $product, string $fulfillmentType): UnitEconomicsCache
    {
        $integrationId = $product->integration_id;
        $marketplace = $product->marketplace;
        $sku = $product->sku;
        
        // Получаем настройки пользователя
        $settings = $this->getSettingsCached($integrationId, $sku);
        
        // Собираем данные для расчёта через новый DTO
        $inputData = $this->prepareCalculationInput($product, $settings, $fulfillmentType);
        $input = CalculationInput::fromArray($inputData);
        
        // Выполняем расчёт через новый оркестратор
        $result = $this->orchestrator->calculate($input);
        
        // Конвертируем результат в формат кэша
        $cacheData = $this->convertResultToCacheData($product, $settings, $result, $inputData);
        
        // Сохраняем в кэш
        $cache = UnitEconomicsCache::updateOrCreate(
            [
                'integration_id' => $integrationId,
                'sku' => $sku,
                'fulfillment_type' => strtoupper($fulfillmentType),
            ],
            $cacheData
        );
        
        return $cache;
    }

    /**
     * Пересчитать кэш для всей интеграции
     * 
     * 1. Удаляет старый кэш для данной интеграции
     * 2. Пересчитывает данные на основе актуальных товаров из products
     * 3. Сохраняет новый кэш в unit_economics_cache
     */
    public function recalculateIntegration(int $integrationId): array
    {
        $integration = $this->getIntegrationCached($integrationId);
        if (!$integration) {
            return ['error' => 'Integration not found'];
        }
        
        // Удаляем старый кэш для данной интеграции
        $deletedCount = UnitEconomicsCache::where('integration_id', $integrationId)->delete();
        Log::info('UnitEconomicsCache cleared for integration', [
            'integration_id' => $integrationId,
            'deleted_count' => $deletedCount,
        ]);
        
        $stats = [
            'total' => 0,
            'success' => 0,
            'errors' => 0,
            'schemes' => [],
        ];
        
        $schemes = $this->getSchemesForMarketplace($integration->marketplace);
        
        Product::where('integration_id', $integrationId)
            ->chunkById(100, function ($products) use (&$stats, $schemes) {
                foreach ($products as $product) {
                    $stats['total']++;
                    
                    try {
                        foreach ($schemes as $scheme) {
                            $this->calculateAndCache($product, $scheme);
                            $stats['schemes'][$scheme] = ($stats['schemes'][$scheme] ?? 0) + 1;
                        }
                        $stats['success']++;
                    } catch (\Exception $e) {
                        $stats['errors']++;
                        Log::error('UnitEconomicsCache recalculate error', [
                            'product_id' => $product->id,
                            'sku' => $product->sku,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        
        Log::info('UnitEconomicsCache recalculated for integration', [
            'integration_id' => $integrationId,
            'stats' => $stats,
        ]);

        $this->forgetStatsCache($integrationId, $integration->marketplace, $schemes);
        
        return $stats;
    }

    /**
     * Обновить кэш при изменении настроек пользователя
     */
    public function onSettingsChanged(int $integrationId, string $sku): void
    {
        $this->forgetSettingsCache($integrationId, $sku);
        $product = Product::where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->first();
        
        if ($product) {
            $this->recalculateProduct($product);
            $this->forgetStatsCache($integrationId, $product->marketplace, $this->getSchemesForProduct($product));
        }
    }

    /**
     * Массовое обновление кэша при изменении настроек
     */
    public function onBulkSettingsChanged(int $integrationId, array $skus): void
    {
        foreach ($skus as $sku) {
            $this->forgetSettingsCache($integrationId, $sku);
        }
        Product::where('integration_id', $integrationId)
            ->whereIn('sku', $skus)
            ->chunk(50, function ($products) {
                foreach ($products as $product) {
                    $this->recalculateProduct($product);
                }
            });

        $integration = $this->getIntegrationCached($integrationId);
        if ($integration) {
            $this->forgetStatsCache($integrationId, $integration->marketplace, $this->getSchemesForMarketplace($integration->marketplace));
        }
    }

    private function prepareCalculationInput(Product $product, ?UnitEconomicsSettings $settings, string $fulfillmentType): array
    {
        $marketplace = $product->marketplace === 'yandex' ? 'yandex_market' : $product->marketplace;

        $marketplaceData = match ($marketplace) {
            'wildberries' => ($product->wb_data ?? []),
            'yandex_market' => ($product->yandex_data ?? []),
            default => ($product->ozon_data ?? []),
        };
        $commissions = $marketplaceData['commissions'] ?? [];
        $redemption = $marketplaceData['redemption'] ?? [];
        $tariffBreakdown = $marketplaceData['tariffs'] ?? [];

        $existingUE = $this->getUnitEconomicsCached($product->integration_id, $product->sku, strtoupper($fulfillmentType));

        $schemeKey = strtolower($fulfillmentType);
        if ($schemeKey === 'realfbs' || $schemeKey === 'dbs') {
            $schemeKey = 'rfbs';
        }

        $commissionPercent = $commissions[$schemeKey]['percent']
            ?? $commissions['fbs']['percent']
            ?? $commissions['fbo']['percent']
            ?? $existingUE?->commission_percent
            ?? 15;

        $defaultRedemptionRate = $marketplace === 'wildberries' ? 80 : 100;
        $redemptionRate = $settings?->redemption_rate_override
            ?? $existingUE?->redemption_rate
            ?? $redemption['redemption_rate']
            ?? $defaultRedemptionRate;

        $integration = $this->getIntegrationCached($product->integration_id);
        $integrationSettings = $integration?->settings ?? [];

        $avgDeliveryTimeHours = $marketplace === 'ozon'
            ? (int) ($integrationSettings['avg_delivery_time_hours'] ?? $existingUE?->avg_delivery_time_hours ?? 29)
            : (int) ($existingUE?->avg_delivery_time_hours ?? 29);

        $deliveryCoefficient = $marketplace === 'ozon'
            ? (float) ($integrationSettings['localization_coefficient'] ?? $existingUE?->logistics_coefficient ?? $this->calculateDeliveryCoefficient($avgDeliveryTimeHours))
            : (float) ($existingUE?->logistics_coefficient ?? 1.0);

        $additionalCommissionPercent = $marketplace === 'ozon'
            ? (float) ($integrationSettings['localization_additional_percent'] ?? $existingUE?->additional_commission_percent ?? 0)
            : (float) ($existingUE?->additional_commission_percent ?? 0);

        $tariffStatus = $marketplace === 'ozon'
            ? (string) ($integrationSettings['localization_tariff_status'] ?? $existingUE?->tariff_status ?? 'UNKNOWN')
            : (string) ($existingUE?->tariff_status ?? 'UNKNOWN');

        $lengthMm = $settings?->length_mm ?? $marketplaceData['length_mm'] ?? $product->depth;
        $widthMm = $settings?->width_mm ?? $marketplaceData['width_mm'] ?? $product->width;
        $heightMm = $settings?->height_mm ?? $marketplaceData['height_mm'] ?? $product->height;
        $weightG = $settings?->weight_g ?? $marketplaceData['weight_g'] ?? $product->weight;

        $lengthCm = $lengthMm !== null ? ((float) $lengthMm / 10) : 0.0;
        $widthCm = $widthMm !== null ? ((float) $widthMm / 10) : 0.0;
        $heightCm = $heightMm !== null ? ((float) $heightMm / 10) : 0.0;
        $weightKg = $weightG !== null ? ((float) $weightG / 1000) : 0.0;

        $costPrice = ($settings?->cost_price && $settings->cost_price > 0)
            ? $settings->cost_price
            : ($existingUE?->cost_price ?? 0);

        $actualPrice = $marketplaceData['actual_price']
            ?? $marketplaceData['marketing_seller_price']
            ?? $commissions['actual_price']
            ?? $existingUE?->price
            ?? $product->price;

        $marketingPrice = (float) ($marketplaceData['marketing_seller_price'] ?? 0);
        if ($marketingPrice > 0 && $marketingPrice < $actualPrice) {
            $actualPrice = $marketingPrice;
        }

        $sppPercent = 0.0;
        $warehouseCoefficient = 1.0;
        $localizationIndex = 1.0;
        if ($marketplace === 'wildberries') {
            $sppPercent = (float) ($settings?->spp_percent ?? $marketplaceData['spp_percent'] ?? 0);
            $warehouseCoefficient = $this->getAverageWarehouseCoefficient($product->sku, $marketplace);
            $localizationIndex = (float) ($integration?->localization_index ?? 1.0);
        }

        $drrPercent = (float) ($settings?->drr_percent ?? $existingUE?->drr_percent ?? 0);
        $ourSharePercent = (float) ($settings?->our_share_percent ?? $existingUE?->our_share_percent ?? 0);
        $taxPercent = (float) ($settings?->tax_percent ?? $existingUE?->tax_percent ?? 6);
        $vatPercent = (float) ($settings?->vat_percent ?? $existingUE?->vat_percent ?? 0);
        $acquiringPercent = (float) ($marketplace === 'wildberries' ? 0 : ($existingUE?->acquiring_percent ?? 1.5));
        $storageCost = (float) ($marketplaceData['storage_cost'] ?? $product->storage_cost ?? $existingUE?->storage_cost ?? 0);
        $ownDeliveryCost = (float) (
            $marketplaceData['own_delivery_cost']
            ?? $integrationSettings['own_delivery_cost']
            ?? $existingUE?->logistics_cost
            ?? 0
        );
        $ownReturnCost = (float) (
            $marketplaceData['own_return_cost']
            ?? $existingUE?->return_logistics_cost
            ?? 0
        );
        $marketplaceCompensation = (float) (
            $marketplaceData['ozon_compensation']
            ?? $marketplaceData['marketplace_compensation']
            ?? 0
        );
        $acceptanceCost = (float) ($marketplaceData['acceptance_cost'] ?? 0);
        $penaltyCost = (float) ($marketplaceData['penalty_cost'] ?? 0);

        return [
            'sku' => $product->sku,
            'integration_id' => $product->integration_id,
            'marketplace' => $marketplace,
            'fulfillment_type' => strtoupper($fulfillmentType),
            'price' => (float) $actualPrice,
            'old_price' => $product->old_price,
            'length' => $lengthCm,
            'width' => $widthCm,
            'height' => $heightCm,
            'weight' => $weightKg,
            'cost_price' => (float) $costPrice,
            'packaging_cost' => 0,
            'additional_costs' => 0,
            'category_id' => $product->category ?? 'default',
            'commission_rate' => (float) $commissionPercent,
            'redemption_rate' => (float) $redemptionRate,
            'delivery_coefficient' => (float) $deliveryCoefficient,
            'warehouse_coefficient' => (float) $warehouseCoefficient,
            'localization_index' => (float) $localizationIndex,
            'spp_percent' => $sppPercent,
            'drr_percent' => $drrPercent,
            'our_share_percent' => $ourSharePercent,
            'tax_percent' => $taxPercent,
            'vat_percent' => $vatPercent,
            'acquiring_percent' => $acquiringPercent,
            'storage_cost' => $storageCost,
            'additional_commission_percent' => $additionalCommissionPercent,
            'own_delivery_cost' => $ownDeliveryCost,
            'own_return_cost' => $ownReturnCost,
            'marketplace_compensation' => $marketplaceCompensation,
            'acceptance_cost' => $acceptanceCost,
            'penalty_cost' => $penaltyCost,
            'tariff_breakdown' => is_array($tariffBreakdown) ? $tariffBreakdown : [],
            'product_name' => $product->name,
            '_extra' => [
                'sales_count' => max(1, (int) ($existingUE?->sales_count ?? $product->sales_30_days ?? 1)),
                'avg_delivery_time_hours' => (int) $avgDeliveryTimeHours,
                'logistics_coefficient' => (float) $deliveryCoefficient,
                'additional_commission_percent' => (float) $additionalCommissionPercent,
                'tariff_status' => $tariffStatus,
                'turnover_days' => (int) ($existingUE?->turnover_days ?? 30),
                'drr_percent' => $drrPercent,
                'our_share_percent' => $ourSharePercent,
                'tax_percent' => $taxPercent,
                'vat_percent' => $vatPercent,
                'acquiring_percent' => $acquiringPercent,
                'is_in_promotion' => $marketplaceData['is_in_promotion'] ?? $commissions['is_in_promotion'] ?? false,
                'promotion_discount' => $marketplaceData['promotion_discount'] ?? $commissions['promotion_discount'] ?? 0,
                'redemption_source' => $existingUE?->redemption_source ?? 'default',
                'orders_count' => $existingUE?->orders_count ?? null,
                'returns_count' => $existingUE?->returns_count ?? null,
                'spp_percent' => $sppPercent,
            ],
        ];
    }

    /**
     * Конвертировать результат расчёта в формат кэша
     */
    private function convertResultToCacheData(
        Product $product, 
        ?UnitEconomicsSettings $settings, 
        \App\Domains\UnitEconomics\DTO\UnitEconomicsResult $result,
        array $inputData
    ): array {
        $costs = $result->costs;
        $extra = $inputData['_extra'] ?? [];
        
        // Рассчитываем дополнительные метрики
        $price = $result->price;
        $drrPercent = $extra['drr_percent'] ?? 0;
        $drrAmount = $price * ($drrPercent / 100);
        $ourSharePercent = $extra['our_share_percent'] ?? 0;
        $ourShareAmount = $price * ($ourSharePercent / 100);
        $taxPercent = $extra['tax_percent'] ?? 6;
        $vatPercent = $extra['vat_percent'] ?? 0;
        $vatAmount = $price * ($vatPercent / 100);
        $metaDrrAmount = array_key_exists('drr_amount', $result->metadata) ? (float) $result->metadata['drr_amount'] : null;
        $metaTaxAmount = array_key_exists('tax_amount', $result->metadata) ? (float) $result->metadata['tax_amount'] : null;
        $metaVatAmount = array_key_exists('vat_amount', $result->metadata) ? (float) $result->metadata['vat_amount'] : null;
        $taxAmount = $metaTaxAmount ?? ($result->netProfit > 0 ? $result->netProfit * ($taxPercent / 100) : 0);
        $effectiveDrrAmount = $metaDrrAmount ?? $drrAmount;
        $effectiveTaxAmount = $metaTaxAmount ?? $taxAmount;
        $effectiveVatAmount = $metaVatAmount ?? $vatAmount;
        $totalCosts = $result->totalCosts
            + $ourShareAmount
            + $effectiveVatAmount
            + ($metaDrrAmount === null ? $drrAmount : 0)
            + ($metaTaxAmount === null ? $taxAmount : 0);
        
        // Корректируем чистую прибыль
        $netProfit = $result->revenue - $totalCosts;
        $marginPercent = $price > 0 ? ($netProfit / $price) * 100 : 0;
        $markupPercent = $costs->costPrice > 0 ? (($netProfit / $costs->costPrice) * 100) : 0;
        $roiPercent = $costs->getProductCosts() > 0 ? (($netProfit / $costs->getProductCosts()) * 100) : 0;
        
        $length = (float) ($inputData['length'] ?? 0);
        $width = (float) ($inputData['width'] ?? 0);
        $height = (float) ($inputData['height'] ?? 0);
        $volumeLiters = ($length > 0 && $width > 0 && $height > 0)
            ? ($length * $width * $height) / 1000
            : null;

        return [
            'product_id' => $product->id,
            'product_name' => $result->productName ?? $product->name,
            'marketplace' => $result->marketplace,
            'price' => $result->price,
            'old_price' => $result->oldPrice ?? $product->old_price,
            'sales_count' => $extra['sales_count'] ?? 0,
            'is_in_promotion' => $extra['is_in_promotion'] ?? false,
            'promotion_discount' => $extra['promotion_discount'] ?? 0,
            'volume_liters' => $volumeLiters,
            'volume_weight' => $product->volume_weight,
            'depth' => $product->depth,
            'width' => $product->width,
            'height' => $product->height,
            'weight' => $product->weight,
            // Комиссия
            'commission_percent' => $result->commissionPercent,
            'commission_amount' => $costs->commission,
            // Логистика (приоритет: _extra из UnitEconomics > costs из расчёта)
            'avg_delivery_time_hours' => $extra['avg_delivery_time_hours'] ?? 29,
            'logistics_coefficient' => $extra['logistics_coefficient'] ?? $costs->deliveryCoefficient ?? 1,
            'additional_commission_percent' => $extra['additional_commission_percent'] ?? $costs->additionalPercent ?? 0,
            'tariff_status' => $extra['tariff_status'] ?? 'UNKNOWN',
            // base_logistics_cost — базовая логистика БЕЗ КС и ИЛ (из metadata для WB)
            'base_logistics_cost' => $result->metadata['base_logistics'] ?? $costs->logistics,
            // logistics_cost — итоговая логистика С учётом КС и ИЛ
            'logistics_cost' => $costs->logistics,
            'last_mile_cost' => $costs->lastMile,
            'processing_cost' => $costs->processingFee,
            'storage_cost' => $costs->storageCost,
            // Возвраты
            'redemption_rate' => $inputData['redemption_rate'] ?? 100,
            'redemption_source' => $extra['redemption_source'] ?? 'default',
            'orders_count' => $extra['orders_count'] ?? null,
            'returns_count' => $extra['returns_count'] ?? null,
            'return_logistics_cost' => $costs->returnLogistics,
            'return_processing_cost' => $costs->returnProcessing,
            'expected_return_cost' => $costs->expectedReturnCost,
            'effective_logistics' => $costs->deliveryCost + $costs->expectedReturnCost,
            // Эквайринг
            'acquiring_percent' => $result->acquiringPercent,
            'acquiring_amount' => $costs->acquiring,
            // Себестоимость и настройки
            'cost_price' => $costs->costPrice,
            'drr_percent' => $drrPercent,
            'drr_amount' => $effectiveDrrAmount,
            'our_share_percent' => $ourSharePercent,
            'our_share_amount' => $ourShareAmount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $effectiveTaxAmount,
            'vat_percent' => $vatPercent,
            'vat_amount' => $effectiveVatAmount,
            // Итоги
            'revenue' => $result->revenue,
            'total_costs' => $totalCosts,
            'gross_profit' => $result->netProfit,
            'net_profit' => $netProfit,
            'to_settlement_account' => $result->metadata['to_settlement_account'] ?? ($result->price - $costs->getMarketplaceCosts()),
            'margin_percent' => $marginPercent,
            'markup_percent' => $markupPercent,
            'roi_percent' => $roiPercent,
            // Метаданные
            'calculated_at' => now(),
            'data_version' => 2, // Новая версия с доменной архитектурой
        ];
    }

    /**
     * Рассчитать коэффициент времени доставки (для Ozon FBO)
     */
    private function calculateDeliveryCoefficient(int $avgDeliveryTimeHours): float
    {
        // Таблица коэффициентов Ozon
        if ($avgDeliveryTimeHours <= 14) return 1.0;
        if ($avgDeliveryTimeHours <= 24) return 1.04;
        if ($avgDeliveryTimeHours <= 36) return 1.12;
        if ($avgDeliveryTimeHours <= 48) return 1.20;
        if ($avgDeliveryTimeHours <= 60) return 1.32;
        if ($avgDeliveryTimeHours <= 72) return 1.40;
        if ($avgDeliveryTimeHours <= 84) return 1.48;
        return 1.80;
    }

    /**
     * Подготовить данные для расчёта (LEGACY - для обратной совместимости)
     * Берём данные из существующей записи UnitEconomics (там актуальные данные после синхронизации)
     * @deprecated Используйте prepareCalculationInput
     */
    private function prepareCalculationData(Product $product, ?UnitEconomicsSettings $settings, string $fulfillmentType): array
    {
        $ozonData = $product->ozon_data ?? [];
        $commissions = $ozonData['commissions'] ?? [];
        $redemption = $ozonData['redemption'] ?? [];
        
        // Получаем существующую запись UnitEconomics (там актуальные данные)
        $existingUE = $this->getUnitEconomicsCached($product->integration_id, $product->sku, null);
        
        // Определяем комиссию по схеме
        // ПРИОРИТЕТ: ozon_data.commissions (актуальные из API товаров) > UnitEconomics > дефолт
        $schemeKey = strtolower($fulfillmentType);
        // Нормализуем ключи схем
        if ($schemeKey === 'realfbs' || $schemeKey === 'dbs') {
            $schemeKey = 'rfbs';
        }
        
        // Берём комиссию для конкретной схемы, fallback на FBS, затем FBO
        $commissionPercent = $commissions[$schemeKey]['percent'] 
            ?? $commissions['fbs']['percent']
            ?? $commissions['fbo']['percent'] 
            ?? $existingUE?->commission_percent
            ?? 15;
        
        // Процент выкупа: переопределение пользователя > UnitEconomics > ozon_data > 100
        $redemptionRate = $settings?->redemption_rate_override 
            ?? $existingUE?->redemption_rate
            ?? $redemption['redemption_rate'] 
            ?? 100;
        
        // Время доставки и коэффициент из UnitEconomics
        $avgDeliveryTimeHours = $existingUE?->avg_delivery_time_hours ?? 29;
        
        // Габариты
        $volumeLiters = $product->volume_liters 
            ?? $existingUE?->volume_liters
            ?? ($product->depth && $product->width && $product->height 
                ? ($product->depth * $product->width * $product->height) / 1000000 
                : 1);
        
        // Себестоимость: настройки пользователя (если > 0) > UnitEconomics > 0
        $costPrice = ($settings?->cost_price && $settings->cost_price > 0) 
            ? $settings->cost_price 
            : ($existingUE?->cost_price ?? 0);
        
        // Актуальная цена с учётом акций (marketing_seller_price):
        // 1. ozon_data['actual_price'] — уже рассчитан с учётом акций
        // 2. ozon_data['marketing_seller_price'] — цена с акцией из API
        // 3. ozon_data['commissions']['actual_price'] — в commissions (старый формат)
        // 4. UnitEconomics->price — там сохраняется актуальная цена
        // 5. Product->price — fallback
        $actualPrice = $ozonData['actual_price'] 
            ?? $ozonData['marketing_seller_price']
            ?? $commissions['actual_price'] 
            ?? $existingUE?->price 
            ?? $product->price;
        
        // Если marketing_seller_price меньше actual_price — используем его (акция)
        $marketingPrice = (float) ($ozonData['marketing_seller_price'] ?? 0);
        if ($marketingPrice > 0 && $marketingPrice < $actualPrice) {
            $actualPrice = $marketingPrice;
        }
        
        return [
            'price' => (float) $actualPrice,
            'cost_price' => (float) $costPrice,
            'sales_count' => max(1, (int) ($existingUE?->sales_count ?? $product->sales_30_days ?? 1)),
            'volume_liters' => (float) $volumeLiters,
            'volume_weight' => (float) ($product->volume_weight ?? $existingUE?->volume_weight ?? $volumeLiters / 5),
            'actual_weight' => (float) (($product->weight ?? 500) / 1000),
            'redemption_rate' => (float) $redemptionRate,
            'avg_delivery_time_hours' => (int) $avgDeliveryTimeHours,
            'turnover_days' => (int) ($existingUE?->turnover_days ?? 30),
            // Настройки пользователя (приоритет: settings > UnitEconomics > default)
            'drr_percent' => (float) ($settings?->drr_percent ?? $existingUE?->drr_percent ?? 0),
            'our_share_percent' => (float) ($settings?->our_share_percent ?? $existingUE?->our_share_percent ?? 0),
            'tax_percent' => (float) ($settings?->tax_percent ?? $existingUE?->tax_percent ?? 6),
            'vat_percent' => (float) ($settings?->vat_percent ?? $existingUE?->vat_percent ?? 0),
            // Комиссия из API
            'commission_percent' => (float) $commissionPercent,
            'acquiring_percent' => (float) ($existingUE?->acquiring_percent ?? 1.5),
            // Схема
            'fulfillment_type' => strtoupper($fulfillmentType),
            // Для realFBS
            'own_delivery_cost' => (float) ($existingUE?->own_delivery_cost ?? 200),
            'ozon_compensation' => (float) ($existingUE?->ozon_compensation ?? 0),
        ];
    }

    /**
     * Подготовить данные для сохранения в кэш
     */
    private function prepareCacheData(Product $product, ?UnitEconomicsSettings $settings, array $calculated, string $fulfillmentType): array
    {
        // Получаем информацию об акциях
        // Приоритет: UnitEconomics (синхронизируется из API) > ozon_data > commissions
        $existingUE = $this->getUnitEconomicsCached($product->integration_id, $product->sku, $fulfillmentType);
        
        $ozonData = $product->ozon_data ?? [];
        $commissions = $ozonData['commissions'] ?? [];
        
        $isInPromotion = $existingUE?->is_in_promotion 
            ?? $ozonData['is_in_promotion'] 
            ?? $commissions['is_in_promotion'] 
            ?? false;
        $promotionDiscount = $existingUE?->promotion_discount 
            ?? $ozonData['promotion_discount'] 
            ?? $commissions['promotion_discount'] 
            ?? 0;
        
        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'marketplace' => $product->marketplace,
            // Базовые данные (price берём из calculated — там актуальная цена с учётом акций)
            'price' => $calculated['price'] ?? $product->price,
            'old_price' => $product->old_price,
            'sales_count' => $calculated['sales_count'] ?? $product->sales_30_days ?? 0,
            // Информация об акциях
            'is_in_promotion' => $isInPromotion,
            'promotion_discount' => $promotionDiscount,
            // Габариты
            'volume_liters' => $product->volume_liters,
            'volume_weight' => $product->volume_weight,
            'depth' => $product->depth,
            'width' => $product->width,
            'height' => $product->height,
            'weight' => $product->weight,
            // Комиссия
            'commission_percent' => $calculated['commission_percent'] ?? 0,
            'commission_amount' => $calculated['commission_amount'] ?? 0,
            // Логистика
            'avg_delivery_time_hours' => $calculated['avg_delivery_time_hours'] ?? 29,
            'logistics_coefficient' => $calculated['logistics_coefficient'] ?? 1,
            'additional_commission_percent' => $calculated['additional_commission_percent'] ?? 0,
            'base_logistics_cost' => $calculated['base_logistics_cost'] ?? 0,
            'logistics_cost' => $calculated['logistics_cost'] ?? 0,
            'last_mile_cost' => $calculated['last_mile_cost'] ?? 0,
            'processing_cost' => $calculated['processing_cost'] ?? 0,
            'storage_cost' => $calculated['storage_cost'] ?? 0,
            // Возвраты
            'redemption_rate' => $calculated['redemption_rate'] ?? 100,
            'return_logistics_cost' => $calculated['return_logistics_cost'] ?? 0,
            'return_processing_cost' => $calculated['return_processing_cost'] ?? 0,
            'expected_return_cost' => $calculated['expected_return_cost'] ?? 0,
            'effective_logistics' => $calculated['effective_logistics'] ?? 0,
            // Эквайринг
            'acquiring_percent' => $calculated['acquiring_percent'] ?? 1.5,
            'acquiring_amount' => $calculated['acquiring_amount'] ?? 0,
            // Настройки пользователя (берём из calculated, там уже учтён приоритет settings > UnitEconomics)
            'cost_price' => $calculated['cost_price'] ?? 0,
            'drr_percent' => $calculated['drr_percent'] ?? 0,
            'drr_amount' => $calculated['drr_amount'] ?? 0,
            'our_share_percent' => $calculated['our_share_percent'] ?? 0,
            'our_share_amount' => $calculated['our_share_amount'] ?? 0,
            'tax_percent' => $calculated['tax_percent'] ?? 6,
            'tax_amount' => $calculated['tax_amount'] ?? 0,
            'vat_percent' => $calculated['vat_percent'] ?? 0,
            'vat_amount' => $calculated['vat_amount'] ?? 0,
            // Итоги
            'revenue' => $calculated['revenue'] ?? 0,
            'total_costs' => $calculated['total_costs'] ?? 0,
            'gross_profit' => $calculated['gross_profit'] ?? 0,
            'net_profit' => $calculated['net_profit'] ?? 0,
            'to_settlement_account' => $calculated['to_settlement_account'] ?? 0,
            'margin_percent' => $calculated['margin_percent'] ?? 0,
            'markup_percent' => $calculated['markup_percent'] ?? 0,
            'roi_percent' => $calculated['roi_percent'] ?? 0,
            // Метаданные
            'calculated_at' => now(),
            'data_version' => 1,
        ];
    }

    /**
     * Получить схемы для маркетплейса
     * 
     * WB схемы:
     * - FBW/FBO: Склад WB (логистика WB, хранение WB)
     * - FBS: Ваш склад, логистика WB
     * - DBS: Своя доставка (логистика продавца)
     * - EDBS: Экспресс своя доставка (логистика продавца)
     * - DBW: Курьер WB от вас (курьер WB забирает у продавца)
     */
    private function getSchemesForMarketplace(string $marketplace): array
    {
        return match ($marketplace) {
            'ozon' => ['FBO', 'FBS', 'RFBS', 'EXPRESS'],
            'wildberries' => ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'],
            default => ['FBO'],
        };
    }

    /**
     * Получить статистику кэша
     */
    public function getCacheStats(int $integrationId): array
    {
        $cacheKey = "ue_cache_stats_{$integrationId}";

        return Cache::remember($cacheKey, 60, function () use ($integrationId) {
            $stats = UnitEconomicsCache::where('integration_id', $integrationId)
                ->selectRaw('fulfillment_type, COUNT(*) as count, MAX(calculated_at) as last_calculated')
                ->groupBy('fulfillment_type')
                ->get()
                ->keyBy('fulfillment_type')
                ->toArray();
            
            $totalProducts = Product::where('integration_id', $integrationId)->count();
            
            return [
                'total_products' => $totalProducts,
                'schemes' => $stats,
                'is_complete' => collect($stats)->sum('count') >= $totalProducts * 4,
            ];
        });
    }

    /**
     * Очистить устаревший кэш
     */
    public function clearStaleCache(int $maxAgeHours = 24): int
    {
        return UnitEconomicsCache::where('calculated_at', '<', now()->subHours($maxAgeHours))
            ->delete();
    }

    /**
     * Получает средний взвешенный КС (коэффициент склада) по всем складам товара
     * 
     * КС влияет на всю логистику WB: логистика = базовая × КС
     * 
     * @param string $sku SKU товара
     * @param string $marketplace Маркетплейс
     * @return float Средний КС (1.0 = 100%, 1.4 = 140%)
     */
    private function getAverageWarehouseCoefficient(string $sku, string $marketplace): float
    {
        $cacheKey = $marketplace . '|' . $sku;
        if (array_key_exists($cacheKey, $this->warehouseCoefficientCache)) {
            return $this->warehouseCoefficientCache[$cacheKey];
        }

        $warehouses = InventoryWarehouse::where('sku', $sku)
            ->where('marketplace', $marketplace)
            ->get(['warehouse_coefficient', 'quantity']);
        
        if ($warehouses->isEmpty()) {
            $this->warehouseCoefficientCache[$cacheKey] = 1.0;
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
            $this->warehouseCoefficientCache[$cacheKey] = $weightedSum / $totalQuantity;
            return $this->warehouseCoefficientCache[$cacheKey];
        }

        $this->warehouseCoefficientCache[$cacheKey] = 1.0;
        return 1.0;
    }

    private function getIntegrationCached(int $integrationId): ?Integration
    {
        if (array_key_exists($integrationId, $this->integrationCache)) {
            return $this->integrationCache[$integrationId];
        }

        $this->integrationCache[$integrationId] = Integration::find($integrationId);
        return $this->integrationCache[$integrationId];
    }

    private function getUnitEconomicsCached(int $integrationId, string $sku, ?string $fulfillmentType): ?UnitEconomics
    {
        $key = $integrationId . '|' . $sku . '|' . ($fulfillmentType ? strtoupper($fulfillmentType) : '');
        if (array_key_exists($key, $this->unitEconomicsCache)) {
            return $this->unitEconomicsCache[$key];
        }

        $query = UnitEconomics::where('integration_id', $integrationId)
            ->where('sku', $sku);
        if ($fulfillmentType) {
            $query->where('fulfillment_type', strtoupper($fulfillmentType));
        }

        $this->unitEconomicsCache[$key] = $query->first();
        return $this->unitEconomicsCache[$key];
    }

    private function getSettingsCached(int $integrationId, string $sku): ?UnitEconomicsSettings
    {
        $key = $integrationId . '|' . $sku;
        if (array_key_exists($key, $this->settingsCache)) {
            return $this->settingsCache[$key];
        }

        $this->settingsCache[$key] = UnitEconomicsSettings::where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->first();

        return $this->settingsCache[$key];
    }

    private function forgetSettingsCache(int $integrationId, string $sku): void
    {
        $key = $integrationId . '|' . $sku;
        unset($this->settingsCache[$key]);
    }

    private function forgetStatsCache(int $integrationId, string $marketplace, array $schemes): void
    {
        Cache::forget("ue_cache_stats_{$integrationId}");
        Cache::forget("ue_scheme_counts_{$integrationId}_{$marketplace}");
        Cache::forget("ue_actual_scheme_{$integrationId}_{$marketplace}");

        foreach ($schemes as $scheme) {
            Cache::forget("ue_stats_{$integrationId}_{$marketplace}_" . strtoupper($scheme));
        }
    }

    // ...
}
