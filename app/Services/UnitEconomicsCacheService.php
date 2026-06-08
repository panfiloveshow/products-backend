<?php

namespace App\Services;

use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Jobs\RecalculateUnitEconomicsForSkuJob;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\OzonSupplyFixation;
use App\Models\OzonSkuDeliveryProfile;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Models\WildberriesTariffSnapshot;
use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use Illuminate\Support\Collection;
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
    /** @var array<int, array{box_by_warehouse: array<string, WildberriesTariffSnapshot>, box_fallback: ?WildberriesTariffSnapshot, return: array}> */
    private array $wildberriesTariffSnapshotCache = [];
    /** @var array<string, array>|null Per-SKU locality data from real orders (batch-loaded) */
    private ?array $localityCache = null;
    /** @var int|null Seller-level FBO orders in last 7 days (cached per integration) */
    private ?int $sellerFboOrders7DaysCache = null;

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
        $this->forgetUnitEconomicsCache($product->integration_id, $product->sku);
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
        $this->unitEconomicsCache = [];
        $this->settingsCache = [];
        $this->localityCache = null;
        $this->sellerFboOrders7DaysCache = null;
        $integration = $this->getIntegrationCached($integrationId);
        if (!$integration) {
            return ['error' => 'Integration not found'];
        }

        // Shadow-update вместо delete→insert: фиксируем timestamp старта,
        // пересчитываем через updateOrCreate (обновляет existing, создаёт новые),
        // в конце удаляем только те записи, что не были тронуты (updated_at < startedAt).
        // Так фронт не получит пустой кэш в окне между delete и первым insert.
        $startedAt = now();

        $stats = [
            'total' => 0,
            'success' => 0,
            'errors' => 0,
            'schemes' => [],
        ];

        $schemes = $this->getSchemesForMarketplace($integration->marketplace);

        // Batch-загрузка per-SKU locality из реальных заказов (Ozon)
        if ($integration->marketplace === 'ozon') {
            $localityService = app(\App\Services\Ozon\OzonLocalityService::class);
            $this->localityCache = $localityService->resolveIntegrationLocality($integrationId);
            Log::info('Locality data loaded for integration', [
                'integration_id' => $integrationId,
                'skus_with_data' => count($this->localityCache),
            ]);
        }

        Product::where('integration_id', $integrationId)
            ->chunkById(100, function ($products) use (&$stats, $schemes, $integration) {
                $this->warmRecalculateChunkCaches($products, $schemes, $integration);

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

        // Зачищаем "устаревшие" записи — те, что не были тронуты этим пересчётом
        // (SKU пропали из products, или не вернули результат из калькулятора).
        $stalePruned = UnitEconomicsCache::where('integration_id', $integrationId)
            ->where('updated_at', '<', $startedAt)
            ->delete();

        Log::info('UnitEconomicsCache recalculated for integration', [
            'integration_id' => $integrationId,
            'stats' => $stats,
            'stale_pruned' => $stalePruned,
        ]);

        $this->forgetStatsCache($integrationId, $integration->marketplace, $schemes);

        return $stats;
    }

    /**
     * Обновить кэш при изменении настроек пользователя
     *
     * Пересчитывает ВСЕ схемы маркетплейса (а не только Product.fulfillment_type).
     * Иначе при ручном переопределении redemption_rate_override обновляется
     * только cache-строка для актуальной схемы товара (Ozon: FBO для FBO-товара),
     * а строки FBS/RFBS/EXPRESS остаются stale. Тогда при переключении вкладки
     * или после refresh пользователь видит старое API-значение, а не свой ручной
     * ввод. Settings change — редкое действие, доп. 3 пересчёта приемлемы.
     */
    public function onSettingsChanged(int $integrationId, string $sku): void
    {
        $this->forgetSettingsCache($integrationId, $sku);
        $integration = $this->getIntegrationCached($integrationId);
        if ($integration) {
            $this->forgetStatsCache($integrationId, $integration->marketplace, $this->getSchemesForMarketplace($integration->marketplace));
        }

        RecalculateUnitEconomicsForSkuJob::dispatch($integrationId, $sku)
            ->onQueue('unit-economics');
    }

    /**
     * Массовое обновление кэша при изменении настроек
     */
    public function onBulkSettingsChanged(int $integrationId, array $skus): void
    {
        foreach ($skus as $sku) {
            $this->forgetSettingsCache($integrationId, $sku);
        }

        $integration = $this->getIntegrationCached($integrationId);
        if ($integration) {
            $this->forgetStatsCache($integrationId, $integration->marketplace, $this->getSchemesForMarketplace($integration->marketplace));
        }

        foreach (array_unique(array_filter(array_map('strval', $skus))) as $sku) {
            RecalculateUnitEconomicsForSkuJob::dispatch($integrationId, $sku)
            ->onQueue('unit-economics');
        }
    }

    public function onIntegrationSettingsChanged(int $integrationId): void
    {
        $integration = $this->getIntegrationCached($integrationId);
        if ($integration) {
            $this->forgetStatsCache($integrationId, $integration->marketplace, $this->getSchemesForMarketplace($integration->marketplace));
        }

        RecalculateUnitEconomicsCacheJob::dispatch($integrationId)
            ->onQueue('unit-economics');
    }

    public function recalculateSkuAllSchemes(int $integrationId, string $sku): array
    {
        $this->forgetSettingsCache($integrationId, $sku);
        $this->forgetUnitEconomicsCache($integrationId, $sku);

        $product = Product::query()
            ->where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->first();

        if (! $product) {
            return [];
        }

        $integration = $this->getIntegrationCached($integrationId);
        if ($integration) {
            $this->forgetStatsCache($integrationId, $integration->marketplace, $this->getSchemesForMarketplace($integration->marketplace));
        }

        return $this->recalculateProductAllSchemes($product);
    }

    /**
     * Пересчитать кэш для товара по ВСЕМ схемам его маркетплейса.
     * Используется при изменении settings (redemption_rate_override, cost_price и т.д.),
     * чтобы все cache-строки SKU были консистентны независимо от Product.fulfillment_type.
     */
    private function recalculateProductAllSchemes(Product $product): array
    {
        $this->forgetUnitEconomicsCache($product->integration_id, $product->sku);
        $schemes = $this->getSchemesForMarketplace($product->marketplace);
        $results = [];

        foreach ($schemes as $scheme) {
            $results[$scheme] = $this->calculateAndCache($product, $scheme);
        }

        return $results;
    }

    private function prepareCalculationInput(Product $product, ?UnitEconomicsSettings $settings, string $fulfillmentType): array
    {
        $marketplace = $product->marketplace === 'yandex' ? 'yandex_market' : $product->marketplace;

        $marketplaceData = match ($marketplace) {
            'wildberries' => ($product->wb_data ?? []),
            'yandex_market' => ($product->yandex_data ?? []),
            default => ($product->ozon_data ?? []),
        };
        $commissions = $marketplaceData['commissions_by_scheme'] ?? $marketplaceData['commissions'] ?? [];
        $redemption = $marketplaceData['redemption'] ?? [];
        $tariffBreakdown = $marketplaceData['tariffs'] ?? [];

        $existingUE = $this->getUnitEconomicsCached($product->integration_id, $product->sku, strtoupper($fulfillmentType));

        $schemeKey = strtolower($fulfillmentType);
        if ($marketplace === 'wildberries') {
            $schemeKey = match ($schemeKey) {
                'fbo', 'fbw' => 'fbo',
                'fbs' => 'fbs',
                'edbs', 'express' => 'edbs',
                'dbs', 'pickup' => 'dbs',
                'dbw', 'booking' => 'dbw',
                default => $schemeKey,
            };
        } elseif ($schemeKey === 'realfbs' || $schemeKey === 'dbs') {
            $schemeKey = 'rfbs';
        }

        $defaultCommissionPercent = match ($marketplace) {
            'yandex', 'yandex_market' => 12,
            default => 15,
        };

        $commissionPercent = $commissions[$schemeKey]['percent']
            ?? $commissions['fbs']['percent']
            ?? $commissions['fbo']['percent']
            ?? $existingUE?->commission_percent
            ?? $defaultCommissionPercent;

        // Дефолты по маркетплейсам — среднерыночные показатели, используются
        // только если нет данных ни из API, ни из ручного override. Раньше для Ozon
        // стоял 100% — это завышало маржу у новых интеграций и для товаров,
        // которых нет в выгрузке Ozon Analytics (non-Premium / свежие SKU).
        $defaultRedemptionRate = match ($marketplace) {
            'wildberries' => 80,
            'ozon' => 85,
            'yandex', 'yandex_market' => 90,
            default => 90,
        };

        // Для Ozon фиксируем source в цепочке — фронту нужно понимать откуда
        // пришло значение (postings_28d, analytics_api_28d, no_sales_28d, manual, default).
        // Также пробрасываем period_days (28 для Ozon, 30 для не-Premium fallback).
        $existingUESource = $existingUE?->redemption_source ?? null;
        $existingUERate = $existingUE?->redemption_rate !== null
            ? (float) $existingUE->redemption_rate
            : null;
        $apiSource = $redemption['source'] ?? null;

        // «Свежие» источники из текущего sync'а (передаются через $redemption) —
        // имеют приоритет над existingUE. Иначе stale default=85% из unit_economics
        // вечно перекрывал свежий postings_28d. Это был баг: cache 2099/black1
        // отдавал ord=3 из старого sync, хотя новый postings за 28д показывал 2.
        // Список «свежих» источников централизован в RedemptionSource::isFresh().
        $apiSourceEnum = \App\Domains\Ozon\UnitEconomics\RedemptionSource::fromStringSafe($apiSource);
        $apiIsFresh = $apiSource !== null && $apiSourceEnum->isFresh();

        if ($settings?->redemption_rate_override !== null) {
            $redemptionRate = (float) $settings->redemption_rate_override;
            $redemptionSource = 'manual';
        } elseif ($apiIsFresh
            && array_key_exists('redemption_rate', $redemption)
            && $redemption['redemption_rate'] !== null
        ) {
            // Свежий пайплайн Sync*Command → новое значение выигрывает.
            // Включая 0.0 для no_sales_28d (user explicit: worst case в расчёты).
            $redemptionRate = (float) $redemption['redemption_rate'];
            $redemptionSource = $apiSource;
        } elseif ($existingUERate !== null) {
            // Нет свежих данных — сохраняем последнее известное значение.
            $redemptionRate = $existingUERate;
            $redemptionSource = $existingUESource ?? 'default';
        } elseif (array_key_exists('redemption_rate', $redemption) && $redemption['redemption_rate'] !== null) {
            // Остался старый API-источник (Yandex/WB), не из свежего списка.
            $redemptionRate = (float) $redemption['redemption_rate'];
            $redemptionSource = $apiSource ?? 'api';
        } else {
            $redemptionRate = (float) $defaultRedemptionRate;
            $redemptionSource = 'default';
        }

        $redemptionPeriodDays = (int) ($redemption['period_days']
            ?? match ($redemptionSource) {
                'postings_28d', 'analytics_api_28d', 'no_sales_28d' => 28,
                'fallback_orders_returns', 'fallback_partial' => 30,
                default => 28,
            });

        $integration = $this->getIntegrationCached($product->integration_id);
        $integrationSettings = $integration?->settings ?? [];

        $routeKey = null;
        $routeLabel = null;
        $isLocalSale = null;
        $nonLocalMarkupPercent = null;
        $tariffSource = null;
        $tariffEffectiveFrom = null;
        $priceSegment = null;
        $activeFixation = [];
        $stockProfile = [];
        $salesProfile = [];
        $routeDetails = [];
        $orderEconomicsSummary = [];
        $markupRuleReason = null;
        $markupRuleReasonLabel = null;
        $sales7Days = null;
        $profileDataSources = [];
        $dominantClusterId = null;
        $dominantClusterShare = null;
        $expectedLocalityRate = null;
        $weightedNonLocalMarkupPercent = null;
        $clustersSummary = [];
        $shippingRoutes = [];
        $pricingStrategy = [];
        $competitorPrice = null;
        $currentPriceIndex = null;
        $currentPriceIsFavorable = null;
        $currentPriceIndexLabel = null;
        $currentPriceCompetitorDelta = null;
        $currentPriceCompetitorDeltaPercent = null;

        if ($marketplace === 'ozon') {
            $activeFixation = is_array($marketplaceData['active_fixation'] ?? null)
                ? $marketplaceData['active_fixation']
                : [];
            if ($activeFixation === []) {
                $fixation = OzonSupplyFixation::query()
                    ->where('integration_id', $product->integration_id)
                    ->where('sku', $product->sku)
                    ->activeWindow()
                    ->orderByDesc('fixation_base_date')
                    ->first();
                if ($fixation) {
                    $activeFixation = [
                        'fixation_applied' => true,
                        'fixation_id' => $fixation->id,
                        'fixation_base_date' => optional($fixation->fixation_base_date)?->toDateString(),
                        'fixed_until' => optional($fixation->fixed_until)?->toDateString(),
                        'tariff_version_used' => $fixation->tariff_version,
                        'markup_version_used' => $fixation->markup_version,
                        'shipping_cluster_id' => $fixation->shipping_cluster_id,
                        'shipping_cluster_name' => $fixation->shipping_cluster_name,
                        'calculation_mode' => 'preview',
                    ];
                }
            }
            $deliveryProfile = OzonSkuDeliveryProfile::findForProduct(
                $product->integration_id,
                $product->sku,
                strtoupper($fulfillmentType)
            ) ?? OzonSkuDeliveryProfile::findForProduct(
                $product->integration_id,
                $product->sku,
                'ALL'
            );
            // Fallback: если для конкретного SKU нет профиля, берём любой профиль интеграции
            if (! $deliveryProfile && $product->integration_id) {
                $deliveryProfile = OzonSkuDeliveryProfile::where('integration_id', $product->integration_id)
                    ->whereNotNull('cluster_profile')
                    ->first();
            }
            $profileStock = is_array($deliveryProfile?->stock_profile ?? null) ? $deliveryProfile->stock_profile : [];
            $profileSales = is_array($deliveryProfile?->sales_profile ?? null) ? $deliveryProfile->sales_profile : [];
            $profileCluster = is_array($deliveryProfile?->cluster_profile ?? null) ? $deliveryProfile->cluster_profile : [];
            $profileClustersSummary = is_array($profileCluster['clusters_summary'] ?? null)
                ? $profileCluster['clusters_summary']
                : [];
            $existingMarketplaceData = is_array($existingUE?->marketplace_data ?? null) ? $existingUE->marketplace_data : [];
            $pricingStrategy = is_array($marketplaceData['pricing_strategy'] ?? null)
                ? $marketplaceData['pricing_strategy']
                : (is_array($existingMarketplaceData['pricing_strategy'] ?? null) ? $existingMarketplaceData['pricing_strategy'] : []);
            $competitorPrice = $marketplaceData['competitor_price']
                ?? $existingMarketplaceData['competitor_price']
                ?? ($pricingStrategy['competitor_price'] ?? null);
            $currentPriceIndex = $marketplaceData['current_price_index']
                ?? $existingMarketplaceData['current_price_index']
                ?? ($pricingStrategy['current_price_index'] ?? null);
            $currentPriceIsFavorable = array_key_exists('current_price_is_favorable', $marketplaceData)
                ? $marketplaceData['current_price_is_favorable']
                : (array_key_exists('current_price_is_favorable', $existingMarketplaceData)
                    ? $existingMarketplaceData['current_price_is_favorable']
                    : ($pricingStrategy['current_price_is_favorable'] ?? null));
            $currentPriceIndexLabel = $marketplaceData['current_price_index_label']
                ?? $existingMarketplaceData['current_price_index_label']
                ?? ($pricingStrategy['current_price_index_label'] ?? null);
            $currentPriceCompetitorDelta = $marketplaceData['current_price_competitor_delta']
                ?? $existingMarketplaceData['current_price_competitor_delta']
                ?? ($pricingStrategy['current_price_competitor_delta'] ?? null);
            $currentPriceCompetitorDeltaPercent = $marketplaceData['current_price_competitor_delta_percent']
                ?? $existingMarketplaceData['current_price_competitor_delta_percent']
                ?? ($pricingStrategy['current_price_competitor_delta_percent'] ?? null);
            $stockProfile = is_array($marketplaceData['stock_profile'] ?? null)
                ? $marketplaceData['stock_profile']
                : ($profileStock !== [] ? $profileStock : (is_array($existingMarketplaceData['stock_profile'] ?? null) ? $existingMarketplaceData['stock_profile'] : []));
            $salesProfile = is_array($marketplaceData['sales_profile'] ?? null)
                ? $marketplaceData['sales_profile']
                : ($profileSales !== [] ? $profileSales : (is_array($existingMarketplaceData['sales_profile'] ?? null) ? $existingMarketplaceData['sales_profile'] : []));
            $routeDetails = is_array($marketplaceData['route_details'] ?? null)
                ? $marketplaceData['route_details']
                : (is_array($existingMarketplaceData['route_details'] ?? null) ? $existingMarketplaceData['route_details'] : []);
            $orderEconomicsSummary = is_array($marketplaceData['order_economics_summary'] ?? null)
                ? $marketplaceData['order_economics_summary']
                : (is_array($existingMarketplaceData['order_economics_summary'] ?? null) ? $existingMarketplaceData['order_economics_summary'] : []);
            $markupRuleReason = $marketplaceData['markup_rule_reason']
                ?? $existingMarketplaceData['markup_rule_reason']
                ?? null;
            $markupRuleReasonLabel = $marketplaceData['markup_rule_reason_label']
                ?? $existingMarketplaceData['markup_rule_reason_label']
                ?? null;
            // Seller-level total FBO orders in 7 days (Ozon rule applies per-seller, not per-SKU)
            // Подсчёт из реальных postings — inventory_warehouses.sales_7_days ненадёжен
            if (! isset($this->sellerFboOrders7DaysCache)) {
                $localityService = app(\App\Services\Ozon\OzonLocalityService::class);
                $this->sellerFboOrders7DaysCache = $localityService->countSellerFboOrders7Days($product->integration_id);
            }
            $sales7Days = $this->sellerFboOrders7DaysCache;
            $profileDataSources = is_array($marketplaceData['profile_data_sources'] ?? null)
                ? $marketplaceData['profile_data_sources']
                : (is_array($existingMarketplaceData['profile_data_sources'] ?? null) ? $existingMarketplaceData['profile_data_sources'] : []);
            $routeKey = $marketplaceData['route_key']
                ?? $activeFixation['shipping_cluster_id']
                ?? $profileStock['route_key']
                ?? $marketplaceData['cluster_id']
                ?? $marketplaceData['macrolocal_cluster_id']
                ?? $existingUE?->route_key
                ?? null;
            $routeLabel = $marketplaceData['route_label']
                ?? $activeFixation['shipping_cluster_name']
                ?? $profileStock['route_label']
                ?? $marketplaceData['cluster_name']
                ?? $marketplaceData['delivery_cluster']
                ?? $existingUE?->route_label
                ?? null;
            $isLocalSale = array_key_exists('is_local_sale', $marketplaceData)
                ? (bool) $marketplaceData['is_local_sale']
                : (array_key_exists('is_local_sale', $profileStock) ? (bool) $profileStock['is_local_sale'] : ($existingUE?->is_local_sale ?? null));
            $nonLocalMarkupPercent = array_key_exists('non_local_markup_percent', $marketplaceData)
                ? (float) $marketplaceData['non_local_markup_percent']
                : ($existingUE?->non_local_markup_percent ?? null);
            $tariffSource = $existingUE?->tariff_source;
            $tariffEffectiveFrom = $existingUE?->tariff_effective_from?->format('Y-m-d')
                ?? ($existingUE?->tariff_effective_from ? (string) $existingUE->tariff_effective_from : null);
            $priceSegment = $existingUE?->price_segment;
            $routeResolutionStatus = $marketplaceData['route_resolution_status']
                ?? $deliveryProfile?->route_resolution_status
                ?? $existingMarketplaceData['route_resolution_status']
                ?? null;
            $localityResolutionStatus = $marketplaceData['locality_resolution_status']
                ?? $deliveryProfile?->locality_resolution_status
                ?? $existingMarketplaceData['locality_resolution_status']
                ?? null;
            $calculationConfidence = $marketplaceData['calculation_confidence']
                ?? $deliveryProfile?->calculation_confidence
                ?? $existingMarketplaceData['calculation_confidence']
                ?? null;
            $profileSource = $marketplaceData['profile_source']
                ?? $deliveryProfile?->profile_source
                ?? $existingMarketplaceData['profile_source']
                ?? null;
            $dominantClusterId = $marketplaceData['dominant_cluster_id']
                ?? $deliveryProfile?->dominant_demand_cluster_id
                ?? ($profileCluster['dominant_cluster_id'] ?? null)
                ?? $existingMarketplaceData['dominant_cluster_id']
                ?? null;
            $dominantClusterShare = isset($marketplaceData['dominant_cluster_share'])
                ? (float) $marketplaceData['dominant_cluster_share']
                : ($deliveryProfile?->dominant_demand_cluster_share !== null
                    ? (float) $deliveryProfile->dominant_demand_cluster_share
                    : (isset($profileCluster['dominant_cluster_share']) ? (float) $profileCluster['dominant_cluster_share'] : (isset($existingMarketplaceData['dominant_cluster_share']) ? (float) $existingMarketplaceData['dominant_cluster_share'] : null)));
            $expectedLocalityRate = isset($marketplaceData['expected_locality_rate'])
                ? (float) $marketplaceData['expected_locality_rate']
                : ($deliveryProfile?->expected_locality_rate !== null
                    ? (float) $deliveryProfile->expected_locality_rate
                    : (isset($existingMarketplaceData['expected_locality_rate']) ? (float) $existingMarketplaceData['expected_locality_rate'] : null));
            $weightedNonLocalMarkupPercent = isset($marketplaceData['weighted_non_local_markup_percent'])
                ? (float) $marketplaceData['weighted_non_local_markup_percent']
                : ($deliveryProfile?->weighted_non_local_markup_percent !== null
                    ? (float) $deliveryProfile->weighted_non_local_markup_percent
                    : (isset($existingMarketplaceData['weighted_non_local_markup_percent']) ? (float) $existingMarketplaceData['weighted_non_local_markup_percent'] : null));
            $baseClustersSummary = is_array($marketplaceData['clusters_summary'] ?? null)
                ? $marketplaceData['clusters_summary']
                : ($profileClustersSummary !== [] ? $profileClustersSummary : (is_array($existingMarketplaceData['clusters_summary'] ?? null) ? $existingMarketplaceData['clusters_summary'] : []));
            // Locality из реальных заказов (per-SKU) — приоритетный источник
            $skuLocality = $this->localityCache[$product->sku] ?? null;
            $shippingRoutes = [];
            if ($skuLocality && ! empty($skuLocality['clusters_summary'])) {
                // markupAllowed учитывает правило Ozon >=50 FBO/7д и ручные исключения
                // (Select-only, size_restricted) — при них наценка не применяется независимо от продаж.
                $manualMarkupOverride = (bool) ($settings?->is_select_only || $settings?->is_size_restricted);
                $isFboScheme = strtoupper($fulfillmentType) === 'FBO';
                $markupAllowed = ! $manualMarkupOverride
                    && $isFboScheme
                    && ($sales7Days === null || $sales7Days >= 50);
                $clustersSummary = $this->mergeOzonRealOrdersClustersSummary(
                    $skuLocality['clusters_summary'],
                    $baseClustersSummary,
                    $stockProfile,
                    $markupAllowed,
                    $isFboScheme ? 'fbo_lt_50_orders_7d' : 'non_fbo_no_nonlocal_markup'
                );
                $salesProfile = ! empty($skuLocality['sales_profile'])
                    ? $this->mergeOzonSalesProfileWithClustersSummary($skuLocality['sales_profile'], $clustersSummary)
                    : $salesProfile;
                $stockProfile = ! empty($skuLocality['stock_profile']) ? $skuLocality['stock_profile'] : $stockProfile;
                $expectedLocalityRate = $skuLocality['locality_rate'];
                $shippingRoutes = $skuLocality['shipping_routes'] ?? [];
                $profileSource = 'real_orders';
                $calculationConfidence = $skuLocality['total_orders'] >= 10 ? 'high' : 'medium';
                // Доминантный кластер из реальных заказов
                $topCluster = $skuLocality['clusters_summary'][0] ?? null;
                if ($topCluster && ! $routeLabel) {
                    $routeLabel = $topCluster['cluster_name'];
                }
                // route_resolution на основе реальных данных
                $routeResolutionStatus = 'resolved';
                $localityResolutionStatus = 'resolved';
            } else {
                $clustersSummary = $baseClustersSummary;
            }

            if ($this->ozonOrderEconomicsHasOnlyExcludedMarkup($orderEconomicsSummary)) {
                $firstReason = (string) array_key_first($orderEconomicsSummary['markup_reason_codes']);
                $nonLocalMarkupPercent = 0.0;
                $weightedNonLocalMarkupPercent = 0.0;
                $clustersSummary = $this->zeroOzonEffectiveMarkup($clustersSummary, $firstReason);
                $salesProfile = $this->zeroOzonEffectiveMarkup(
                    is_array($salesProfile['clusters'] ?? null) ? $salesProfile['clusters'] : (is_array($salesProfile) ? $salesProfile : []),
                    $firstReason
                );
            }

            if (strtoupper($fulfillmentType) !== 'FBO') {
                $nonLocalMarkupPercent = 0.0;
                $weightedNonLocalMarkupPercent = 0.0;
                $clustersSummary = $this->zeroOzonEffectiveMarkup($clustersSummary, 'non_fbo_no_nonlocal_markup');
                $salesProfile = $this->zeroOzonEffectiveMarkup(
                    is_array($salesProfile['clusters'] ?? null) ? $salesProfile['clusters'] : (is_array($salesProfile) ? $salesProfile : []),
                    'non_fbo_no_nonlocal_markup'
                );
                $markupRuleReason = 'non_fbo_no_nonlocal_markup';
                $markupRuleReasonLabel = 'Надбавка за нелокальность применяется только к FBO';
            }
        }

        $lengthMm = $settings?->length_mm ?? $marketplaceData['length_mm'] ?? $marketplaceData['dimensions']['depth'] ?? $product->depth;
        $widthMm = $settings?->width_mm ?? $marketplaceData['width_mm'] ?? $marketplaceData['dimensions']['width'] ?? $product->width;
        $heightMm = $settings?->height_mm ?? $marketplaceData['height_mm'] ?? $marketplaceData['dimensions']['height'] ?? $product->height;
        $weightG = $settings?->weight_g ?? $marketplaceData['weight_g'] ?? $marketplaceData['dimensions']['weight'] ?? $product->weight;

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
        $salesDistributionIndex = 0.0;
        if ($marketplace === 'wildberries') {
            $tariffBreakdown = $this->resolveWildberriesTariffBreakdown(
                (int) $product->integration_id,
                (string) $fulfillmentType,
                $marketplaceData,
                is_array($tariffBreakdown) ? $tariffBreakdown : []
            );
            $tariffSource = $tariffBreakdown['source'] ?? $tariffSource;
            $tariffEffectiveFrom = $tariffBreakdown['effective_date'] ?? $tariffEffectiveFrom;
            $sppPercent = (float) ($settings?->spp_percent ?? $marketplaceData['spp_percent'] ?? $existingUE?->spp_percent ?? 0);
            $warehouseCoefficient = $this->getAverageWarehouseCoefficient($product->integration_id, $product->sku, $marketplace);

            // Делаем средневзвешенный по складам КС авторитетным: записываем его в box-тариф,
            // чтобы калькулятор не перетирал его коэффициентом одного склада/фолбэка «Цифровой склад».
            // Логистика по сумме не меняется (КС в ней сокращается), меняется корректный показ КС.
            if (is_array($tariffBreakdown) && is_array($tariffBreakdown['box'] ?? null) && $warehouseCoefficient > 0) {
                $coefKey = in_array(strtoupper((string) $fulfillmentType), ['FBS', 'DBW'], true)
                    ? 'delivery_marketplace_coef_percent'
                    : 'delivery_coef_percent';
                $tariffBreakdown['box'][$coefKey] = round($warehouseCoefficient * 100, 2);
            }

            $localizationIndex = (float) (
                $integrationSettings['wb_localization_index']
                ?? $integration?->localization_index
                ?? $existingUE?->localization_index
                ?? 1.0
            );
            $salesDistributionIndex = (float) (
                $marketplaceData['sales_distribution_index']
                ?? $marketplaceData['sales_distribution_index_percent']
                ?? $integrationSettings['wb_sales_distribution_index']
                ?? $integrationSettings['sales_distribution_index']
                ?? $existingMarketplaceData['sales_distribution_index']
                ?? 0.0
            );
        }

        $drrPercent = (float) ($settings?->drr_percent ?? $existingUE?->drr_percent ?? 0);
        $ourSharePercent = (float) ($settings?->our_share_percent ?? $existingUE?->our_share_percent ?? 0);
        $taxPercent = (float) ($settings?->tax_percent ?? $existingUE?->tax_percent ?? 6);
        $vatPercent = (float) ($settings?->vat_percent ?? $existingUE?->vat_percent ?? 0);
        $existingAcquiringPercent = $existingUE?->acquiring_percent;
        $defaultAcquiring = match ($marketplace) {
            'wildberries' => 1.5,
            'yandex_market' => 2.0,
            'ozon' => 1.5,
            default => 0,
        };
        $acquiringPercent = (float) (($existingAcquiringPercent !== null && (float) $existingAcquiringPercent > 0)
            ? $existingAcquiringPercent
            : $defaultAcquiring);
        $storageCost = $marketplace === 'wildberries'
            ? (float) ($marketplaceData['storage_cost_per_unit'] ?? $marketplaceData['storage_cost_normalized'] ?? 0)
            : (float) ($marketplaceData['storage_cost_per_unit']
                ?? $marketplaceData['storage_cost_normalized']
                ?? $marketplaceData['storage_cost']
                ?? $product->storage_cost
                ?? $existingUE?->storage_cost
                ?? 0);
        if ($marketplace === 'wildberries' && ! in_array(strtoupper($fulfillmentType), ['FBO', 'FBW'], true)) {
            $storageCost = 0.0;
        }
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

        // (volume_weight / chargeable_volume_liters вычисляются позже — в
        // convertResultToCacheData по результатам калькулятора; здесь этот блок
        // был вставлен по ошибке и падал на `Undefined variable $result`.)

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
            'length_mm' => $lengthMm,
            'width_mm' => $widthMm,
            'height_mm' => $heightMm,
            'weight_g' => $weightG,
            'cost_price' => (float) $costPrice,
            'packaging_cost' => 0,
            'additional_costs' => 0,
            'category_id' => $product->category ?? 'default',
            'commission_rate' => (float) $commissionPercent,
            'redemption_rate' => (float) $redemptionRate,
            'redemption_source' => $redemptionSource,
            'redemption_period_days' => $redemptionPeriodDays,
            'orders_count' => $redemption['orders_count'] ?? $existingUE?->orders_count ?? null,
            'returns_count' => $redemption['returns_count'] ?? $existingUE?->returns_count ?? null,
            'delivered_count' => $redemption['delivered_count'] ?? $existingMarketplaceData['delivered_count'] ?? null,
            'cancelled_count' => $redemption['cancelled_count']
                ?? $redemption['cancellations_count']
                ?? $redemption['cancellations']
                ?? $existingMarketplaceData['cancelled_count']
                ?? $existingMarketplaceData['cancellations_count']
                ?? $existingMarketplaceData['cancellations']
                ?? null,
            'not_redeemed_count' => $redemption['not_redeemed_count'] ?? $existingMarketplaceData['not_redeemed_count'] ?? null,
            'in_flight_count' => $redemption['in_flight_count'] ?? $existingMarketplaceData['in_flight_count'] ?? null,
            'delivery_coefficient' => null,
            'warehouse_coefficient' => (float) $warehouseCoefficient,
            'localization_index' => (float) $localizationIndex,
            'sales_distribution_index' => (float) $salesDistributionIndex,
            'spp_percent' => $sppPercent,
            'drr_percent' => $drrPercent,
            'our_share_percent' => $ourSharePercent,
            'tax_percent' => $taxPercent,
            'vat_percent' => $vatPercent,
            'acquiring_percent' => $acquiringPercent,
            'storage_cost' => $storageCost,
            'additional_commission_percent' => null,
            'own_delivery_cost' => $ownDeliveryCost,
            'own_return_cost' => $ownReturnCost,
            'marketplace_compensation' => $marketplaceCompensation,
            'acceptance_cost' => $acceptanceCost,
            'penalty_cost' => $penaltyCost,
            'route_key' => $routeKey,
            'route_label' => $routeLabel,
            'is_local_sale' => $isLocalSale,
            'non_local_markup_percent' => $nonLocalMarkupPercent,
            'tariff_source' => $tariffSource,
            'tariff_effective_from' => $tariffEffectiveFrom,
            'price_segment' => $priceSegment,
            'route_resolution_status' => $routeResolutionStatus ?? null,
            'locality_resolution_status' => $localityResolutionStatus ?? null,
            'calculation_confidence' => $calculationConfidence ?? null,
            'profile_source' => $profileSource ?? null,
            'dominant_cluster_id' => $dominantClusterId ?? null,
            'dominant_cluster_share' => $dominantClusterShare ?? null,
            'expected_locality_rate' => $expectedLocalityRate ?? null,
            'weighted_non_local_markup_percent' => $weightedNonLocalMarkupPercent ?? null,
            'clusters_summary' => $clustersSummary ?? [],
            'shipping_cluster_id' => $activeFixation['shipping_cluster_id'] ?? null,
            'shipping_cluster_name' => $activeFixation['shipping_cluster_name'] ?? null,
            'destination_cluster_id' => $dominantClusterId ?? $marketplaceData['destination_cluster_id'] ?? null,
            'destination_cluster_name' => $marketplaceData['destination_cluster_name']
                ?? ($dominantClusterId !== null && !empty($clustersSummary)
                    ? collect($clustersSummary)->firstWhere('cluster_id', $dominantClusterId)['cluster_name'] ?? null
                    : null),
            'fixation_applied' => $activeFixation['fixation_applied'] ?? null,
            'fixation_id' => $activeFixation['fixation_id'] ?? null,
            'fixation_base_date' => $activeFixation['fixation_base_date'] ?? null,
            'fixed_until' => $activeFixation['fixed_until'] ?? null,
            'tariff_version_used' => $activeFixation['tariff_version_used'] ?? null,
            'markup_version_used' => $activeFixation['markup_version_used'] ?? null,
            'calculation_mode' => $activeFixation['calculation_mode'] ?? null,
            'tariff_breakdown' => is_array($tariffBreakdown) ? $tariffBreakdown : [],
            'stock_profile' => is_array($stockProfile['clusters'] ?? null) ? $stockProfile['clusters'] : ($stockProfile ?? []),
            'sales_profile' => is_array($salesProfile['clusters'] ?? null) ? $salesProfile['clusters'] : ($salesProfile ?? []),
            'shipping_routes' => $shippingRoutes ?? [],
            'sales_7_days' => $sales7Days,
            // Ручные исключения: если товар отмечен как Select-only или size_restricted —
            // Ozon не применяет наценку за нелокальную продажу. Принудительно false.
            'markup_applied' => ($settings?->is_select_only || $settings?->is_size_restricted)
                ? false
                : ($marketplaceData['markup_applied'] ?? null),
            'weighted_logistics_cost' => isset($deliveryProfile) ? ($deliveryProfile->weighted_logistics_cost ?? null) : null,
            'product_name' => $product->name,
            '_extra' => [
                'sales_count' => max(1, (int) ($existingUE?->sales_count ?? $product->sales_30_days ?? 1)),
                'route_key' => $routeKey,
                'route_label' => $routeLabel,
                'is_local_sale' => $isLocalSale,
                'non_local_markup_percent' => $nonLocalMarkupPercent,
                'tariff_source' => $tariffSource,
                'tariff_effective_from' => $tariffEffectiveFrom,
                'price_segment' => $priceSegment,
                'route_resolution_status' => $routeResolutionStatus ?? null,
                'locality_resolution_status' => $localityResolutionStatus ?? null,
                'calculation_confidence' => $calculationConfidence ?? null,
                'profile_source' => $profileSource ?? null,
                'dominant_cluster_id' => $dominantClusterId ?? null,
                'dominant_cluster_share' => $dominantClusterShare ?? null,
                'expected_locality_rate' => $expectedLocalityRate ?? null,
                'weighted_non_local_markup_percent' => $weightedNonLocalMarkupPercent ?? null,
                'clusters_summary' => $clustersSummary ?? [],
                'stock_profile' => $stockProfile ?? [],
                'sales_profile' => $salesProfile ?? [],
                'route_details' => $routeDetails ?? [],
                'order_economics_summary' => $orderEconomicsSummary ?? [],
                'markup_rule_reason' => $markupRuleReason,
                'markup_rule_reason_label' => $markupRuleReasonLabel,
                'sales_7_days' => $sales7Days,
                'profile_data_sources' => $profileDataSources,
                'is_premium' => (bool) ($integration?->is_premium ?? false),
                'premium_mode' => ($integration?->is_premium ?? false) ? 'premium' : 'fallback',
                'premium_recommendation' => ($integration?->is_premium ?? false)
                    ? null
                    : 'Подключите Premium Ozon, чтобы получать точные данные по кластерам спроса и локальности.',
                'shipping_routes' => $shippingRoutes ?? [],
                'active_fixation' => $activeFixation,
                'turnover_days' => (int) ($existingUE?->turnover_days ?? 30),
                'drr_percent' => $drrPercent,
                'our_share_percent' => $ourSharePercent,
                'tax_percent' => $taxPercent,
                'vat_percent' => $vatPercent,
                'acquiring_percent' => $acquiringPercent,
                'is_in_promotion' => $marketplaceData['is_in_promotion'] ?? $commissions['is_in_promotion'] ?? false,
                'promotion_discount' => $marketplaceData['promotion_discount'] ?? $commissions['promotion_discount'] ?? 0,
                'redemption_source' => $redemptionSource,
                'redemption_period_days' => $redemptionPeriodDays,
                'orders_count' => $redemption['orders_count'] ?? $existingUE?->orders_count ?? null,
                'returns_count' => $redemption['returns_count'] ?? $existingUE?->returns_count ?? null,
                'delivered_count' => $redemption['delivered_count'] ?? $existingMarketplaceData['delivered_count'] ?? null,
                'cancelled_count' => $redemption['cancelled_count']
                    ?? $redemption['cancellations_count']
                    ?? $redemption['cancellations']
                    ?? $existingMarketplaceData['cancelled_count']
                    ?? $existingMarketplaceData['cancellations_count']
                    ?? $existingMarketplaceData['cancellations']
                    ?? null,
                'not_redeemed_count' => $redemption['not_redeemed_count'] ?? $existingMarketplaceData['not_redeemed_count'] ?? null,
                'in_flight_count' => $redemption['in_flight_count'] ?? $existingMarketplaceData['in_flight_count'] ?? null,
                'spp_percent' => $sppPercent,
                'price_source' => $marketplaceData['price_source'] ?? null,
                'commission_source' => isset($marketplaceData['commissions_by_scheme']) ? 'wb_commission_api_scheme' : null,
                'storage_source' => $marketplace === 'wildberries'
                    ? (array_key_exists('storage_cost_per_unit', $marketplaceData) || array_key_exists('storage_cost_normalized', $marketplaceData)
                        ? 'wb_normalized_per_unit'
                        : 'not_applied_without_normalized_per_unit')
                    : null,
                'calculation_warnings' => $marketplace === 'wildberries' && ($marketplaceData['storage_cost'] ?? null) !== null && ($marketplaceData['storage_cost_per_unit'] ?? $marketplaceData['storage_cost_normalized'] ?? null) === null
                    ? ['wb_period_storage_cost_not_used_as_per_unit']
                    : [],
                'pricing_strategy' => $pricingStrategy,
                'competitor_price' => $competitorPrice !== null ? round((float) $competitorPrice, 2) : null,
                'current_price_index' => $currentPriceIndex !== null ? round((float) $currentPriceIndex, 4) : null,
                'current_price_is_favorable' => $currentPriceIsFavorable !== null ? (bool) $currentPriceIsFavorable : null,
                'current_price_index_label' => $currentPriceIndexLabel,
                'current_price_competitor_delta' => $currentPriceCompetitorDelta !== null ? round((float) $currentPriceCompetitorDelta, 2) : null,
                'current_price_competitor_delta_percent' => $currentPriceCompetitorDeltaPercent !== null ? round((float) $currentPriceCompetitorDeltaPercent, 2) : null,
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
        $taxAmount = $metaTaxAmount ?? ($price * ($taxPercent / 100));
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
        $markupPercent = $costs->costPrice > 0 ? round($price / $costs->costPrice, 2) : 0;
        $roiPercent = $costs->getProductCosts() > 0 ? (($netProfit / $costs->getProductCosts()) * 100) : 0;
        
        $length = (float) ($inputData['length'] ?? 0);
        $width = (float) ($inputData['width'] ?? 0);
        $height = (float) ($inputData['height'] ?? 0);
        $volumeLiters = ($length > 0 && $width > 0 && $height > 0)
            ? ($length * $width * $height) / 1000
            : null;

        // Объёмный вес (Ozon: volume_liters / 5) — используется в возвращаемых
        // marketplace_data и volume_weight.
        $volumeWeight = $product->volume_weight
            ?? ($volumeLiters !== null ? round($volumeLiters / 5, 4) : null);

        // Тарифицируемый объём для матрицы логистики — max(физ. объём, volumeWeight × 5).
        // Калькулятор мог уже положить это значение в metadata.
        $chargeableVolumeLiters = $result->metadata['chargeable_volume_liters']
            ?? ($volumeLiters !== null
                ? max((float) $volumeLiters, (float) (($volumeWeight ?? 0) * 5))
                : null);

        $profitBaseBeforePostCosts = isset($result->metadata['profit_base'])
            ? (float) $result->metadata['profit_base']
            : (float) $result->netProfit;
        $profitRangeDelta = $netProfit - $profitBaseBeforePostCosts;
        $profitMin = isset($result->metadata['profit_min'])
            ? (float) $result->metadata['profit_min'] + $profitRangeDelta
            : $netProfit;
        $profitMax = isset($result->metadata['profit_max'])
            ? (float) $result->metadata['profit_max'] + $profitRangeDelta
            : $netProfit;
        $profitRangeValues = [$profitMin, $netProfit, $profitMax];

        return [
            'product_id' => $product->id,
            'product_name' => $result->productName ?? $product->name,
            'marketplace' => $result->marketplace,
            'marketplace_data' => array_merge(
                is_array($extra) ? $extra : [],
                is_array($result->metadata ?? null) ? $result->metadata : [],
                [
                    'route_key' => $result->metadata['route_key'] ?? $extra['route_key'] ?? null,
                    'route_label' => $result->metadata['route_label'] ?? $extra['route_label'] ?? null,
                    'is_local_sale' => $result->metadata['is_local_sale'] ?? $extra['is_local_sale'] ?? null,
                    'non_local_markup_percent' => $result->metadata['non_local_markup_percent'] ?? $extra['non_local_markup_percent'] ?? 0,
                    'tariff_source' => $result->metadata['tariff_source'] ?? $extra['tariff_source'] ?? null,
                    'tariff_effective_from' => $result->metadata['tariff_effective_from'] ?? $extra['tariff_effective_from'] ?? null,
                    'price_segment' => $result->metadata['price_segment'] ?? $extra['price_segment'] ?? null,
                    'route_resolution_status' => $result->metadata['route_resolution_status'] ?? $extra['route_resolution_status'] ?? null,
                    'locality_resolution_status' => $result->metadata['locality_resolution_status'] ?? $extra['locality_resolution_status'] ?? null,
                    'calculation_confidence' => $result->metadata['calculation_confidence'] ?? $extra['calculation_confidence'] ?? null,
                    'profile_source' => $result->metadata['profile_source'] ?? $extra['profile_source'] ?? null,
                    'dominant_cluster_id' => $result->metadata['dominant_cluster_id'] ?? $extra['dominant_cluster_id'] ?? null,
                    'dominant_cluster_share' => $result->metadata['dominant_cluster_share'] ?? $extra['dominant_cluster_share'] ?? null,
                    'expected_locality_rate' => $result->metadata['expected_locality_rate'] ?? $extra['expected_locality_rate'] ?? null,
                    'weighted_non_local_markup_percent' => $result->metadata['weighted_non_local_markup_percent'] ?? $extra['weighted_non_local_markup_percent'] ?? null,
                    'chargeable_volume_liters' => $chargeableVolumeLiters,
                    'shipping_cluster_id' => $result->metadata['shipping_cluster_id'] ?? $extra['active_fixation']['shipping_cluster_id'] ?? null,
                    'shipping_cluster_name' => $result->metadata['shipping_cluster_name'] ?? $extra['active_fixation']['shipping_cluster_name'] ?? null,
                    'fixation_applied' => $result->metadata['fixation_applied'] ?? $extra['active_fixation']['fixation_applied'] ?? null,
                    'fixation_id' => $result->metadata['fixation_id'] ?? $extra['active_fixation']['fixation_id'] ?? null,
                    'fixation_base_date' => $result->metadata['fixation_base_date'] ?? $extra['active_fixation']['fixation_base_date'] ?? null,
                    'fixed_until' => $result->metadata['fixed_until'] ?? $extra['active_fixation']['fixed_until'] ?? null,
                    'tariff_version_used' => $result->metadata['tariff_version_used'] ?? $extra['active_fixation']['tariff_version_used'] ?? null,
                    'markup_version_used' => $result->metadata['markup_version_used'] ?? $extra['active_fixation']['markup_version_used'] ?? null,
                    'calculation_mode' => $result->metadata['calculation_mode'] ?? $extra['active_fixation']['calculation_mode'] ?? null,
                    'clusters_summary' => $extra['clusters_summary'] ?? [],
                    'stock_profile' => $extra['stock_profile'] ?? [],
                    'sales_profile' => $extra['sales_profile'] ?? [],
                    'route_details' => $extra['route_details'] ?? [],
                    'order_economics_summary' => $extra['order_economics_summary'] ?? [],
                    'shipping_routes' => $extra['shipping_routes'] ?? [],
                    'markup_rule_reason' => $extra['markup_rule_reason'] ?? null,
                    'markup_rule_reason_label' => $extra['markup_rule_reason_label'] ?? null,
                    'sales_7_days' => $extra['sales_7_days'] ?? null,
                    'profile_data_sources' => $extra['profile_data_sources'] ?? [],
                    'is_premium' => $extra['is_premium'] ?? false,
                    'premium_mode' => $extra['premium_mode'] ?? 'fallback',
                    'premium_recommendation' => $extra['premium_recommendation'] ?? null,
                    'profit_min' => round(min($profitRangeValues), 2),
                    'profit_base' => round($netProfit, 2),
                    'profit_max' => round(max($profitRangeValues), 2),
                ]
            ),
            'price' => $result->price,
            'old_price' => $result->oldPrice ?? $product->old_price,
            'sales_count' => $extra['sales_count'] ?? 0,
            'is_in_promotion' => $extra['is_in_promotion'] ?? false,
            'promotion_discount' => $extra['promotion_discount'] ?? 0,
            'volume_liters' => $volumeLiters,
            'volume_weight' => $volumeWeight,
            'depth' => $product->depth ?? $inputData['length_mm'] ?? null,
            'width' => $product->width ?? $inputData['width_mm'] ?? null,
            'height' => $product->height ?? $inputData['height_mm'] ?? null,
            'weight' => $product->weight ?? $inputData['weight_g'] ?? null,
            // Комиссия
            'commission_percent' => $result->commissionPercent,
            'commission_amount' => $costs->commission,
            // Ozon vNext: route/locality and tariff metadata
            'tariff_source' => $result->metadata['tariff_source'] ?? $extra['tariff_source'] ?? null,
            'tariff_effective_from' => $result->metadata['tariff_effective_from'] ?? $extra['tariff_effective_from'] ?? null,
            'tariff_version' => $result->metadata['tariff_version'] ?? null,
            'route_key' => $result->metadata['route_key'] ?? $extra['route_key'] ?? null,
            'route_label' => $result->metadata['route_label'] ?? $extra['route_label'] ?? null,
            'is_local_sale' => $result->metadata['is_local_sale'] ?? $extra['is_local_sale'] ?? null,
            'non_local_markup_percent' => $result->metadata['non_local_markup_percent'] ?? $extra['non_local_markup_percent'] ?? 0,
            'price_segment' => $result->metadata['price_segment'] ?? $extra['price_segment'] ?? null,
            'sales_fee_percent' => $result->metadata['sales_fee_percent'] ?? $result->commissionPercent,
            // Legacy Ozon localization fields are kept physically but no longer used
            'avg_delivery_time_hours' => 0,
            'logistics_coefficient' => $result->marketplace === 'wildberries'
                ? (float) ($result->metadata['localization_index'] ?? $inputData['localization_index'] ?? 1)
                : 1,
            'additional_commission_percent' => 0,
            'tariff_status' => null,
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
            'markup_multiplier' => $markupPercent,
            'roi_percent' => $roiPercent,
            // Метаданные
            'calculated_at' => now(),
            'data_version' => 2, // Новая версия с доменной архитектурой
        ];
    }

    /**
     * Рассчитать коэффициент времени доставки (для Ozon FBO)
     *
     * @deprecated Логистика теперь рассчитывается через OzonPricingMatrix и кластерную систему.
     *             Метод оставлен для обратной совместимости, не используется в новой архитектуре.
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
            'markup_multiplier' => $calculated['markup_multiplier'] ?? ($calculated['markup_percent'] ?? 0),
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
            'yandex', 'yandex_market' => ['FBY', 'FBS', 'DBS', 'EXPRESS'],
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

    private function mergeOzonRealOrdersClustersSummary(
        array $realOrderClusters,
        array $baseClustersSummary,
        array $stockProfile,
        bool $markupAllowed = true,
        string $markupDisabledReason = 'fbo_lt_50_orders_7d'
    ): array {
        $lookup = $this->buildOzonClusterLookup($baseClustersSummary);
        $pricing = app(OzonPricingMatrix::class);
        // Канонизация имён остатков через pricing matrix (единый источник с Calculator).
        $stockClusterCanonical = collect($stockProfile)
            ->pluck('cluster_name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn (string $name) => $pricing->resolveClusterName($name))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return array_map(function (array $cluster) use ($lookup, $stockClusterCanonical, $pricing, $markupAllowed, $markupDisabledReason): array {
            $matched = $this->findOzonClusterMatch($cluster, $lookup);
            $clusterName = $cluster['cluster_name'] ?? $matched['cluster_name'] ?? null;
            $canonicalName = is_string($clusterName) ? $pricing->resolveClusterName($clusterName) : null;
            $isLocalCluster = array_key_exists('is_local_cluster', $cluster)
                ? (bool) $cluster['is_local_cluster']
                : ($canonicalName !== null && in_array($canonicalName, $stockClusterCanonical, true));
            $resolvedRoute = $pricing->resolveRoute(null, is_string($clusterName) ? $clusterName : null);
            $nonLocalMarkupPercent = $pricing->resolveDestinationMarkupPercent(
                is_string($clusterName) ? $clusterName : null,
                $pricing->getEffectiveFrom()
            );
            if (! $markupAllowed || $isLocalCluster) {
                $effectiveMarkupPercent = 0.0;
            } elseif (array_key_exists('effective_markup_percent', $cluster) && $cluster['effective_markup_percent'] !== null) {
                $effectiveMarkupPercent = (float) $cluster['effective_markup_percent'];
            } else {
                $effectiveMarkupPercent = $nonLocalMarkupPercent;
            }
            $markupReason = $cluster['markup_reason']
                ?? $matched['markup_reason']
                ?? (! $markupAllowed
                    ? $markupDisabledReason
                    : ($isLocalCluster ? 'local_cluster' : ($nonLocalMarkupPercent > 0 ? 'non_local_markup_applied' : 'no_markup_for_cluster')));

            return [
                'cluster_id' => $cluster['cluster_id'] ?? $matched['cluster_id'] ?? null,
                'cluster_name' => $clusterName,
                'region' => $cluster['region'] ?? $matched['region'] ?? null,
                'orders_count' => (int) ($cluster['orders_count'] ?? 0),
                'orders_percent' => isset($cluster['orders_percent']) ? (float) $cluster['orders_percent'] : 0.0,
                'delivery_time_fbo' => $cluster['delivery_time_fbo'] ?? $matched['delivery_time_fbo'] ?? null,
                'delivery_time_fbs' => $cluster['delivery_time_fbs'] ?? $matched['delivery_time_fbs'] ?? null,
                'is_local_cluster' => $isLocalCluster,
                'route_key' => $cluster['route_key'] ?? $matched['route_key'] ?? $resolvedRoute['route_key'] ?? null,
                'route_label' => $cluster['route_label'] ?? $matched['route_label'] ?? $resolvedRoute['route_label'] ?? null,
                'non_local_markup_percent' => $nonLocalMarkupPercent,
                'effective_markup_percent' => $effectiveMarkupPercent,
                'markup_reason' => $markupReason,
            ];
        }, $realOrderClusters);
    }

    private function ozonOrderEconomicsHasOnlyExcludedMarkup(array $summary): bool
    {
        $ordersCount = (int) ($summary['orders_count'] ?? 0);
        $markupAmount = (float) ($summary['avg_non_local_markup_amount'] ?? 0);
        $reasonCodes = is_array($summary['markup_reason_codes'] ?? null)
            ? $summary['markup_reason_codes']
            : [];
        if ($ordersCount <= 0 || abs($markupAmount) > 0.0001 || $reasonCodes === []) {
            return false;
        }

        $excludedReasons = [
            'cancelled_order',
            'not_redeemed',
            'local_cluster',
            'fbo_lt_50_orders_7d',
            'zero_markup_cluster',
        ];
        $excludedCount = 0;
        foreach ($reasonCodes as $reason => $count) {
            if (in_array((string) $reason, $excludedReasons, true)) {
                $excludedCount += (int) $count;
            }
        }

        return $excludedCount >= $ordersCount;
    }

    private function zeroOzonEffectiveMarkup(array $clusters, string $reason): array
    {
        return array_map(static function (array $cluster) use ($reason): array {
            $cluster['effective_markup_percent'] = 0.0;
            $cluster['markup_reason'] = $reason;

            return $cluster;
        }, $clusters);
    }

    private function mergeOzonSalesProfileWithClustersSummary(array $salesProfile, array $clustersSummary): array
    {
        $clusters = is_array($salesProfile['clusters'] ?? null) ? $salesProfile['clusters'] : $salesProfile;
        $lookup = $this->buildOzonClusterLookup($clustersSummary);

        $merged = array_map(function (array $cluster) use ($lookup): array {
            $matched = $this->findOzonClusterMatch($cluster, $lookup);

            return array_merge($cluster, array_filter([
                'cluster_id' => $cluster['cluster_id'] ?? $matched['cluster_id'] ?? null,
                'cluster_name' => $cluster['cluster_name'] ?? $matched['cluster_name'] ?? null,
                'is_local_cluster' => $matched['is_local_cluster'] ?? null,
                'route_key' => $matched['route_key'] ?? null,
                'route_label' => $matched['route_label'] ?? null,
                'non_local_markup_percent' => isset($matched['non_local_markup_percent'])
                    ? (float) $matched['non_local_markup_percent']
                    : null,
                'effective_markup_percent' => isset($matched['effective_markup_percent'])
                    ? (float) $matched['effective_markup_percent']
                    : null,
                'markup_reason' => $matched['markup_reason'] ?? null,
            ], static fn ($value) => $value !== null));
        }, $clusters);

        return is_array($salesProfile['clusters'] ?? null) ? ['clusters' => $merged] : $merged;
    }

    private function buildOzonClusterLookup(array $clusters): array
    {
        $lookup = [];

        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }

            $clusterId = isset($cluster['cluster_id']) && $cluster['cluster_id'] !== '' ? (string) $cluster['cluster_id'] : null;
            $clusterNameKey = $this->normalizeClusterKey($cluster['cluster_name'] ?? null);

            if ($clusterId !== null) {
                $lookup['id:' . $clusterId] = $cluster;
            }

            if ($clusterNameKey !== null) {
                $lookup['name:' . $clusterNameKey] = $cluster;
            }
        }

        return $lookup;
    }

    private function findOzonClusterMatch(array $cluster, array $lookup): array
    {
        $clusterId = isset($cluster['cluster_id']) && $cluster['cluster_id'] !== '' ? (string) $cluster['cluster_id'] : null;
        if ($clusterId !== null && isset($lookup['id:' . $clusterId])) {
            return $lookup['id:' . $clusterId];
        }

        $clusterNameKey = $this->normalizeClusterKey($cluster['cluster_name'] ?? null);
        if ($clusterNameKey !== null && isset($lookup['name:' . $clusterNameKey])) {
            return $lookup['name:' . $clusterNameKey];
        }

        return [];
    }

    private function normalizeClusterKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return $normalized !== '' ? $normalized : null;
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
    private function getAverageWarehouseCoefficient(int $integrationId, string $sku, string $marketplace): float
    {
        $cacheKey = $integrationId . '|' . $marketplace . '|' . $sku;
        if (array_key_exists($cacheKey, $this->warehouseCoefficientCache)) {
            return $this->warehouseCoefficientCache[$cacheKey];
        }

        $warehouses = InventoryWarehouse::where('sku', $sku)
            ->where('integration_id', $integrationId)
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

    /**
     * Per-товарный КС WB + детализация по складам из wb_data.stock_warehouses.
     *
     * Единый источник для ОТОБРАЖЕНИЯ (число КС + тултип со складами). Формула
     * полностью повторяет расчётный warmRecalculateChunkCaches(): взвешенный по
     * остаткам коэффициент складов товара, а при отсутствии остатков — средний КС
     * по магазину (а не молчаливые 100%). При правке держать в синхроне с
     * warmRecalculateChunkCaches().
     *
     * @return array{coefficient: float, percent: float, details: array, has_stock: bool, integration_avg: float}
     */
    public function resolveWildberriesWarehouseBreakdown(int $integrationId, array $wbData): array
    {
        $this->warmWildberriesTariffSnapshotCache($integrationId);
        $snapshotCache = $this->wildberriesTariffSnapshotCache[$integrationId] ?? ['box_by_warehouse' => []];
        $boxByWarehouse = is_array($snapshotCache['box_by_warehouse'] ?? null) ? $snapshotCache['box_by_warehouse'] : [];

        $coefFromSnapshot = function ($snapshot, bool $marketplace): ?float {
            if (! $snapshot) {
                return null;
            }
            $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
            $keys = $marketplace
                ? ['delivery_marketplace_coef_percent', 'boxDeliveryMarketplaceCoefExpr']
                : ['delivery_coef_percent', 'boxDeliveryCoefExpr'];
            foreach ($keys as $k) {
                $v = $payload[$k] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $v = is_string($v) ? str_replace(',', '.', $v) : $v;
                if (is_numeric($v) && (float) $v > 0) {
                    return (float) $v / 100;
                }
            }
            return null;
        };

        // Средний КС магазина — разумный дефолт для товаров без остатка.
        $allCoefs = [];
        foreach ($boxByWarehouse as $snap) {
            $c = $coefFromSnapshot($snap, false);
            if ($c !== null) {
                $allCoefs[] = $c;
            }
        }
        $integrationAvgCoef = $allCoefs !== [] ? array_sum($allCoefs) / count($allCoefs) : 1.0;

        $stockWarehouses = is_array($wbData['stock_warehouses'] ?? null) ? $wbData['stock_warehouses'] : [];
        $weightedSum = 0.0;
        $totalQuantity = 0;
        $details = [];
        foreach ($stockWarehouses as $w) {
            $qty = (int) ($w['quantity'] ?? 0);
            $name = (string) ($w['warehouse_name'] ?? '');
            if ($qty <= 0 || $name === '') {
                continue;
            }
            $isMarketplace = in_array(strtoupper((string) ($w['fulfillment_type'] ?? '')), ['FBS', 'DBW', 'DBS', 'EDBS'], true);
            $snap = $boxByWarehouse[$this->normalizeWildberriesWarehouseName($name)] ?? null;
            $coef = $coefFromSnapshot($snap, $isMarketplace) ?? $integrationAvgCoef;
            $weightedSum += $coef * $qty;
            $totalQuantity += $qty;
            $details[] = [
                'warehouse_name' => $name,
                'coefficient_raw' => round($coef, 3),
                'coefficient' => round($coef * 100, 0),
                'quantity' => $qty,
            ];
        }

        $coefficient = $totalQuantity > 0
            ? round($weightedSum / $totalQuantity, 4)
            : round($integrationAvgCoef, 4);

        foreach ($details as &$detail) {
            $detail['share_percent'] = $totalQuantity > 0
                ? round(($detail['quantity'] / $totalQuantity) * 100, 2)
                : 0.0;
        }
        unset($detail);

        return [
            'coefficient' => $coefficient,
            'percent' => round($coefficient * 100, 0),
            'details' => $details,
            'has_stock' => $totalQuantity > 0,
            'integration_avg' => round($integrationAvgCoef, 4),
        ];
    }

    private function warmRecalculateChunkCaches(Collection $products, array $schemes, Integration $integration): void
    {
        $skus = $products->pluck('sku')
            ->filter(fn ($sku) => filled($sku))
            ->map(fn ($sku) => (string) $sku)
            ->unique()
            ->values();

        if ($skus->isEmpty()) {
            return;
        }

        UnitEconomicsSettings::where('integration_id', $integration->id)
            ->whereIn('sku', $skus)
            ->get()
            ->each(function (UnitEconomicsSettings $settings) use ($integration) {
                $this->settingsCache[$integration->id.'|'.$settings->sku] = $settings;
            });

        foreach ($skus as $sku) {
            $this->settingsCache[$integration->id.'|'.$sku] ??= null;
        }

        $normalizedSchemes = collect($schemes)->map(fn ($scheme) => strtoupper((string) $scheme))->all();
        UnitEconomics::where('integration_id', $integration->id)
            ->whereIn('sku', $skus)
            ->whereIn('fulfillment_type', $normalizedSchemes)
            ->get()
            ->each(function (UnitEconomics $unitEconomics) use ($integration) {
                $key = $integration->id.'|'.$unitEconomics->sku.'|'.strtoupper((string) $unitEconomics->fulfillment_type);
                $this->unitEconomicsCache[$key] = $unitEconomics;
            });

        foreach ($skus as $sku) {
            foreach ($normalizedSchemes as $scheme) {
                $this->unitEconomicsCache[$integration->id.'|'.$sku.'|'.$scheme] ??= null;
            }
        }

        if ($integration->marketplace !== 'wildberries') {
            return;
        }

        $this->warmWildberriesTariffSnapshotCache((int) $integration->id);

        // КС (коэффициент склада) считаем средневзвешенно по ОСТАТКАМ из wb_data.stock_warehouses,
        // где реальные имена складов WB (Электросталь, Коледино…). Коэффициент тянем из box-снапшотов
        // по имени склада (FBS — маркетплейс-доставка). InventoryWarehouse тут не годится: там все
        // остатки под общим «Мой склад» и без коэффициента.
        $snapshotCache = $this->wildberriesTariffSnapshotCache[$integration->id] ?? ['box_by_warehouse' => []];
        $boxByWarehouse = is_array($snapshotCache['box_by_warehouse'] ?? null) ? $snapshotCache['box_by_warehouse'] : [];

        $coefFromSnapshot = function ($snapshot, bool $marketplace): ?float {
            if (! $snapshot) {
                return null;
            }
            $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
            $keys = $marketplace
                ? ['delivery_marketplace_coef_percent', 'boxDeliveryMarketplaceCoefExpr']
                : ['delivery_coef_percent', 'boxDeliveryCoefExpr'];
            foreach ($keys as $k) {
                $v = $payload[$k] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $v = is_string($v) ? str_replace(',', '.', $v) : $v;
                if (is_numeric($v) && (float) $v > 0) {
                    return (float) $v / 100;
                }
            }
            return null;
        };

        // Средний КС магазина — разумный дефолт для товаров без остатка (вместо молчаливых 100%).
        $allCoefs = [];
        foreach ($boxByWarehouse as $snap) {
            $c = $coefFromSnapshot($snap, false);
            if ($c !== null) {
                $allCoefs[] = $c;
            }
        }
        $integrationAvgCoef = $allCoefs !== [] ? array_sum($allCoefs) / count($allCoefs) : 1.0;

        foreach ($products as $product) {
            $sku = (string) $product->sku;
            if ($sku === '') {
                continue;
            }
            $cacheKey = $integration->id.'|wildberries|'.$sku;

            $wbData = is_array($product->wb_data ?? null) ? $product->wb_data : [];
            $stockWarehouses = is_array($wbData['stock_warehouses'] ?? null) ? $wbData['stock_warehouses'] : [];

            $weightedSum = 0.0;
            $totalQuantity = 0;
            foreach ($stockWarehouses as $w) {
                $qty = (int) ($w['quantity'] ?? 0);
                $name = (string) ($w['warehouse_name'] ?? '');
                if ($qty <= 0 || $name === '') {
                    continue;
                }
                $isMarketplace = in_array(strtoupper((string) ($w['fulfillment_type'] ?? '')), ['FBS', 'DBW', 'DBS', 'EDBS'], true);
                $snap = $boxByWarehouse[$this->normalizeWildberriesWarehouseName($name)] ?? null;
                $coef = $coefFromSnapshot($snap, $isMarketplace) ?? $integrationAvgCoef;
                $weightedSum += $coef * $qty;
                $totalQuantity += $qty;
            }

            $this->warehouseCoefficientCache[$cacheKey] = $totalQuantity > 0
                ? round($weightedSum / $totalQuantity, 4)
                : round($integrationAvgCoef, 4);
        }
    }

    private function warmWildberriesTariffSnapshotCache(int $integrationId): void
    {
        if (isset($this->wildberriesTariffSnapshotCache[$integrationId])) {
            return;
        }

        $snapshots = WildberriesTariffSnapshot::where('integration_id', $integrationId)
            ->whereIn('tariff_type', ['box', 'return'])
            ->orderByDesc('effective_date')
            ->orderByDesc('fetched_at')
            ->get();

        $boxByWarehouse = [];
        $boxFallback = null;
        $returnPayload = [];

        foreach ($snapshots as $snapshot) {
            if ($snapshot->tariff_type === 'return' && $returnPayload === []) {
                $returnPayload = is_array($snapshot->payload) ? $snapshot->payload : [];
                continue;
            }

            if ($snapshot->tariff_type !== 'box') {
                continue;
            }

            $boxFallback ??= $snapshot;

            $warehouseName = $snapshot->warehouse_name ? $this->normalizeWildberriesWarehouseName((string) $snapshot->warehouse_name) : null;
            if ($warehouseName && ! isset($boxByWarehouse[$warehouseName])) {
                $boxByWarehouse[$warehouseName] = $snapshot;
            }
        }

        $this->wildberriesTariffSnapshotCache[$integrationId] = [
            'box_by_warehouse' => $boxByWarehouse,
            'box_fallback' => $boxFallback,
            'return' => $returnPayload,
        ];
    }

    private function resolveWildberriesTariffBreakdown(int $integrationId, string $fulfillmentType, array $marketplaceData, array $existing): array
    {
        if (isset($existing['box']) || isset($existing['source'])) {
            return $existing;
        }

        $this->warmWildberriesTariffSnapshotCache($integrationId);
        $snapshotCache = $this->wildberriesTariffSnapshotCache[$integrationId] ?? [
            'box_by_warehouse' => [],
            'box_fallback' => null,
            'return' => [],
        ];

        $warehouseName = $this->resolveWildberriesTariffWarehouseName($marketplaceData);
        if ($warehouseName !== null) {
            $matching = $snapshotCache['box_by_warehouse'][$this->normalizeWildberriesWarehouseName($warehouseName)] ?? null;
            if ($matching) {
                return [
                    'source' => 'wildberries_tariff_snapshots',
                    'effective_date' => optional($matching->effective_date)->toDateString(),
                    'warehouse_name' => $matching->warehouse_name,
                    'scheme' => strtoupper($fulfillmentType),
                    'box' => $matching->payload ?? [],
                    'return' => $snapshotCache['return'] ?? [],
                ];
            }
        }

        $fallback = $snapshotCache['box_fallback'] ?? null;

        if (! $fallback) {
            return $existing;
        }

        return [
            'source' => 'wildberries_tariff_snapshots_fallback',
            'effective_date' => optional($fallback->effective_date)->toDateString(),
            'warehouse_name' => $fallback->warehouse_name,
            'scheme' => strtoupper($fulfillmentType),
            'box' => $fallback->payload ?? [],
            'return' => $snapshotCache['return'] ?? [],
        ];
    }

    private function latestWildberriesReturnTariffPayload(int $integrationId): array
    {
        $this->warmWildberriesTariffSnapshotCache($integrationId);
        if (isset($this->wildberriesTariffSnapshotCache[$integrationId])) {
            return $this->wildberriesTariffSnapshotCache[$integrationId]['return'] ?? [];
        }

        $snapshot = WildberriesTariffSnapshot::where('integration_id', $integrationId)
            ->where('tariff_type', 'return')
            ->orderByDesc('effective_date')
            ->first();

        return is_array($snapshot?->payload) ? $snapshot->payload : [];
    }

    private function resolveWildberriesTariffWarehouseName(array $marketplaceData): ?string
    {
        $warehouses = $marketplaceData['stock_warehouses'] ?? [];
        if (! is_array($warehouses)) {
            return null;
        }

        $withStock = collect($warehouses)
            ->filter(fn ($warehouse) => (int) ($warehouse['quantity'] ?? 0) > 0 && filled($warehouse['warehouse_name'] ?? null))
            ->sortByDesc(fn ($warehouse) => (int) ($warehouse['quantity'] ?? 0))
            ->first();

        if ($withStock && filled($withStock['warehouse_name'] ?? null)) {
            return (string) $withStock['warehouse_name'];
        }

        $first = collect($warehouses)->first(fn ($warehouse) => filled($warehouse['warehouse_name'] ?? null));

        return $first ? (string) $first['warehouse_name'] : null;
    }

    private function normalizeWildberriesWarehouseName(string $warehouseName): string
    {
        $warehouseName = mb_strtolower(trim($warehouseName));
        $warehouseName = str_replace(['-', '–', '—'], ' ', $warehouseName);
        $warehouseName = preg_replace('/\s+/', ' ', $warehouseName) ?: $warehouseName;

        return trim($warehouseName);
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

    private function forgetUnitEconomicsCache(int $integrationId, string $sku, ?string $fulfillmentType = null): void
    {
        if ($fulfillmentType !== null) {
            $key = $integrationId . '|' . $sku . '|' . strtoupper($fulfillmentType);
            unset($this->unitEconomicsCache[$key]);
            return;
        }

        $prefix = $integrationId . '|' . $sku . '|';
        foreach (array_keys($this->unitEconomicsCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->unitEconomicsCache[$key]);
            }
        }
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
