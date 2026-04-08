<?php

namespace App\Console\Commands;

use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Models\OzonSkuDeliveryProfile;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Services\LocalizationIndexService;
use App\Services\Ozon\OzonOrderUnitEconomicsService;
use App\Services\Ozon\OzonSupplyFixationService;
use App\Services\Ozon\OzonSupplySyncService;
use App\Services\PostingService;
use App\Services\UnitEconomicsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncUnitEconomicsCommand extends Command
{
    protected $signature = 'unit-economics:sync 
                            {--integration= : Integration ID to sync}
                            {--marketplace= : Marketplace to sync (wildberries, ozon, yandex)}
                            {--all : Sync all integrations}';

    protected $description = 'Sync unit economics from real product data';

    public function handle(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService
    ): int
    {
        $integrationId = $this->option('integration');
        $marketplace = $this->option('marketplace');
        $syncAll = $this->option('all');

        if ($syncAll) {
            return $this->syncAll($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService);
        }

        if ($integrationId) {
            return $this->syncByIntegrationId($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, (int) $integrationId);
        }

        if ($marketplace) {
            return $this->syncByMarketplace($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, $marketplace);
        }

        $this->error('Please specify --integration=ID, --marketplace=NAME, or --all');

        return 1;
    }

    private function syncAll(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService
    ): int
    {
        $integrations = Product::select('integration_id', 'marketplace')
            ->whereNotNull('integration_id')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->groupBy('integration_id', 'marketplace')
            ->get();

        $this->info("Found {$integrations->count()} integrations to sync");

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($integrations as $int) {
            $this->line("Syncing integration_id={$int->integration_id} ({$int->marketplace})...");

            $result = $this->syncProducts($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, $int->integration_id, $int->marketplace);

            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'];

            $this->info("  Synced: {$result['synced']}, Errors: {$result['errors']}");
        }

        $this->newLine();
        $this->info("Total synced: {$totalSynced}, Total errors: {$totalErrors}");

        return 0;
    }

    private function syncByIntegrationId(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService,
        int $integrationId
    ): int
    {
        $marketplace = Product::where('integration_id', $integrationId)->value('marketplace');

        if (! $marketplace) {
            $this->error("No products found for integration_id={$integrationId}");

            return 1;
        }

        if ($marketplace === 'yandex') {
            $marketplace = 'yandex_market';
        }

        $this->info("Syncing integration_id={$integrationId} ({$marketplace})...");

        $result = $this->syncProducts($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, $integrationId, $marketplace);

        $this->info("Synced: {$result['synced']}, Errors: {$result['errors']}");

        return 0;
    }

    private function syncByMarketplace(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService,
        string $marketplace
    ): int
    {
        $integrations = Product::where('marketplace', $marketplace)
            ->whereNotNull('integration_id')
            ->select('integration_id')
            ->distinct()
            ->pluck('integration_id');

        if ($integrations->isEmpty()) {
            $this->error("No integrations found for marketplace={$marketplace}");

            return 1;
        }

        $this->info("Found {$integrations->count()} integrations for {$marketplace}");

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($integrations as $integrationId) {
            $this->line("Syncing integration_id={$integrationId}...");

            $result = $this->syncProducts($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, $integrationId, $marketplace);

            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'];
        }

        $this->info("Total synced: {$totalSynced}, Total errors: {$totalErrors}");

        return 0;
    }

    private function syncProducts(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService,
        int $integrationId,
        string $marketplace
    ): array
    {
        $products = Product::where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->get();

        if ($products->isEmpty()) {
            return ['synced' => 0, 'errors' => 0];
        }

        // Получаем настройки интеграции (avg_delivery_time_hours и др.)
        $integration = \App\Models\Integration::find($integrationId);
        $integrationSettings = $integration?->settings ?? [];

        // Загружаем себестоимость из UnitEconomicsSettings (приоритет — ввод пользователя)
        $skus = $products->pluck('sku');
        $vendorCodes = $products->pluck('vendor_code')->filter();
        $costPriceSettings = \App\Models\UnitEconomicsSettings::where('integration_id', $integrationId)
            ->where(function ($q) use ($skus, $vendorCodes) {
                $q->whereIn('sku', $skus);
                if ($vendorCodes->isNotEmpty()) {
                    $q->orWhereIn('sku', $vendorCodes);
                }
            })
            ->where('cost_price', '>', 0)
            ->get(['sku', 'cost_price'])
            ->keyBy('sku');

        // Карта vendor_code -> sku для поиска себестоимости по артикулу продавца
        $vendorToSku = $products->filter(fn ($p) => $p->vendor_code)->pluck('sku', 'vendor_code');

        // Агрегируем данные по складам для каждого SKU
        $inventoryRaw = InventoryWarehouse::where('marketplace', $marketplace)
            ->where('integration_id', $integrationId)
            ->whereIn('sku', $skus)
            ->get();

        $inventoryData = $inventoryRaw->groupBy('sku')->map(function ($items) use ($costPriceSettings, $vendorToSku) {
            // Определяем фактическую схему работы по остаткам (где больше товара)
            $byFulfillment = $items->groupBy('fulfillment_type');
            $maxStock = 0;
            $actualFulfillmentType = 'FBO';

            foreach ($byFulfillment as $type => $typeItems) {
                $stock = $typeItems->sum('quantity');
                if ($stock > $maxStock) {
                    $maxStock = $stock;
                    $actualFulfillmentType = strtoupper($type ?? 'FBO');
                }
            }

            // Если остатков нет, берём первую схему из записей
            if ($maxStock === 0 && $items->isNotEmpty()) {
                $actualFulfillmentType = strtoupper($items->first()->fulfillment_type ?? 'FBO');
            }

            $sku = $items->first()->sku;
            // Приоритет себестоимости: UnitEconomicsSettings > inventory_warehouses
            $costFromSettings = $costPriceSettings[$sku]->cost_price ?? null;
            // Также ищем по vendor_code (артикулу продавца)
            if (! $costFromSettings) {
                $vendorCode = array_search($sku, $vendorToSku->toArray());
                if ($vendorCode) {
                    $costFromSettings = $costPriceSettings[$vendorCode]->cost_price ?? null;
                }
            }
            $costFromInventory = $items->max('cost_price');
            $warehousesWithStock = $items->filter(fn ($item) => (int) $item->quantity > 0);
            $totalWarehouseQty = $warehousesWithStock->sum('quantity');
            $warehouseCoefficient = $totalWarehouseQty > 0
                ? $warehousesWithStock->sum(fn ($item) => (float) ($item->warehouse_coefficient ?? 1.0) * (int) $item->quantity) / $totalWarehouseQty
                : (float) ($items->avg(fn ($item) => (float) ($item->warehouse_coefficient ?? 1.0)) ?? 1.0);

            return (object) [
                'sku' => $sku,
                'cost_price' => $costFromSettings ?? $costFromInventory, // Берём из настроек или из inventory
                'sales_7_days' => $items->sum('sales_7_days'),
                'sales_30_days' => $items->sum('sales_30_days'), // Суммируем продажи по всем складам
                'storage_cost_per_month' => $items->sum('storage_cost_per_month'), // Суммируем хранение
                'fulfillment_type' => $actualFulfillmentType, // Схема с наибольшими остатками
                'turnover_days' => $items->first()->turnover_days ?? 30,
                'warehouse_coefficient' => round($warehouseCoefficient, 3),
            ];
        });
        $ozonStockProfiles = [];

        // Получаем фактические затраты и индекс локализации из API (если Ozon)
        $actualCosts = [];
        $localizationIndex = null;
        $acquiringData = [];
        $redemptionData = [];
        $productPrices = []; // Актуальные цены из API (включая акционные)
        $deliveryProfiles = [];
        $ozonDirectSalesProfiles = [];
        $previewFixationMap = [];
        $orderEconomicsPreview = [];
        $manualRedemptionRate = null; // Инициализируем для всех маркетплейсов
        if ($marketplace === 'ozon') {
            try {
                // Приоритет: 1) локальная интеграция, 2) Sellico API, 3) глобальные из config/env
                $clientId = null;
                $apiKey = null;

                // 1. Пробуем локальную интеграцию
                $localCredentials = $this->getIntegrationCredentialsSafely($integration);
                if (! empty($localCredentials['client_id'] ?? null)) {
                    $clientId = $localCredentials['client_id'];
                    $apiKey = $localCredentials['api_key'] ?? '';
                }

                // 2. Пробуем Sellico API
                if (empty($clientId)) {
                    $sellicoService = new \App\Services\SellicoApiService;
                    $sellicoResult = $sellicoService->getIntegrationById($integrationId);

                    if ($sellicoResult['success'] && ! empty($sellicoResult['credentials'])) {
                        $clientId = $sellicoResult['credentials']['client_id'] ?? '';
                        $apiKey = $sellicoResult['credentials']['api_key'] ?? '';
                        $this->info('  Credentials получены из Sellico API');

                        if ($integration && ! empty($clientId) && ! empty($apiKey)) {
                            $this->persistIntegrationCredentials($integration, [
                                'client_id' => $clientId,
                                'api_key' => $apiKey,
                            ]);
                        }
                    }
                }

                // 3. Fallback на глобальные из config/env
                if (empty($clientId)) {
                    $clientId = config('services.ozon.client_id') ?? '';
                    $apiKey = config('services.ozon.api_key') ?? '';
                }

                if (! empty($clientId) && ! empty($apiKey)) {
                    $ozonService = new \App\Domains\Ozon\OzonMarketplace(['client_id' => $clientId, 'api_key' => $apiKey]);
                    $operationalSince = now()->subDays(120)->format('Y-m-d');

                    try {
                        $suppliesSyncResult = $supplySyncService->syncForIntegration($integrationId);
                        $this->info('  Ozon supplies synced: '.json_encode($suppliesSyncResult, JSON_UNESCAPED_UNICODE));
                    } catch (\Throwable $supplySyncException) {
                        $this->warn('  Не удалось синхронизировать Ozon supplies: '.$supplySyncException->getMessage());
                    }

                    try {
                        $postingsSyncResult = $postingService->sync((string) $integrationId, null, $operationalSince);
                        $this->info('  Ozon postings synced: '.json_encode($postingsSyncResult, JSON_UNESCAPED_UNICODE));
                    } catch (\Throwable $postingSyncException) {
                        $this->warn('  Не удалось синхронизировать Ozon postings: '.$postingSyncException->getMessage());
                    }

                    $actualCosts = $ozonService->getActualCostsBySku();

                    // Получаем актуальные цены (включая акционные marketing_seller_price)
                    $productPrices = $ozonService->getProductPrices();
                    $promotionCount = count(array_filter($productPrices, fn ($p) => $p['is_in_promotion'] ?? false));
                    $this->info('  Цены: '.count($productPrices).' товаров, '.$promotionCount.' в акциях');

                    // Логируем примеры товаров в акциях для диагностики
                    $promotionExamples = array_filter($productPrices, fn ($p) => $p['is_in_promotion'] ?? false);
                    $exampleSkus = array_slice(array_keys($promotionExamples), 0, 3);
                    foreach ($exampleSkus as $exSku) {
                        $ex = $promotionExamples[$exSku];
                        $this->info("    Пример акции: {$exSku} - базовая: {$ex['price']}₽, акционная: {$ex['actual_price']}₽ (-{$ex['promotion_discount']}%)");
                    }

                    try {
                        $ozonDirectSalesProfiles = $this->mergeOzonSalesByWarehouse(
                            $ozonService->getSalesBySkuAndWarehouse(28),
                            $ozonService->getSalesBySkuAndWarehouseFbs(28)
                        );
                        $this->info('  Прямые продажи по складам SKU: '.count($ozonDirectSalesProfiles));
                    } catch (\Throwable $salesByWarehouseException) {
                        $this->warn('  Не удалось получить прямые продажи по складам: '.$salesByWarehouseException->getMessage());
                        $ozonDirectSalesProfiles = [];
                    }

                    $ozonStockProfiles = $this->buildOzonStockProfiles($inventoryRaw, $ozonDirectSalesProfiles);

                    try {
                        $deliveryAnalyticsApi = new \App\Domains\Ozon\Api\DeliveryAnalyticsApi($ozonService->api());
                        $deliveryProfiles = $this->buildOzonDeliveryProfiles(
                            $deliveryAnalyticsApi->getSupplyRecommendations([], 'ALL', 'EIGHT_WEEKS'),
                            $ozonStockProfiles
                        );
                        $this->info('  Профили доставки SKU: '.count($deliveryProfiles));
                    } catch (\Throwable $deliveryException) {
                        $this->warn('  Не удалось получить профили доставки SKU: '.$deliveryException->getMessage());
                        $deliveryProfiles = [];
                    }

                    // Получаем индекс локализации (среднее время доставки) из API с TTL 24ч
                    if ($integration && ! $integration->needsLocalizationCheck()) {
                        // Используем кэшированные данные из settings
                        $localizationIndex = [
                            'average_delivery_time' => $integrationSettings['avg_delivery_time_hours'] ?? 29,
                            'tariff_coefficient' => $integrationSettings['localization_coefficient'] ?? 1.0,
                            'additional_fee_percent' => $integrationSettings['localization_additional_percent'] ?? 0,
                            'tariff_status' => $integrationSettings['localization_tariff_status'] ?? 'UNKNOWN',
                        ];
                        $this->info("  Индекс локализации: {$localizationIndex['average_delivery_time']}ч, коэф: {$localizationIndex['tariff_coefficient']}, доп.%: {$localizationIndex['additional_fee_percent']} (кэш)");
                    } else {
                        $localizationIndex = $ozonService->getLocalizationIndex();
                        $this->info("  Индекс локализации: {$localizationIndex['average_delivery_time']}ч, коэф: {$localizationIndex['tariff_coefficient']}, доп.%: {$localizationIndex['additional_fee_percent']} (API)");

                        // Сохраняем в settings интеграции
                        if ($integration) {
                            $newSettings = array_merge($integrationSettings, [
                                'avg_delivery_time_hours' => $localizationIndex['average_delivery_time'],
                                'localization_coefficient' => $localizationIndex['tariff_coefficient'],
                                'localization_additional_percent' => $localizationIndex['additional_fee_percent'],
                                'localization_tariff_status' => $localizationIndex['tariff_status'] ?? 'UNKNOWN',
                            ]);
                            $integration->update([
                                'settings' => $newSettings,
                                'localization_checked_at' => now(),
                            ]);
                            $integrationSettings = $newSettings;
                        }
                    }

                    // === ПРОВЕРКА PREMIUM СТАТУСА (TTL 24ч) ===
                    $storedPremium = $integration?->is_premium;
                    $isPremium = $storedPremium ?? null;
                    $manualRedemptionRate = $integration?->manual_redemption_rate ?? null;

                    if ($integration && ($storedPremium === null || $integration->needsPremiumCheck())) {
                        $premiumStatus = $ozonService->checkPremiumStatus();
                        $detectedPremium = $premiumStatus['is_premium'] ?? null;
                        $reason = (string) ($premiumStatus['reason'] ?? '');

                        if ($storedPremium === true && $detectedPremium !== true) {
                            // Track consecutive non-premium detections
                            $nonPremiumCount = (int) ($integration->non_premium_detection_count ?? 0) + 1;
                            $integration->update(['non_premium_detection_count' => $nonPremiumCount]);

                            if ($nonPremiumCount >= 3) {
                                // Downgrade after 3 consecutive non-premium detections
                                $isPremium = false;
                                $integration->update([
                                    'is_premium' => false,
                                    'non_premium_detection_count' => 0,
                                ]);
                                $this->warn("Integration {$integrationId}: Premium downgraded after {$nonPremiumCount} consecutive non-premium API results");
                            } else {
                                $isPremium = true;
                                $this->info("Integration {$integrationId}: Preserving Premium status (non-premium detection {$nonPremiumCount}/3)");
                            }
                        } else {
                            $isPremium = $detectedPremium ?? ($storedPremium ?? false);

                            // Reset non-premium counter when premium IS detected
                            if ($detectedPremium === true && ($integration->non_premium_detection_count ?? 0) > 0) {
                                $integration->update(['non_premium_detection_count' => 0]);
                            }
                        }

                        // Сохраняем статус в интеграцию (если она существует в локальной БД)
                        if ($integration) {
                            $integration->update([
                                'is_premium' => $isPremium,
                                'premium_checked_at' => now(),
                            ]);
                        }

                        $this->info('  Premium статус: '.($isPremium ? '✓ Premium (точная аналитика)' : '✗ Не Premium (fallback-модель)'));
                    } else {
                        $this->info('  Premium статус: '.($isPremium ? '✓ Premium' : '✗ Не Premium').' (из сохранённого статуса)');
                    }

                    // === ПОЛУЧЕНИЕ ПРОЦЕНТА ВЫКУПА ===
                    if ($isPremium) {
                        // Создаём маппинг ozon_sku -> offer_id для сопоставления данных из API
                        // API аналитики возвращает ozon_data['sku'] (числовой ID в системе Ozon)
                        // offer_id — это артикул продавца (SKU), по которому мы ищем товары
                        $ozonSkuToOfferIdMap = [];
                        Product::where('integration_id', $integrationId)
                            ->whereNotNull('ozon_data')
                            ->chunk(500, function ($products) use (&$ozonSkuToOfferIdMap) {
                                foreach ($products as $product) {
                                    $ozonData = $product->ozon_data ?? [];
                                    // API аналитики использует ozon_data['sku'] (числовой ID Ozon)
                                    $ozonSku = $ozonData['sku'] ?? null;
                                    // offer_id — артикул продавца, совпадает с product.sku
                                    $offerId = $ozonData['offer_id'] ?? $product->sku;
                                    if ($ozonSku) {
                                        $ozonSkuToOfferIdMap[(string) $ozonSku] = $offerId;
                                    }
                                }
                            });

                        $this->info('  Создан маппинг ozon_sku -> offer_id для '.count($ozonSkuToOfferIdMap).' товаров');

                        // Premium: получаем данные автоматически из API аналитики с маппингом
                        $redemptionData = $ozonService->getRedemptionRateFromAnalytics(null, null, $ozonSkuToOfferIdMap);

                        if (! empty($redemptionData)) {
                            $fullDataCount = count(array_filter($redemptionData, fn ($d) => ($d['has_full_data'] ?? false)));
                            $this->info('  Получен выкуп для '.count($redemptionData)." товаров (полных: {$fullDataCount})");

                            // Логируем примеры для диагностики
                            $sampleKeys = array_slice(array_keys($redemptionData), 0, 5);
                            $this->info('  Примеры ключей redemptionData: '.implode(', ', $sampleKeys));
                        } else {
                            $this->warn('  ⚠ API аналитики не вернул данных о выкупе');
                        }
                    } else {
                        // Не Premium: используем ручной ввод или fallback через заказы/возвраты
                        if ($manualRedemptionRate !== null && $manualRedemptionRate > 0) {
                            $this->info("  Используем ручной процент выкупа: {$manualRedemptionRate}%");
                            // Ручной ввод будет применён в buildCalculationData
                        } else {
                            // Fallback: пробуем получить через заказы и возвраты
                            $this->info('  Нет ручного выкупа, пробуем fallback через заказы/возвраты');

                            $dateFrom = now()->subDays(30)->format('Y-m-d');
                            $dateTo = now()->format('Y-m-d');

                            $ordersMap = $ozonService->getOrdersStatsBySku($dateFrom, $dateTo);
                            $returnsMap = $ozonService->getReturnsStatsBySku($dateFrom, $dateTo);

                            foreach ($ordersMap as $offerId => $ordersCount) {
                                $returnsCount = $returnsMap[$offerId] ?? 0;
                                $deliveredCount = $ordersCount - $returnsCount;

                                if ($ordersCount > 0) {
                                    $redemptionRate = round(($deliveredCount / $ordersCount) * 100, 1);
                                    $redemptionData[$offerId] = [
                                        'redemption_rate' => $redemptionRate,
                                        'orders_count' => $ordersCount,
                                        'returns_count' => $returnsCount,
                                        'delivered_count' => $deliveredCount,
                                        'has_full_data' => true,
                                        'source' => 'fallback',
                                    ];
                                }
                            }

                            if (! empty($redemptionData)) {
                                $this->info('  Fallback: получен выкуп для '.count($redemptionData).' товаров');
                            } else {
                                $this->warn('  ⚠ Нет данных о выкупе. Установите manual_redemption_rate в настройках интеграции.');
                            }
                        }
                    }

                    // TODO: Эквайринг из финансовых транзакций отключён (слишком много данных, OOM)
                    // $acquiringData = $ozonService->getAcquiringBySku();
                    // Используем фиксированный 1.5% (стандартная ставка Ozon)
                } else {
                    $this->warn('  Нет credentials для Ozon API');
                }
            } catch (\Exception $e) {
                $this->warn("  Не удалось получить данные из API: {$e->getMessage()}");
            }

            $fixationService->syncForIntegration($integrationId);
            $previewFixationMap = $fixationService->getPreviewFixationMap($integrationId, $ozonStockProfiles);
            $orderUnitEconomicsService->syncForIntegration($integrationId);
            $orderEconomicsPreview = $orderUnitEconomicsService->summarizeForPreview($integrationId);
        }

        // === WILDBERRIES API ===
        $wbSalesData = [];
        $wbStorageData = [];
        $wbTariffsData = [];
        $wbSppData = []; // СПП из статистики продаж
        $wbRedemptionData = [];
        $wbLocalizationByNmId = [];
        $wbCommissionsData = [];

        if ($marketplace === 'wildberries') {
            try {
                $wbApiKey = null;

                // 1. Пробуем локальную интеграцию
                if ($integration && ! empty($integration->credentials['api_key'])) {
                    $wbApiKey = $integration->credentials['api_key'];
                }

                // 2. Пробуем Sellico API
                if (empty($wbApiKey)) {
                    $sellicoService = new \App\Services\SellicoApiService;
                    $sellicoResult = $sellicoService->getIntegrationById($integrationId);

                    if ($sellicoResult['success'] && ! empty($sellicoResult['credentials'])) {
                        $wbApiKey = $sellicoResult['credentials']['api_key'] ?? '';
                        $this->info('  WB Credentials получены из Sellico API');
                    }
                }

                if (! empty($wbApiKey)) {
                    $wbService = new \App\Domains\Wildberries\WildberriesMarketplace(['api_key' => $wbApiKey], $integration);

                    if ($integration) {
                        $localizationService = app(LocalizationIndexService::class);
                        $localizationResult = $localizationService->calculateLocalizationIndex($integration);

                        if (! empty($localizationResult['ktr_by_article'] ?? []) || (int) ($localizationResult['total_orders'] ?? 0) > 0) {
                            $wbLocalizationByNmId = $localizationResult['ktr_by_article'] ?? [];
                            $wbLocalizationIndex = (float) ($localizationResult['localization_index'] ?? 1.0);

                            $newSettings = array_merge($integrationSettings, [
                                'wb_localization_index' => $wbLocalizationIndex,
                                'wb_localization_total_orders' => (int) ($localizationResult['total_orders'] ?? 0),
                            ]);

                            $integration->update([
                                'localization_index' => $wbLocalizationIndex,
                                'localization_checked_at' => now(),
                                'settings' => $newSettings,
                            ]);

                            $integrationSettings = $newSettings;
                            $this->info("  WB ИЛ: {$wbLocalizationIndex} (товаров: ".count($wbLocalizationByNmId).')');
                        } else {
                            $this->warn('  WB ИЛ: нет новых данных, сохраняю текущее значение '.($integrationSettings['wb_localization_index'] ?? $integration?->localization_index ?? 1));
                        }
                    }

                    // Получаем продажи по SKU (7/14/30 дней)
                    $wbSalesData = $wbService->getSalesBySku();
                    if (! empty($wbSalesData)) {
                        $this->info('  WB Продажи: получено для '.count($wbSalesData).' SKU');
                    }

                    // Получаем стоимость хранения по SKU
                    $wbStorageData = $wbService->getStorageCostBySku();
                    if (! empty($wbStorageData)) {
                        $this->info('  WB Хранение: получено для '.count($wbStorageData).' SKU');
                    }

                    // Получаем тарифы на поставку (коэффициенты складов)
                    $wbTariffsData = $wbService->getSupplyTariffs();
                    if (! empty($wbTariffsData)) {
                        $this->info('  WB Тарифы: получено для '.count($wbTariffsData).' складов');
                    }

                    $wbCommissionsData = $wbService->getCommissions();
                    if (! empty($wbCommissionsData)) {
                        $this->info('  WB Комиссии: получено для '.count($wbCommissionsData).' категорий');
                    }

                    // === ПОЛУЧАЕМ СПП ИЗ СТАТИСТИКИ ПРОДАЖ ===
                    $wbSppData = $wbService->getSppFromSales(30); // За последние 30 дней
                    if (! empty($wbSppData)) {
                        $this->info('  WB СПП: получено для '.count($wbSppData).' товаров');
                    }

                    $wbRedemptionData = $wbService->getRedemptionStatsByNmId(30);
                    if (! empty($wbRedemptionData)) {
                        $this->info('  WB Выкуп: получено для '.count($wbRedemptionData).' товаров');
                    }
                    // Ручной % выкупа из интеграции
                    $manualRedemptionRate = $integration?->manual_redemption_rate ?? null;
                    if ($manualRedemptionRate) {
                        $this->info("  WB Ручной % выкупа: {$manualRedemptionRate}%");
                    }
                } else {
                    $this->warn('  Нет credentials для WB API');
                }
            } catch (\Exception $e) {
                $this->warn("  Не удалось получить данные из WB API: {$e->getMessage()}");
            }
        }

        // === YANDEX MARKET API ===
        $yandexSalesData = [];
        $yandexPricesData = [];
        $yandexTariffsData = [];

        if ($marketplace === 'yandex' || $marketplace === 'yandex_market') {
            try {
                $yandexToken = null;
                $yandexCampaignId = null;
                $yandexBusinessId = null;

                // Хелпер: извлечь Yandex credentials из массива (Sellico может хранить api_key+client_id)
                $extractYandexCreds = function (array $creds) use (&$yandexToken, &$yandexCampaignId, &$yandexBusinessId): bool {
                    $token = $creds['token'] ?? $creds['api_key'] ?? null;
                    $campaignId = $creds['campaign_id'] ?? $creds['client_id'] ?? null;
                    $businessId = $creds['business_id'] ?? null;
                    if (! empty($token) && ! empty($campaignId)) {
                        $yandexToken = $token;
                        $yandexCampaignId = $campaignId;
                        $yandexBusinessId = $businessId;
                        return true;
                    }
                    return false;
                };

                // 1. Пробуем локальную интеграцию
                if ($integration) {
                    $extractYandexCreds($integration->credentials ?? []);
                }

                // 2. Пробуем Sellico API (сервис-аккаунт)
                if (empty($yandexToken)) {
                    $sellicoService = new \App\Services\SellicoApiService;
                    \Illuminate\Support\Facades\Cache::forget("sellico_integration_{$integrationId}");
                    $sellicoResult = $sellicoService->getIntegrationById($integrationId);

                    if ($sellicoResult['success'] && ! empty($sellicoResult['credentials'])) {
                        if ($extractYandexCreds($sellicoResult['credentials'])) {
                            $this->info('  Yandex Credentials получены из Sellico API (service account)');
                        }
                    }
                }

                // 3. Пробуем кешированный токен пользователя (из веб-запросов через middleware)
                if (empty($yandexToken)) {
                    $workspaceId = $integration?->work_space_id;
                    if ($workspaceId) {
                        $cachedUserToken = \Illuminate\Support\Facades\Cache::get("workspace_user_token:{$workspaceId}");
                        if ($cachedUserToken) {
                            $sellicoService2 = new \App\Services\SellicoApiService;
                            $sellicoService2->setAccessToken($cachedUserToken);
                            \Illuminate\Support\Facades\Cache::forget("sellico_integration_{$integrationId}");
                            $sellicoResult2 = $sellicoService2->getIntegrationById($integrationId);

                            if ($sellicoResult2['success'] && ! empty($sellicoResult2['credentials'])) {
                                if ($extractYandexCreds($sellicoResult2['credentials'])) {
                                    $this->info("  Yandex Credentials получены через workspace user token (workspace={$workspaceId})");
                                }
                            }
                        } else {
                            $this->warn("  Нет кешированного токена пользователя для workspace={$workspaceId}");
                        }
                    }
                }

                // Сохраняем credentials локально для следующих синков (без Sellico)
                if (! empty($yandexToken) && ! empty($yandexCampaignId) && $integration) {
                    $existingCreds = $integration->credentials ?? [];
                    $existingKey = $existingCreds['token'] ?? $existingCreds['api_key'] ?? null;
                    $existingCampaign = $existingCreds['campaign_id'] ?? $existingCreds['client_id'] ?? null;
                    if (empty($existingKey) || empty($existingCampaign)) {
                        $integration->credentials = array_merge($existingCreds, [
                            'api_key'     => $yandexToken,
                            'client_id'   => $yandexCampaignId,
                            'business_id' => $yandexBusinessId,
                        ]);
                        $integration->save();
                        $this->info('  Yandex Credentials сохранены локально');
                    }
                }

                if (! empty($yandexToken) && ! empty($yandexCampaignId)) {
                    $yandexService = new \App\Domains\YandexMarket\YandexMarketMarketplace([
                        'api_key'     => $yandexToken,
                        'client_id'   => $yandexCampaignId,
                        'business_id' => $yandexBusinessId,
                    ]);

                    // Получаем продажи по SKU
                    $yandexSalesData = $yandexService->getSalesBySku();
                    if (! empty($yandexSalesData)) {
                        $this->info('  Yandex Продажи: получено для '.count($yandexSalesData).' SKU');
                    }

                    // Получаем актуальные цены
                    $yandexPricesData = $yandexService->getProductPrices();
                    if (! empty($yandexPricesData)) {
                        $this->info('  Yandex Цены: получено для '.count($yandexPricesData).' товаров');
                    }

                    $yandexTariffService = new \App\Services\Marketplace\YandexMarketService(
                        $yandexToken,
                        (string) $yandexCampaignId,
                        $yandexBusinessId !== null ? (string) $yandexBusinessId : null
                    );
                    $yandexTariffsData = $this->loadYandexTariffsData($products, $yandexPricesData, $yandexTariffService, (string) $yandexCampaignId);
                    if (! empty($yandexTariffsData)) {
                        $this->info('  Yandex Тарифы: подготовлены для '.count($yandexTariffsData['FBY'] ?? []).' товаров');
                    }

                    // Ручной % выкупа из интеграции
                    $manualRedemptionRate = $integration?->manual_redemption_rate ?? null;
                    if ($manualRedemptionRate) {
                        $this->info("  Yandex Ручной % выкупа: {$manualRedemptionRate}%");
                    }
                } else {
                    $this->warn('  Нет credentials для Yandex API (token или campaign_id)');
                }
            } catch (\Exception $e) {
                $this->warn("  Не удалось получить данные из Yandex API: {$e->getMessage()}");
            }
        }

        $synced = 0;
        $errors = 0;

        // Схемы работы по маркетплейсам
        $fulfillmentTypes = match ($marketplace) {
            'ozon' => ['FBO', 'FBS', 'RFBS', 'EXPRESS'],
            'wildberries' => ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'],
            'yandex', 'yandex_market' => ['FBY', 'FBS', 'DBS', 'EXPRESS'],
            default => [null],
        };

        foreach ($products as $product) {
            try {
                $inventory = $inventoryData->get($product->sku);
                $productActualCosts = $actualCosts[$product->sku] ?? null;
                $productAcquiring = $acquiringData[$product->sku] ?? null;

                // Получаем данные о выкупе по ozon_sku, offer_id или SKU
                // API аналитики возвращает ozon_data['sku'] (числовой ID в Ozon)
                $ozonData = $product->ozon_data ?? [];
                $ozonSku = $ozonData['sku'] ?? null;
                $offerId = $ozonData['offer_id'] ?? $product->sku; // offer_id = артикул продавца

                $productRedemption = null;
                // Приоритет поиска: 1) по ozon_sku, 2) по offer_id, 3) по product.sku
                if ($ozonSku && isset($redemptionData[(string) $ozonSku])) {
                    $productRedemption = $redemptionData[(string) $ozonSku];
                } elseif ($offerId && isset($redemptionData[$offerId])) {
                    $productRedemption = $redemptionData[$offerId];
                } elseif (isset($redemptionData[$product->sku])) {
                    $productRedemption = $redemptionData[$product->sku];
                }

                // WB: данные из API
                $productWbSales = $wbSalesData[$product->sku] ?? null;
                $productWbStorage = $wbStorageData[$product->sku] ?? null;

                // Yandex: данные из API
                $productYandexSales = $yandexSalesData[$product->sku] ?? null;
                $productYandexPrice = $yandexPricesData[$product->sku] ?? null;

                // Определяем фактическую схему работы товара (из остатков API)
                $defaultFulfillmentType = match ($marketplace) {
                    'yandex', 'yandex_market' => 'FBY',
                    default => 'FBO',
                };
                $actualFulfillmentType = strtoupper($inventory?->fulfillment_type ?? $defaultFulfillmentType);

                if ($marketplace === 'ozon') {
                    $this->persistOzonDeliveryProfile(
                        $integrationId,
                        $product,
                        $deliveryProfiles[$product->sku] ?? null
                    );
                    $fixationService->appendPreviewFixationToProduct(
                        $product,
                        $previewFixationMap[$product->sku] ?? null
                    );
                    if (!empty($orderEconomicsPreview[$product->sku]['order_economics_summary'] ?? null)) {
                        $currentOzonData = is_array($product->ozon_data ?? null) ? $product->ozon_data : [];
                        $currentOzonData['order_economics_summary'] = $orderEconomicsPreview[$product->sku]['order_economics_summary'];
                        $product->forceFill(['ozon_data' => $currentOzonData])->saveQuietly();
                        $product->setAttribute('ozon_data', $currentOzonData);
                    }
                }

                // Создаём/обновляем записи для ВСЕХ схем работы (для предварительного расчёта)
                foreach ($fulfillmentTypes as $fulfillmentType) {
                    // WB: получаем СПП по nmId товара
                    $nmId = isset($product->wb_data['nmID']) ? (string) $product->wb_data['nmID'] : null;
                    $productWbSpp = $nmId ? ($wbSppData[$nmId] ?? null) : null;
                    $productWbRedemption = $nmId ? ($wbRedemptionData[$nmId] ?? null) : null;
                    $productWbLocalization = $nmId ? ($wbLocalizationByNmId[$nmId] ?? null) : null;
                    $productYandexTariffs = $yandexTariffsData[$fulfillmentType][$product->sku] ?? null;

                    // Ozon: получаем актуальные цены из API
                    $productPriceData = $productPrices[$product->sku] ?? null;

                    $data = $this->buildCalculationData(
                        $product,
                        $inventory,
                        $marketplace,
                        $integrationSettings,
                        $productActualCosts,
                        $localizationIndex,
                        $productAcquiring,
                        $productRedemption,
                        $manualRedemptionRate,
                        $fulfillmentType, // Передаём конкретную схему
                        $productWbSales,  // WB: продажи
                        $productWbStorage, // WB: хранение
                        $wbTariffsData,   // WB: тарифы складов
                        $productWbSpp,    // WB: СПП из статистики продаж
                        $productWbRedemption,
                        $productWbLocalization,
                        $wbCommissionsData,
                        $productPriceData, // Ozon: актуальные цены из API
                        $deliveryProfiles[$product->sku] ?? null,
                        $productYandexPrice,
                        $productYandexTariffs,
                        $productYandexSales
                    );

                    if ($data['price'] <= 0) {
                        continue;
                    }

                    $currentFulfillmentType = $data['fulfillment_type'] ?? 'FBO';

                    // Ищем существующую запись по SKU, маркетплейсу, интеграции И схеме работы
                    $existing = UnitEconomics::where('sku', $product->sku)
                        ->where('marketplace', $marketplace)
                        ->where('integration_id', $integrationId)
                        ->where('fulfillment_type', $currentFulfillmentType)
                        ->first();

                    // Ищем записи с ручными значениями для этого SKU (для копирования между схемами)
                    $baseQuery = UnitEconomics::where('sku', $product->sku)
                        ->where('marketplace', $marketplace)
                        ->where('integration_id', $integrationId);

                    // Ищем отдельно для каждого поля
                    $costPriceRecord = (clone $baseQuery)->where('cost_price', '>', 0)->first();
                    $drrRecord = (clone $baseQuery)->where('drr_percent', '>', 0)->first();
                    $ourShareRecord = (clone $baseQuery)->where('our_share_percent', '>', 0)->first();
                    $acquiringRecord = (clone $baseQuery)->whereNotNull('acquiring_percent')->where('acquiring_percent', '>', 0)->first();

                    // Используем ручные значения: сначала из текущей схемы, потом из любой другой
                    if ($existing && $existing->cost_price > 0) {
                        $data['cost_price'] = (float) $existing->cost_price;
                    } elseif ($costPriceRecord) {
                        $data['cost_price'] = (float) $costPriceRecord->cost_price;
                    }

                    if ($existing && $existing->drr_percent > 0) {
                        $data['drr_percent'] = (float) $existing->drr_percent;
                    } elseif ($drrRecord) {
                        $data['drr_percent'] = (float) $drrRecord->drr_percent;
                    }

                    if ($existing && $existing->our_share_percent > 0) {
                        $data['our_share_percent'] = (float) $existing->our_share_percent;
                    } elseif ($ourShareRecord) {
                        $data['our_share_percent'] = (float) $ourShareRecord->our_share_percent;
                    }

                    if ($existing && $existing->acquiring_percent !== null && $existing->acquiring_percent > 0) {
                        $data['acquiring_percent'] = (float) $existing->acquiring_percent;
                    } elseif ($acquiringRecord) {
                        $data['acquiring_percent'] = (float) $acquiringRecord->acquiring_percent;
                    }
                    // Налоги — берём из текущей схемы если есть
                    if ($existing) {
                        if ($existing->tax_percent !== null) {
                            $data['tax_percent'] = (float) $existing->tax_percent;
                        }
                        if ($existing->vat_percent !== null) {
                            $data['vat_percent'] = (float) $existing->vat_percent;
                        }
                    }

                    $calculated = $service->calculate($marketplace, $data);

                    // Извлекаем детализированные данные
                    $detailed = $this->extractDetailedData($data, $calculated, $marketplace);

                    // Расчёт стоимости за единицу
                    $salesCount = max(1, $data['sales_count'] ?? 1);
                    $totalCostsPerUnit = round($calculated['total_costs'] / $salesCount, 2);
                    $netProfitPerUnit = round($calculated['net_profit'] / $salesCount, 2);

                    // Определяем, является ли эта схема фактической для товара
                    $isActualScheme = ($currentFulfillmentType === $actualFulfillmentType);

                    // Базовые данные для сохранения
                    // cost_price копируется между схемами, но не перезаписывается на 0
                    $saveData = [
                        'integration_id' => $integrationId,
                        'product_name' => $product->name,
                        'price' => $data['price'],
                        'sales_count' => $data['sales_count'],
                        'revenue' => $calculated['revenue'],
                        'total_costs' => $calculated['total_costs'],
                        'gross_profit' => $calculated['gross_profit'],
                        'net_profit' => $calculated['net_profit'],
                        'margin_percent' => $calculated['margin_percent'],
                        'markup_percent' => $calculated['markup_percent'] ?? null,
                        'roi_percent' => $calculated['roi_percent'],
                        'total_costs_per_unit' => $totalCostsPerUnit,
                        'net_profit_per_unit' => $netProfitPerUnit,
                        'is_actual_scheme' => $isActualScheme,
                        'marketplace_data' => array_merge($data, $calculated, [
                            'has_cost_price' => $data['cost_price'] > 0,
                            'has_sales_data' => $data['sales_count'] > 0,
                        ]),
                    ];

                    // Копируем ручные значения между схемами (только если > 0)
                    if ($data['cost_price'] > 0) {
                        $saveData['cost_price'] = $data['cost_price'];
                    }
                    if (isset($data['drr_percent']) && $data['drr_percent'] > 0) {
                        $saveData['drr_percent'] = $data['drr_percent'];
                    }
                    if (isset($data['our_share_percent']) && $data['our_share_percent'] > 0) {
                        $saveData['our_share_percent'] = $data['our_share_percent'];
                    }
                    if (isset($data['acquiring_percent']) && $data['acquiring_percent'] > 0) {
                        $saveData['acquiring_percent'] = $data['acquiring_percent'];
                    }

                    $saveData = array_merge($saveData, $detailed);

                    if ($existing) {
                        // Обновляем существующую запись
                        $existing->update($saveData);
                    } else {
                        // Создаём новую запись
                        UnitEconomics::create(array_merge([
                            'sku' => $product->sku,
                            'marketplace' => $marketplace,
                            'period_start' => now()->subDays(30)->toDateString(),
                            'period_end' => now()->toDateString(),
                            'product_id' => null,
                        ], $saveData));
                    }

                    $synced++;
                }

            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 3) {
                    $this->warn("  Error for SKU {$product->sku}: {$e->getMessage()}");
                }
            }
        }

        // Пересчитываем кэш юнит-экономики после синхронизации
        if ($synced > 0) {
            $lockKey = "ue_recalculate_{$integrationId}";
            if (Cache::lock($lockKey, 900)->get()) {
                \App\Jobs\RecalculateUnitEconomicsCacheJob::dispatch($integrationId);
                $this->info("  Запущен пересчёт кэша для integration_id={$integrationId}");
            } else {
                $this->info("  Пересчёт кэша для integration_id={$integrationId} уже выполняется, пропускаем");
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    private function buildCalculationData(
        Product $product,
        ?object $inventory,
        string $marketplace,
        array $integrationSettings = [],
        ?array $actualCosts = null,
        ?array $localizationIndex = null,
        ?array $acquiringData = null,
        ?array $redemptionData = null,
        ?float $manualRedemptionRate = null,
        ?string $forceFulfillmentType = null,
        ?array $wbSalesData = null,
        ?array $wbStorageData = null,
        ?array $wbTariffsData = null,
        mixed $wbSppData = null,
        ?array $wbRedemptionData = null,
        ?array $wbLocalizationData = null,
        ?array $wbCommissionsData = null,
        ?array $productPriceData = null,
        ?array $ozonDeliveryProfile = null,
        ?array $productYandexPrice = null,
        ?array $productYandexTariffs = null,
        ?array $productYandexSales = null
    ): array {
        // Актуальная цена: приоритет API (actual_price = marketing_seller_price если акция) > Product.price
        // actual_price уже рассчитан в getPrices() с учётом marketing_seller_price
        $price = (float) $product->price;
        if ($productPriceData) {
            // actual_price содержит marketing_seller_price если товар в акции, иначе price
            $actualPrice = $productPriceData['actual_price'] ?? $productPriceData['marketing_seller_price'] ?? 0;
            if ($actualPrice > 0) {
                $price = $actualPrice;
            }
        }
        $costPrice = $inventory?->cost_price ?? 0;

        $salesCount = match ($marketplace) {
            'yandex', 'yandex_market' => $productYandexSales['sales_30_days'] ?? $inventory?->sales_30_days ?? 0,
            default => $wbSalesData['sales_30_days'] ?? $inventory?->sales_30_days ?? 0,
        };

        // Хранение: приоритет WB API > inventory
        $storageCost = ($wbStorageData['storage_cost_per_month'] ?? null) ?? $inventory?->storage_cost_per_month ?? 0;

        $sales7Days = match ($marketplace) {
            'yandex', 'yandex_market' => $productYandexSales['sales_7_days'] ?? null,
            'ozon' => $inventory?->sales_7_days ?? null,
            default => $wbSalesData['sales_7_days'] ?? null,
        };
        $sales14Days = match ($marketplace) {
            'yandex', 'yandex_market' => $productYandexSales['sales_14_days'] ?? null,
            default => $wbSalesData['sales_14_days'] ?? null,
        };
        $revenue30Days = match ($marketplace) {
            'yandex', 'yandex_market' => $productYandexSales['revenue'] ?? null,
            default => $wbSalesData['revenue_30_days'] ?? null,
        };
        $avgDailySales = match ($marketplace) {
            'yandex', 'yandex_market' => $productYandexSales['avg_daily_sales'] ?? null,
            default => $wbSalesData['avg_daily_sales'] ?? null,
        };

        $data = [
            'sku' => $product->sku,
            'price' => $price,
            'cost_price' => $costPrice,
            'sales_count' => $salesCount,
            'storage_cost' => $storageCost,
            // WB: дополнительные данные о продажах
            'sales_7_days' => $sales7Days,
            'sales_14_days' => $sales14Days,
            'revenue_30_days' => $revenue30Days,
            'avg_daily_sales' => $avgDailySales,
        ];

        switch ($marketplace) {
            case 'wildberries':
                $wbData = $product->wb_data ?? [];

                // Определяем тип фулфилмента (принудительный или из inventory)
                $fulfillmentType = $forceFulfillmentType
                    ? strtoupper($forceFulfillmentType)
                    : strtoupper($inventory?->fulfillment_type ?? 'FBO');
                $data['fulfillment_type'] = $fulfillmentType;

                // === ГАБАРИТЫ ИЗ ХАРАКТЕРИСТИК ===
                $characteristics = $product->characteristics ?? [];
                // Характеристики WB приходят в см — сохраняем как есть
                $lengthCm = $this->extractNumericValue($characteristics['Длина упаковки'] ?? $wbData['length'] ?? null);
                $widthCm = $this->extractNumericValue($characteristics['Ширина упаковки'] ?? $wbData['width'] ?? null);
                $heightCm = $this->extractNumericValue($characteristics['Высота упаковки'] ?? $wbData['height'] ?? null);

                // Габариты в см (без конвертации)
                $length = $lengthCm ?? 10; // fallback 10 см
                $width = $widthCm ?? 10;
                $height = $heightCm ?? 10;

                // Вес: ищем в разных форматах (г или кг)
                $weightG = $this->extractNumericValue($characteristics['Вес товара без упаковки (г)'] ?? $characteristics['Вес'] ?? $characteristics['Вес товара'] ?? null);
                $weightKg = $this->extractNumericValue($characteristics['Вес без упаковки (кг)'] ?? $characteristics['Вес (кг)'] ?? $characteristics['Вес товара (кг)'] ?? null);

                if ($weightG) {
                    $weight = $weightG; // уже в граммах
                } elseif ($weightKg) {
                    $weight = $weightKg * 1000; // кг -> г
                } else {
                    $weight = $this->extractNumericValue($wbData['weight'] ?? null) ?? 500; // fallback
                }

                // Объём в литрах (см³ -> л = /1000)
                $volumeLiters = ($length * $width * $height) / 1000;

                // Если есть объём из API хранения — используем его
                if ($wbStorageData && isset($wbStorageData['volume_liters']) && $wbStorageData['volume_liters'] > 0) {
                    $volumeLiters = $wbStorageData['volume_liters'];
                }

                $data['volume_liters'] = round($volumeLiters, 2);
                // Габариты WB в см (поля называются _mm для совместимости, но значения в см)
                $data['length_mm'] = round($length, 2);
                $data['width_mm'] = round($width, 2);
                $data['height_mm'] = round($height, 2);
                $data['weight_g'] = round($weight, 0);

                // Объёмный вес (объём / 5)
                $data['volume_weight'] = round($volumeLiters / 5, 2);

                // Фактический вес в кг
                $data['actual_weight'] = round($weight / 1000, 2);

                // === КОМИССИЯ ЗА ПРОДАЖУ (0.5% - 29.5% в зависимости от категории) ===
                $wbCommissionScheme = match (strtoupper($fulfillmentType)) {
                    'FBS', 'DBS', 'EDBS', 'DBW' => 'fbs',
                    default => 'fbo',
                };
                $subjectId = $wbData['subjectID'] ?? $wbData['subjectId'] ?? null;
                $subjectCommission = $subjectId ? ($wbCommissionsData[(string) $subjectId] ?? $wbCommissionsData[$subjectId] ?? null) : null;
                $data['commission_percent'] = data_get($wbData, "commissions.{$wbCommissionScheme}.percent")
                    ?? data_get($subjectCommission, $wbCommissionScheme)
                    ?? data_get($subjectCommission, 'fbo')
                    ?? data_get($wbData, 'commissions.fbo.percent')
                    ?? data_get($wbData, 'commissions.fbs.percent')
                    ?? $wbData['commission_percent']
                    ?? $integrationSettings['wb_commission_percent']
                    ?? 15;

                // === КОЭФФИЦИЕНТЫ ИЗ API ТАРИФОВ ===
                // Если есть данные из API тарифов — используем их
                $warehouseCoefficient = 1.0;
                $storageCoefficient = 1.0;
                $deliveryBaseLiter = 46;
                $deliveryAdditionalLiter = 14;
                $storageBaseLiter = 0.08;

                if (! empty($wbTariffsData)) {
                    // Берём средний коэффициент по всем складам или первый доступный
                    $firstTariff = reset($wbTariffsData);
                    if ($firstTariff) {
                        $warehouseCoefficient = $firstTariff['delivery_coefficient'] ?? 1.0;
                        $storageCoefficient = $firstTariff['storage_coefficient'] ?? 1.0;
                        $deliveryBaseLiter = $firstTariff['delivery_base_liter'] ?? 46;
                        $deliveryAdditionalLiter = $firstTariff['delivery_additional_liter'] ?? 14;
                        $storageBaseLiter = $firstTariff['storage_base_liter'] ?? 0.08;
                    }
                }

                // Коэффициент склада (логистики) — приоритет: API > wb_data > settings > дефолт
                $data['warehouse_coefficient'] = $wbData['warehouse_coefficient']
                    ?? $inventory?->warehouse_coefficient
                    ?? $warehouseCoefficient;

                // Индекс локализации (ИЛ) — влияет на КТР
                $data['localization_index'] = $wbLocalizationData['ktr']
                    ?? $wbData['localization_index']
                    ?? $integrationSettings['wb_localization_index']
                    ?? $integrationSettings['localization_index']
                    ?? 1.0;
                $data['localization_rate'] = $wbLocalizationData['localization_rate'] ?? null;
                $data['local_orders'] = $wbLocalizationData['local_orders'] ?? null;
                $data['local_orders_count'] = $wbLocalizationData['total_orders'] ?? null;
                $data['orders_count'] = $wbRedemptionData['orders_count'] ?? null;

                // Коэффициент габаритов (штраф за превышение)
                $data['dimensions_coefficient'] = $wbData['dimensions_coefficient'] ?? 1.0;

                // Коэффициент хранения — приоритет: API > wb_data > settings > дефолт
                $data['storage_coefficient'] = $wbData['storage_coefficient'] ?? $storageCoefficient;

                // === ХРАНЕНИЕ (только FBO) ===
                // Тариф хранения из API или дефолт
                $data['storage_tariff'] = $storageBaseLiter;
                $data['storage_days'] = $inventory?->turnover_days ?? 30;

                // Если есть фактическая стоимость хранения из API — используем её
                if ($wbStorageData && isset($wbStorageData['storage_cost_per_day'])) {
                    $data['storage_cost_per_day'] = $wbStorageData['storage_cost_per_day'];
                    $data['storage_cost_per_month'] = $wbStorageData['storage_cost_per_month'] ?? null;
                }

                // === ПРИЁМКА ===
                $data['acceptance_cost'] = $wbData['acceptance_cost'] ?? 0;

                // === СПП (Скидка постоянного покупателя) ===
                // Приоритет: 1) из статистики продаж API, 2) wb_data, 3) дефолт 0
                if (is_numeric($wbSppData)) {
                    $data['spp_percent'] = (float) $wbSppData;
                } elseif (is_array($wbSppData) && isset($wbSppData['spp'])) {
                    $data['spp_percent'] = $wbSppData['spp'];
                    $data['spp_min'] = $wbSppData['min_spp'] ?? null;
                    $data['spp_max'] = $wbSppData['max_spp'] ?? null;
                    $data['spp_sales_count'] = $wbSppData['count'] ?? null;
                } else {
                    $data['spp_percent'] = $wbData['spp_percent'] ?? 0;
                }

                // === ПРОЦЕНТ ВЫКУПА ===
                // Приоритет: 1) расчёт из продаж API, 2) wb_data, 3) ручной ввод, 4) дефолт 80%
                // Можно рассчитать из продаж/возвратов если есть данные
                if ($wbRedemptionData && isset($wbRedemptionData['redemption_rate']) && $wbRedemptionData['redemption_rate'] > 0) {
                    $data['redemption_rate'] = $wbRedemptionData['redemption_rate'];
                    $data['orders_count'] = $wbRedemptionData['orders_count'] ?? ($data['orders_count'] ?? null);
                    $data['returns_count'] = $wbRedemptionData['returns_count'] ?? null;
                    $data['redemption_source'] = $wbRedemptionData['source'] ?? 'api';
                } elseif (($wbRedemptionData['orders_count'] ?? 0) > 0) {
                    $ordersCount = (int) ($wbRedemptionData['orders_count'] ?? 0);
                    $deliveredCount = min($ordersCount, max(0, (int) $salesCount));
                    $returnsCount = max(0, $ordersCount - $deliveredCount);

                    $data['redemption_rate'] = round(($deliveredCount / max($ordersCount, 1)) * 100, 2);
                    $data['orders_count'] = $ordersCount;
                    $data['returns_count'] = $returnsCount;
                    $data['redemption_source'] = 'api_orders_sku_sales';
                } elseif (isset($wbData['redemption_rate']) && $wbData['redemption_rate'] > 0) {
                    $data['redemption_rate'] = $wbData['redemption_rate'];
                    $data['redemption_source'] = 'product';
                } elseif ($manualRedemptionRate !== null && $manualRedemptionRate > 0) {
                    $data['redemption_rate'] = $manualRedemptionRate;
                    $data['redemption_source'] = 'manual';
                } else {
                    $data['redemption_rate'] = 80; // WB обычно ниже выкуп чем Ozon
                    $data['redemption_source'] = 'default';
                }

                // === СВОЯ ДОСТАВКА (DBS) ===
                if ($fulfillmentType === 'DBS') {
                    $data['own_delivery_cost'] = $wbData['own_delivery_cost'] ?? $integrationSettings['own_delivery_cost'] ?? 200;
                    $data['own_return_cost'] = $wbData['own_return_cost'] ?? $integrationSettings['own_return_cost'] ?? 100;
                }
                break;

            case 'ozon':
                $ozonData = $product->ozon_data ?? [];
                $commissions = $ozonData['commissions'] ?? [];
                $redemption = $ozonData['redemption'] ?? [];

                // Определяем тип фулфилмента (принудительный или из inventory)
                $fulfillmentType = $forceFulfillmentType
                    ? strtoupper($forceFulfillmentType)
                    : strtoupper($inventory?->fulfillment_type ?? 'FBO');
                $data['fulfillment_type'] = $fulfillmentType;

                // === ГАБАРИТЫ ИЗ ХАРАКТЕРИСТИК ===
                $characteristics = $product->characteristics ?? [];
                $length = $this->extractNumericValue($characteristics['Глубина упаковки'] ?? null) ?? 100; // мм
                $width = $this->extractNumericValue($characteristics['Ширина упаковки'] ?? null) ?? 100;
                $height = $this->extractNumericValue($characteristics['Высота упаковки'] ?? null) ?? 100;
                $weight = $this->extractNumericValue($characteristics['Вес'] ?? $characteristics['Вес товара, г'] ?? null) ?? 500; // г

                // Объём в литрах (мм³ -> л)
                $volumeLiters = ($length * $width * $height) / 1000000;
                $data['volume_liters'] = round($volumeLiters, 2);

                // Объёмный вес (объём / 5)
                $data['volume_weight'] = round($volumeLiters / 5, 2);

                // Фактический вес в кг
                $data['actual_weight'] = round($weight / 1000, 2);

                // === НОВАЯ МОДЕЛЬ ТАРИФОВ Ozon (route/locality + price segment) ===
                $data['category_id'] = $ozonData['category_name']
                    ?? $ozonData['category']
                    ?? $product->category
                    ?? 'default';
                $data['route_key'] = $inventory?->route_key
                    ?? $ozonDeliveryProfile['route_key']
                    ?? $ozonData['route_key']
                    ?? ($ozonData['active_fixation']['shipping_cluster_id'] ?? null)
                    ?? $integrationSettings['ozon_route_key']
                    ?? $integrationSettings['route_key']
                    ?? null;
                $data['route_label'] = $inventory?->route_label
                    ?? $ozonDeliveryProfile['route_label']
                    ?? $ozonData['route_label']
                    ?? ($ozonData['active_fixation']['shipping_cluster_name'] ?? null)
                    ?? $integrationSettings['ozon_route_label']
                    ?? $integrationSettings['route_label']
                    ?? null;
                $data['shipping_cluster_id'] = $ozonData['active_fixation']['shipping_cluster_id'] ?? null;
                $data['shipping_cluster_name'] = $ozonData['active_fixation']['shipping_cluster_name'] ?? null;
                $data['fixation_applied'] = $ozonData['active_fixation']['fixation_applied'] ?? null;
                $data['fixation_id'] = $ozonData['active_fixation']['fixation_id'] ?? null;
                $data['fixation_base_date'] = $ozonData['active_fixation']['fixation_base_date'] ?? null;
                $data['fixed_until'] = $ozonData['active_fixation']['fixed_until'] ?? null;
                $data['tariff_version_used'] = $ozonData['active_fixation']['tariff_version_used'] ?? null;
                $data['markup_version_used'] = $ozonData['active_fixation']['markup_version_used'] ?? null;
                $data['calculation_mode'] = $ozonData['active_fixation']['calculation_mode'] ?? null;
                $data['is_local_sale'] = $ozonDeliveryProfile['is_local_sale']
                    ?? $ozonData['is_local_sale']
                    ?? $inventory?->is_local_sale
                    ?? null;
                // Stale $ozonData['non_local_markup_percent'] removed from fallback chain —
                // it may contain persisted data from a previous sync and cause incorrect calculations.
                // Prefer delivery profile or inventory data; fall back to null rather than stale values.
                $data['non_local_markup_percent'] = $ozonDeliveryProfile['weighted_non_local_markup_percent']
                    ?? $ozonDeliveryProfile['non_local_markup_percent']
                    ?? $inventory?->non_local_markup_percent
                    ?? null;
                $data['tariff_source'] = $inventory?->tariff_source
                    ?? $ozonData['tariff_source']
                    ?? null;
                $data['tariff_effective_from'] = $inventory?->tariff_effective_from
                    ?? $ozonData['tariff_effective_from']
                    ?? null;
                $data['price_segment'] = $inventory?->price_segment
                    ?? $ozonData['price_segment']
                    ?? null;
                $data['route_resolution_status'] = $ozonDeliveryProfile['route_resolution_status']
                    ?? $ozonData['route_resolution_status']
                    ?? null;
                $data['locality_resolution_status'] = $ozonDeliveryProfile['locality_resolution_status']
                    ?? $ozonData['locality_resolution_status']
                    ?? null;
                $data['calculation_confidence'] = $ozonDeliveryProfile['calculation_confidence']
                    ?? $ozonData['calculation_confidence']
                    ?? null;
                $data['profile_source'] = $ozonDeliveryProfile['profile_source']
                    ?? $ozonData['profile_source']
                    ?? null;
                $data['dominant_cluster_id'] = $ozonDeliveryProfile['dominant_cluster_id']
                    ?? $ozonData['dominant_cluster_id']
                    ?? null;
                $data['dominant_cluster_share'] = $ozonDeliveryProfile['dominant_cluster_share']
                    ?? $ozonData['dominant_cluster_share']
                    ?? null;
                $data['expected_locality_rate'] = $ozonDeliveryProfile['expected_locality_rate']
                    ?? $ozonData['expected_locality_rate']
                    ?? null;
                $data['weighted_non_local_markup_percent'] = $ozonDeliveryProfile['weighted_non_local_markup_percent']
                    ?? $ozonData['weighted_non_local_markup_percent']
                    ?? null;
                $data['weighted_logistics_cost'] = $ozonDeliveryProfile['weighted_logistics_cost']
                    ?? $ozonData['weighted_logistics_cost']
                    ?? null;
                $data['clusters_summary'] = $ozonDeliveryProfile['clusters_summary']
                    ?? $ozonData['clusters_summary']
                    ?? [];
                $data['stock_profile'] = $ozonDeliveryProfile['stock_profile']
                    ?? $ozonData['stock_profile']
                    ?? [];
                $data['markup_rule_reason'] = $ozonDeliveryProfile['markup_rule_reason']
                    ?? $ozonData['markup_rule_reason']
                    ?? null;
                $data['markup_rule_reason_label'] = $ozonData['markup_rule_reason_label']
                    ?? null;
                $data['order_economics_summary'] = $ozonData['order_economics_summary'] ?? [];

                // === КОМИССИИ (приоритет: фактические > API цен > ozon_data > дефолт) ===
                $schemaKey = strtolower($fulfillmentType);
                $schemaCommission = $commissions[$schemaKey] ?? [];

                // Комиссии из API /v4/product/info/prices (sales_percent_fbo, sales_percent_fbs)
                $priceCommissions = $productPriceData['commissions'] ?? [];
                $fboCommissionFromApi = $priceCommissions['sales_percent_fbo'] ?? null;
                $fbsCommissionFromApi = $priceCommissions['sales_percent_fbs'] ?? null;
                $rfbsCommissionFromApi = $priceCommissions['sales_percent_rfbs'] ?? null;

                // Используем фактические затраты если есть И они > 0
                if ($actualCosts && isset($actualCosts['avg_commission_per_unit']) && $actualCosts['avg_commission_per_unit'] > 0) {
                    $data['commission_value'] = $actualCosts['avg_commission_per_unit'];
                    // Рассчитываем процент из фактических данных
                    if ($price > 0) {
                        $data['commission_percent'] = round(($actualCosts['avg_commission_per_unit'] / $price) * 100, 2);
                    }
                } else {
                    // Приоритет: API цен > ozon_data > дефолт
                    $data['fbo_commission_percent'] = $fboCommissionFromApi ?? $commissions['fbo']['percent'] ?? 15;
                    $data['fbs_commission_percent'] = $fbsCommissionFromApi ?? $commissions['fbs']['percent'] ?? 21;
                    $data['rfbs_commission_percent'] = $rfbsCommissionFromApi ?? 20;

                    // Выбираем комиссию по текущей схеме
                    $data['commission_percent'] = match ($fulfillmentType) {
                        'FBO' => $data['fbo_commission_percent'],
                        'FBS' => $data['fbs_commission_percent'],
                        'RFBS' => $data['rfbs_commission_percent'],
                        'EXPRESS' => $data['rfbs_commission_percent'],
                        default => $schemaCommission['percent'] ?? 15,
                    };
                    $data['commission_value'] = $schemaCommission['value'] ?? null;
                }

                // Стоимость возврата из API
                $data['return_cost'] = $schemaCommission['return_amount'] ?? 100;

                // Эквайринг (приоритет: финансовые транзакции > фактические затраты > дефолт 1.5%)
                if ($acquiringData && isset($acquiringData['avg_acquiring_percent']) && $acquiringData['avg_acquiring_percent'] > 0) {
                    // Из финансовых транзакций /v3/finance/transaction/list
                    $data['acquiring_percent'] = $acquiringData['avg_acquiring_percent'];
                    $data['acquiring_value'] = $acquiringData['total_acquiring'] / max($acquiringData['orders_count'], 1);
                } elseif ($actualCosts && isset($actualCosts['avg_acquiring_per_unit']) && $actualCosts['avg_acquiring_per_unit'] > 0) {
                    // Из заказов /v2/posting/fbo/list
                    $data['acquiring_value'] = $actualCosts['avg_acquiring_per_unit'];
                    if ($price > 0) {
                        $data['acquiring_percent'] = round(($actualCosts['avg_acquiring_per_unit'] / $price) * 100, 2);
                    } else {
                        $data['acquiring_percent'] = 1.5;
                    }
                } else {
                    // Дефолт
                    $data['acquiring_percent'] = 1.5;
                }

                // === ПРОЦЕНТ ВЫКУПА ===
                // Приоритет: 1) API аналитики (redemptionData), 2) ручной ввод (manualRedemptionRate), 3) ozon_data['redemption'], 4) дефолт 100%
                if ($redemptionData && isset($redemptionData['redemption_rate']) && $redemptionData['redemption_rate'] !== null) {
                    // Данные из API (Premium или fallback)
                    $data['redemption_rate'] = $redemptionData['redemption_rate'];
                    $data['orders_count'] = $redemptionData['orders_count'] ?? null;
                    $data['returns_count'] = $redemptionData['returns_count'] ?? null;
                    $data['redemption_source'] = $redemptionData['source'] ?? 'api';
                } elseif ($manualRedemptionRate !== null && $manualRedemptionRate > 0) {
                    // Ручной ввод для не-Premium аккаунтов
                    $data['redemption_rate'] = $manualRedemptionRate;
                    $data['orders_count'] = null;
                    $data['returns_count'] = null;
                    $data['redemption_source'] = 'manual';
                } else {
                    // Fallback на ozon_data или дефолт
                    $data['redemption_rate'] = $redemption['redemption_rate'] ?? 100;
                    $data['orders_count'] = $redemption['orders_count'] ?? null;
                    $data['returns_count'] = $redemption['returns_count'] ?? null;
                    $data['redemption_source'] = 'default';
                }

                // === ХРАНЕНИЕ (FBO) ===
                $data['turnover_days'] = $inventory?->turnover_days ?? 30;
                $data['litrobonus'] = $inventory?->litrobonus ?? 0;

                // === ОБРАБОТКА (FBS) с 10.12.2025 ===
                // СЦ: 20₽, ПВЗ/ППЗ: 30₽, с доверительной приёмкой: 10₽
                $data['processing_cost'] = 20; // Дефолт 20₽ (СЦ)

                // === ФАКТИЧЕСКИЕ ЗАТРАТЫ ИЗ API (если есть) ===
                if ($actualCosts) {
                    $data['actual_logistics_per_unit'] = $actualCosts['avg_logistics_per_unit'] ?? null;
                    $data['actual_last_mile_per_unit'] = $actualCosts['avg_last_mile_per_unit'] ?? null;
                    $data['actual_acquiring_per_unit'] = $actualCosts['avg_acquiring_per_unit'] ?? null;
                }

                // === СВОЯ ЛОГИСТИКА (realFBS/DBS) ===
                $data['own_delivery_cost'] = $inventory?->own_delivery_cost ?? 200;
                $data['marketplace_compensation'] = $inventory?->ozon_compensation
                    ?? $inventory?->marketplace_compensation
                    ?? 0;
                $data['ozon_compensation'] = $data['marketplace_compensation'];

                // === АКЦИИ (marketing_seller_price) ===
                // Акция определяется ТОЛЬКО через API: marketing_seller_price < price
                // old_price — это просто "зачёркнутая цена" для маркетинга, НЕ признак акции
                $isInPromotion = $productPriceData['is_in_promotion']
                    ?? $ozonData['is_in_promotion']
                    ?? false;
                $promotionDiscount = $productPriceData['promotion_discount']
                    ?? $ozonData['promotion_discount']
                    ?? null;

                $data['is_in_promotion'] = $isInPromotion;
                $data['promotion_discount'] = $promotionDiscount;
                $data['seller_price'] = $productPriceData['price']
                    ?? $commissions['seller_price']
                    ?? null; // Цена без акции (из API)
                $data['marketing_seller_price'] = $productPriceData['marketing_seller_price']
                    ?? $commissions['marketing_seller_price']
                    ?? null; // Цена с акцией (из API)
                break;

            case 'yandex':
            case 'yandex_market':
                $yandexData = $product->yandex_data ?? [];
                $fulfillmentType = $forceFulfillmentType
                    ? strtoupper($forceFulfillmentType)
                    : strtoupper($inventory?->fulfillment_type ?? 'FBY');

                $lengthMm = (float) ($yandexData['length_mm'] ?? $product->depth ?? 0);
                $widthMm = (float) ($yandexData['width_mm'] ?? $product->width ?? 0);
                $heightMm = (float) ($yandexData['height_mm'] ?? $product->height ?? 0);
                $weightG = (float) ($yandexData['weight_g'] ?? $product->weight ?? 0);
                $volumeLiters = ($lengthMm > 0 && $widthMm > 0 && $heightMm > 0)
                    ? ($lengthMm * $widthMm * $heightMm) / 1000000
                    : 0;

                if (($productYandexPrice['price'] ?? 0) > 0) {
                    $data['price'] = (float) $productYandexPrice['price'];
                }

                $data['fulfillment_type'] = $fulfillmentType;
                $data['length_mm'] = $lengthMm;
                $data['width_mm'] = $widthMm;
                $data['height_mm'] = $heightMm;
                $data['weight_g'] = $weightG;
                $data['volume_liters'] = round($volumeLiters, 2);
                $data['volume_weight'] = round($volumeLiters / 5, 2);
                $data['actual_weight'] = round($weightG / 1000, 2);
                $data['category_id'] = $yandexData['categoryId'] ?? $product->category ?? 'default';
                // Приоритет % выкупа: ручной → из API продаж → дефолт
                $apiRedemptionRate = $productYandexSales['redemption_rate'] ?? null;
                if ($manualRedemptionRate !== null) {
                    $data['redemption_rate'] = $manualRedemptionRate;
                    $data['redemption_source'] = 'manual';
                } elseif ($apiRedemptionRate !== null) {
                    $data['redemption_rate'] = $apiRedemptionRate;
                    $data['redemption_source'] = 'api';
                } else {
                    $data['redemption_rate'] = $yandexData['redemption_rate'] ?? 95;
                    $data['redemption_source'] = 'default';
                }
                $data['tariff_breakdown'] = $productYandexTariffs ?? [];

                $normalizedTariffs = $this->normalizeYandexTariffBreakdown($data['tariff_breakdown']);
                $data['referral_fee_percent'] = isset($normalizedTariffs['FEE']) && $data['price'] > 0
                    ? round(($normalizedTariffs['FEE'] / $data['price']) * 100, 2)
                    : 5;
                $data['fby_delivery'] = (float) ($normalizedTariffs['DELIVERY_TO_CUSTOMER'] ?? 50);
                $data['fbs_delivery'] = (float) ($normalizedTariffs['DELIVERY_TO_CUSTOMER'] ?? 40);
                $data['acquiring_percent'] = $data['price'] > 0
                    ? round((((float) ($normalizedTariffs['AGENCY_COMMISSION'] ?? 0) + (float) ($normalizedTariffs['PAYMENT_TRANSFER'] ?? 0)) / $data['price']) * 100, 2)
                    : 0;
                break;
        }

        return $data;
    }

    /**
     * Извлечь детализированные данные для сохранения в БД
     */
    private function extractDetailedData(array $data, array $calculated, string $marketplace): array
    {
        $detailed = [];

        if ($marketplace === 'ozon') {
            $salesCount = max(1, $data['sales_count'] ?? 1);
            $fulfillmentType = $calculated['fulfillment_type'] ?? $data['fulfillment_type'] ?? 'FBO';

            $detailed = [
                // Комиссия
                'commission_percent' => $calculated['sales_fee_percent'] ?? $calculated['commission_percent'] ?? $data['commission_percent'] ?? null,
                'commission_amount' => $calculated['commission_amount'] ?? null,

                // Габариты
                'volume_liters' => $calculated['volume_liters'] ?? $data['volume_liters'] ?? null,
                'volume_weight' => $calculated['volume_weight'] ?? $data['volume_weight'] ?? null,
                'actual_weight' => $calculated['actual_weight'] ?? $data['actual_weight'] ?? null,

                // Логистика и объяснимые тарифные метаданные
                'fulfillment_type' => $fulfillmentType,
                'avg_delivery_time_hours' => null,
                'localization_index' => null,
                'tariff_status' => null,
                'base_logistics_cost' => $calculated['base_logistics_cost'] ?? null,
                'logistics_coefficient' => 1.0,
                'additional_commission_percent' => 0.0,
                'additional_commission_amount' => $calculated['non_local_markup_amount'] ?? null,
                'logistics_with_coefficient' => $calculated['logistics_cost'] ?? null,
                'logistics_cost' => $calculated['logistics_cost'] ?? null,
                'processing_cost' => $calculated['processing_cost'] ?? null,
                'last_mile_cost' => $calculated['last_mile_cost'] ?? null,
                'tariff_version' => $calculated['tariff_version'] ?? null,
                'tariff_effective_from' => $calculated['tariff_effective_from'] ?? $data['tariff_effective_from'] ?? null,
                'tariff_source' => $calculated['tariff_source'] ?? $data['tariff_source'] ?? null,
                'route_key' => $calculated['route_key'] ?? $data['route_key'] ?? null,
                'route_label' => $calculated['route_label'] ?? $data['route_label'] ?? null,
                'is_local_sale' => $calculated['is_local_sale'] ?? $data['is_local_sale'] ?? null,
                'non_local_markup_percent' => $calculated['non_local_markup_percent'] ?? $data['non_local_markup_percent'] ?? 0,
                'price_segment' => $calculated['price_segment'] ?? $data['price_segment'] ?? null,
                'sales_fee_percent' => $calculated['sales_fee_percent'] ?? $calculated['commission_percent'] ?? $data['commission_percent'] ?? null,
                'route_resolution_status' => $calculated['route_resolution_status'] ?? $data['route_resolution_status'] ?? 'unknown',
                'locality_resolution_status' => $calculated['locality_resolution_status'] ?? $data['locality_resolution_status'] ?? 'unknown',
                'calculation_confidence' => $calculated['calculation_confidence'] ?? $data['calculation_confidence'] ?? 'low',
                'profile_source' => $calculated['profile_source'] ?? $data['profile_source'] ?? null,
                'dominant_cluster_id' => $calculated['dominant_cluster_id'] ?? $data['dominant_cluster_id'] ?? null,
                'dominant_cluster_share' => $calculated['dominant_cluster_share'] ?? $data['dominant_cluster_share'] ?? null,
                'expected_locality_rate' => $calculated['expected_locality_rate'] ?? $data['expected_locality_rate'] ?? null,
                'weighted_non_local_markup_percent' => $calculated['weighted_non_local_markup_percent'] ?? $data['weighted_non_local_markup_percent'] ?? null,
                'profit_min' => $calculated['profit_min'] ?? null,
                'profit_base' => $calculated['profit_base'] ?? null,
                'profit_max' => $calculated['profit_max'] ?? null,
                'clusters_summary' => $calculated['clusters_summary'] ?? $data['clusters_summary'] ?? [],

                // Хранение
                'storage_cost' => $calculated['storage_cost'] ?? $data['storage_cost'] ?? null,
                'turnover_days' => (int) ($calculated['turnover_days'] ?? $data['turnover_days'] ?? 0),
                'litrobonus' => $calculated['litrobonus'] ?? $data['litrobonus'] ?? null,

                // Возвраты
                'redemption_rate' => $calculated['redemption_rate'] ?? $data['redemption_rate'] ?? null,
                'redemption_source' => $data['redemption_source'] ?? 'default',
                'orders_count' => $data['orders_count'] ?? null,
                'returns_count' => $data['returns_count'] ?? null,
                'return_logistics_cost' => $calculated['return_logistics_cost'] ?? null,
                'return_processing_cost' => $calculated['return_processing_cost'] ?? 15.0,
                'expected_return_cost' => $calculated['expected_return_cost'] ?? null,
                'effective_logistics' => $calculated['effective_logistics'] ?? null,
                'delivery_cost' => $calculated['delivery_cost'] ?? null,

                // Эквайринг
                'acquiring_percent' => $calculated['acquiring_percent'] ?? $data['acquiring_percent'] ?? null,
                'acquiring_amount' => $calculated['acquiring_amount'] ?? null,

                // Своя логистика (realFBS/DBS)
                'own_delivery_cost' => $calculated['own_delivery_cost'] ?? $data['own_delivery_cost'] ?? null,
                'ozon_compensation' => $calculated['marketplace_compensation'] ?? $calculated['ozon_compensation'] ?? $data['ozon_compensation'] ?? null,
                'marketplace_compensation' => $calculated['marketplace_compensation'] ?? $data['marketplace_compensation'] ?? null,

                // === СТОИМОСТЬ ЗА ЕДИНИЦУ ===
                'logistics_per_unit' => $calculated['base_logistics_cost'] ?? null,
                'last_mile_per_unit' => $calculated['last_mile_cost'] ?? null,
                'commission_per_unit' => isset($calculated['commission_amount'])
                    ? round($calculated['commission_amount'] / $salesCount, 2)
                    : null,
                'acquiring_per_unit' => isset($calculated['acquiring_amount'])
                    ? round($calculated['acquiring_amount'] / $salesCount, 2)
                    : null,
                'storage_per_unit' => isset($calculated['storage_cost'])
                    ? round($calculated['storage_cost'] / $salesCount, 2)
                    : null,

                // === НА РС (На расчётный счёт) ===
                'advertising_cost' => $calculated['advertising_cost'] ?? $data['advertising_cost'] ?? 0,
                'to_settlement_account' => $calculated['to_settlement_account'] ?? null,

                // === НАЛОГИ (рассчитанные суммы) ===
                // drr_percent и our_share_percent НЕ включаем — они вводятся вручную и не перезаписываются
                'tax_amount' => $calculated['tax_amount'] ?? null,
                'vat_amount' => $calculated['vat_amount'] ?? null,
                'drr_amount' => $calculated['drr_amount'] ?? null,
                'our_share_amount' => $calculated['our_share_amount'] ?? null,

                // === АКЦИИ (marketing_seller_price) ===
                'is_in_promotion' => $data['is_in_promotion'] ?? false,
                'promotion_discount' => $data['promotion_discount'] ?? null,
                'seller_price' => $data['seller_price'] ?? null, // Цена без акции
                'marketing_seller_price' => $data['marketing_seller_price'] ?? null, // Цена с акцией
            ];
        } elseif ($marketplace === 'wildberries') {
            $salesCount = max(1, $data['sales_count'] ?? 1);

            $detailed = [
                // === ГАБАРИТЫ (мм, г, л) ===
                'length_mm' => $calculated['length_mm'] ?? $data['length_mm'] ?? null,
                'width_mm' => $calculated['width_mm'] ?? $data['width_mm'] ?? null,
                'height_mm' => $calculated['height_mm'] ?? $data['height_mm'] ?? null,
                'weight_g' => $calculated['weight_g'] ?? $data['weight_g'] ?? null,
                'volume_liters' => $calculated['volume_liters'] ?? $data['volume_liters'] ?? null,
                'volume_weight' => $calculated['volume_weight'] ?? $data['volume_weight'] ?? null,
                'actual_weight' => $calculated['actual_weight'] ?? $data['actual_weight'] ?? null,

                // === НАЦЕНКА (множитель x) ===
                'markup_multiplier' => $calculated['markup_multiplier'] ?? null,

                // === ЦЕНА ПОКУПАТЕЛЯ (с СПП) ===
                'customer_price' => $calculated['customer_price'] ?? null,

                // === КОМИССИЯ ===
                'commission_percent' => $calculated['commission_percent'] ?? $data['commission_percent'] ?? null,
                'commission_amount' => $calculated['commission_amount'] ?? null,

                // === СПП (Скидка постоянного покупателя) ===
                'spp_percent' => $calculated['spp_percent'] ?? $data['spp_percent'] ?? 0,
                'spp_amount' => $calculated['spp_amount'] ?? null,

                // === КС (Коэффициент склада) ===
                'warehouse_coefficient' => $calculated['warehouse_coefficient'] ?? $data['warehouse_coefficient'] ?? 1.0,
                'warehouse_coefficient_percent' => $calculated['warehouse_coefficient_percent'] ?? null,
                'warehouse_coefficient_amount' => $calculated['warehouse_coefficient_amount'] ?? null,

                // === ЛОГИСТИКА ===
                'base_logistics_cost' => $calculated['base_logistics_cost'] ?? null,
                'localization_index' => $calculated['localization_index'] ?? $data['localization_index'] ?? 1.0,
                'logistics_coefficient' => $calculated['ktr'] ?? $data['localization_index'] ?? 1.0,
                'dimensions_coefficient' => $calculated['dimensions_coefficient'] ?? 1.0,
                'logistics_cost' => $calculated['logistics_cost'] ?? null,
                'logistics_with_warehouse' => $calculated['logistics_with_warehouse'] ?? null,

                // === СХЕМА РАБОТЫ ===
                'fulfillment_type' => $calculated['fulfillment_type'] ?? $data['fulfillment_type'] ?? 'FBO',

                // === % ВЫКУПА 28д ===
                'redemption_rate' => $calculated['redemption_rate'] ?? $data['redemption_rate'] ?? 80,
                'redemption_source' => $data['redemption_source'] ?? 'default',
                'orders_count' => $data['orders_count'] ?? null,
                'returns_count' => $data['returns_count'] ?? null,

                // === ХРАНЕНИЕ (FBO) ===
                'storage_tariff' => $calculated['storage_tariff'] ?? $data['storage_tariff'] ?? 0.08,
                'storage_coefficient' => $calculated['storage_coefficient'] ?? 1.0,
                'storage_days' => (int) ($calculated['storage_days'] ?? $data['storage_days'] ?? 30),
                'storage_cost' => $calculated['storage_cost'] ?? null,
                'turnover_days' => (int) ($data['turnover_days'] ?? $data['storage_days'] ?? 30),

                // === ЭКВАЙРИНГ (WB: 0%) ===
                'acquiring_percent' => $calculated['acquiring_percent'] ?? $data['acquiring_percent'] ?? 1.5,
                'acquiring_amount' => $calculated['acquiring_amount'] ?? null,

                // === ИТОГОВЫЙ % РАСХОДОВ ===
                'total_expenses_percent' => $calculated['total_expenses_percent'] ?? null,

                // === ВОЗВРАТЫ ===
                'return_logistics_cost' => $calculated['return_logistics_cost'] ?? null,
                'expected_return_cost' => $calculated['expected_return_cost'] ?? null,
                'effective_logistics' => $calculated['effective_logistics'] ?? null,
                'delivery_cost' => $calculated['delivery_cost'] ?? null,

                // === ПРИЁМКА ===
                'acceptance_cost' => $calculated['acceptance_cost'] ?? null,

                // === ШТРАФЫ ===
                'penalty_cost' => $calculated['penalty_cost'] ?? null,

                // === СВОЯ ДОСТАВКА (DBS) ===
                'own_delivery_cost' => $calculated['own_delivery_cost'] ?? null,

                // === НА РС (На расчётный счёт) ===
                'advertising_cost' => $calculated['advertising_cost'] ?? $data['advertising_cost'] ?? 0,
                'to_settlement_account' => $calculated['to_settlement_account'] ?? null,

                // === НАЛОГИ ===
                'tax_amount' => $calculated['tax_amount'] ?? null,
                'vat_amount' => $calculated['vat_amount'] ?? null,
                'drr_amount' => $calculated['drr_amount'] ?? null,
                'our_share_amount' => $calculated['our_share_amount'] ?? null,
            ];
        } elseif ($marketplace === 'yandex' || $marketplace === 'yandex_market') {
            $salesCount = max(1, $data['sales_count'] ?? 1);

            $detailed = [
                // === ГАБАРИТЫ ===
                'length_mm' => $calculated['length_mm'] ?? $data['length_mm'] ?? null,
                'width_mm' => $calculated['width_mm'] ?? $data['width_mm'] ?? null,
                'height_mm' => $calculated['height_mm'] ?? $data['height_mm'] ?? null,
                'weight_g' => $calculated['weight_g'] ?? $data['weight_g'] ?? null,
                'volume_liters' => $calculated['volume_liters'] ?? $data['volume_liters'] ?? null,

                // === СХЕМА РАБОТЫ ===
                'fulfillment_type' => $calculated['fulfillment_type'] ?? $data['fulfillment_type'] ?? 'FBY',

                // === КОМИССИЯ (реферальный сбор) ===
                'referral_fee_percent' => $calculated['referral_fee_percent'] ?? $data['referral_fee_percent'] ?? 5,
                'commission_percent' => $calculated['commission_percent'] ?? $data['referral_fee_percent'] ?? 5,
                'commission_amount' => $calculated['commission_amount'] ?? null,
                'acquiring_percent' => $calculated['acquiring_percent'] ?? $data['acquiring_percent'] ?? 0,
                'acquiring_amount' => $calculated['acquiring_amount'] ?? null,

                // === ЛОГИСТИКА ===
                'fby_delivery' => $calculated['fby_delivery'] ?? $data['fby_delivery'] ?? 50,
                'fbs_delivery' => $calculated['fbs_delivery'] ?? $data['fbs_delivery'] ?? 40,
                'delivery_to_customer' => $calculated['delivery_to_customer'] ?? null,
                'crossregional_delivery' => $calculated['crossregional_delivery'] ?? null,
                'middle_mile' => $calculated['middle_mile'] ?? null,
                'express_delivery' => $calculated['express_delivery'] ?? null,
                'sorting' => $calculated['sorting'] ?? null,
                'logistics_cost' => $calculated['logistics_cost'] ?? null,
                'delivery_cost' => $calculated['delivery_cost'] ?? null,

                // === % ВЫКУПА ===
                'redemption_rate' => $calculated['redemption_rate'] ?? $data['redemption_rate'] ?? 95,
                'redemption_source' => $data['redemption_source'] ?? 'default',

                // === ХРАНЕНИЕ ===
                'storage_cost' => $calculated['storage_cost'] ?? null,
                'turnover_days' => (int) ($data['turnover_days'] ?? 30),

                // === ВОЗВРАТЫ ===
                'return_logistics_cost' => $calculated['return_logistics_cost'] ?? null,
                'return_processing_cost' => $calculated['return_processing_cost'] ?? null,
                'expected_return_cost' => $calculated['expected_return_cost'] ?? null,
                'effective_logistics' => $calculated['effective_logistics'] ?? null,

                // === УПАКОВКА ===
                'packaging_cost' => $calculated['packaging_cost'] ?? $data['packaging_cost'] ?? null,

                // === НА РС ===
                'advertising_cost' => $calculated['advertising_cost'] ?? $data['advertising_cost'] ?? 0,
                'to_settlement_account' => $calculated['to_settlement_account'] ?? null,

                // === НАЛОГИ ===
                'tax_amount' => $calculated['tax_amount'] ?? null,
                'vat_amount' => $calculated['vat_amount'] ?? null,
                'drr_amount' => $calculated['drr_amount'] ?? null,
                'our_share_amount' => $calculated['our_share_amount'] ?? null,
            ];
        }

        return $detailed;
    }

    private function loadYandexTariffsData(
        iterable $products,
        array $yandexPricesData,
        \App\Services\Marketplace\YandexMarketService $yandexTariffService,
        string $campaignId
    ): array {
        $offersBySku = [];

        foreach ($products as $product) {
            $offer = $this->buildYandexTariffOffer($product, $yandexPricesData[$product->sku] ?? null);
            if ($offer !== null) {
                $offersBySku[$product->sku] = $offer;
            }
        }

        if (empty($offersBySku)) {
            return [];
        }

        $tariffsByScheme = [];
        foreach (['FBY', 'FBS', 'DBS', 'EXPRESS'] as $scheme) {
            foreach (array_chunk($offersBySku, 100, true) as $chunk) {
                $responses = $yandexTariffService->calculateTariffs(array_values($chunk), [
                    'campaign_id' => $campaignId,
                    'selling_program' => $scheme,
                ]);

                $responseValues = array_values($responses);
                $skuKeys = array_keys($chunk);

                foreach ($skuKeys as $index => $sku) {
                    $responseItem = $responseValues[$index] ?? null;
                    if (! is_array($responseItem)) {
                        continue;
                    }

                    $tariffsByScheme[$scheme][$sku] = $this->extractYandexTariffBreakdown($responseItem);
                }
            }
        }

        return $tariffsByScheme;
    }

    private function buildYandexTariffOffer(Product $product, ?array $priceData): ?array
    {
        $price = (float) ($priceData['price'] ?? $product->price ?? 0);
        if ($price <= 0) {
            return null;
        }

        $yandexData = $product->yandex_data ?? [];
        $categoryId = (int) ($yandexData['categoryId'] ?? 0);
        $lengthCm = ((float) ($yandexData['length_mm'] ?? $product->depth ?? 0)) / 10;
        $widthCm = ((float) ($yandexData['width_mm'] ?? $product->width ?? 0)) / 10;
        $heightCm = ((float) ($yandexData['height_mm'] ?? $product->height ?? 0)) / 10;
        $weightKg = ((float) ($yandexData['weight_g'] ?? $product->weight ?? 0)) / 1000;

        if ($categoryId <= 0 || $lengthCm <= 0 || $widthCm <= 0 || $heightCm <= 0 || $weightKg <= 0) {
            return null;
        }

        return [
            'category_id' => $categoryId,
            'price' => $price,
            'length' => round($lengthCm, 2),
            'width' => round($widthCm, 2),
            'height' => round($heightCm, 2),
            'weight' => round($weightKg, 3),
            'quantity' => 1,
        ];
    }

    private function extractYandexTariffBreakdown(array $responseItem): array
    {
        if (isset($responseItem['tariffs']) && is_array($responseItem['tariffs'])) {
            return $responseItem['tariffs'];
        }

        if (isset($responseItem['services']) && is_array($responseItem['services'])) {
            return $responseItem['services'];
        }

        if (isset($responseItem[0]) && is_array($responseItem[0])) {
            return $responseItem;
        }

        return [];
    }

    private function normalizeYandexTariffBreakdown(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = strtoupper((string) ($item['type'] ?? $item['tariffType'] ?? $item['serviceType'] ?? $item['code'] ?? ''));
            if ($type === '') {
                continue;
            }

            $amount = $item['amount'] ?? $item['price'] ?? $item['total'] ?? $item['value'] ?? 0;
            if (is_array($amount)) {
                $amount = $amount['value'] ?? 0;
            }

            $normalized[$type] = (float) $amount;
        }

        return $normalized;
    }

    private function buildOzonDeliveryProfiles(array $recommendations, array $stockProfiles = []): array
    {
        $profiles = [];
        $pricing = new OzonPricingMatrix();
        $clusterDirectory = \App\Models\OzonWarehouseCluster::query()
            ->select('cluster_id', 'cluster_name', 'region')
            ->get()
            ->groupBy(fn ($item) => (string) $item->cluster_id)
            ->map(function ($rows): array {
                $first = $rows->first();

                return [
                    'cluster_name' => $first?->cluster_name,
                    'region' => $first?->region,
                ];
            })
            ->all();

        foreach ($recommendations as $item) {
            $sku = (string) ($item['sku'] ?? '');
            if ($sku === '') {
                continue;
            }

            $clusters = array_values($item['clusters'] ?? []);
            $stockProfile = $stockProfiles[$sku] ?? [];
            $stockClusterIds = array_map('strval', array_keys($stockProfile['cluster_distribution'] ?? []));
            $dominantClusterId = null;
            $dominantClusterShare = 0.0;
            $expectedLocalityRate = null;
            $localDemandShare = 0.0;
            $demandShareTotal = 0.0;
            $weightedLogisticsCost = null;
            $weightedNonLocalMarkupPercent = 0.0;
            $hasWeightedMarkup = false;
            $clustersSummary = [];
            $sales7Days = (int) ($stockProfile['total_sales_7_days'] ?? 0);
            $markupAllowed = (string) ($item['delivery_schema'] ?? 'ALL') !== 'FBO' || $sales7Days >= 50;
            $markupRuleReason = $markupAllowed ? null : 'fbo_lt_50_orders_7d';

            foreach ($clusters as $cluster) {
                $share = (float) ($cluster['orders_percent'] ?? 0);
                if ($share > $dominantClusterShare) {
                    $dominantClusterShare = $share;
                    $dominantClusterId = (string) ($cluster['cluster_id'] ?? '');
                }

                if ($share <= 0) {
                    continue;
                }

                $demandShareTotal += $share;

                $clusterId = (string) ($cluster['cluster_id'] ?? '');
                $isLocalCluster = $clusterId !== '' && in_array($clusterId, $stockClusterIds, true);
                if ($isLocalCluster) {
                    $localDemandShare += $share;
                }

                $clusterMeta = $clusterDirectory[$clusterId] ?? [];
                $clusterName = $cluster['cluster_name'] ?? $clusterMeta['cluster_name'] ?? "Кластер {$clusterId}";
                $clusterRegion = $cluster['region'] ?? $clusterMeta['region'] ?? null;
                $route = $pricing->resolveRoute(null, $clusterName);
                $clusterMarkupPercent = $pricing->resolveDestinationMarkupPercent($clusterName);
                $effectiveClusterMarkupPercent = (!$markupAllowed || $isLocalCluster) ? 0.0 : $clusterMarkupPercent;
                $markupReason = !$markupAllowed
                    ? $markupRuleReason
                    : ($isLocalCluster ? 'local_cluster' : ($clusterMarkupPercent > 0 ? 'non_local_markup_applied' : 'no_markup_for_cluster'));

                if ($stockClusterIds !== []) {
                    $weightedNonLocalMarkupPercent += ($share / 100) * $effectiveClusterMarkupPercent;
                    $hasWeightedMarkup = true;
                }
                $clustersSummary[] = [
                    'cluster_id' => $clusterId !== '' ? $clusterId : null,
                    'cluster_name' => $clusterName,
                    'region' => $clusterRegion,
                    'orders_count' => $cluster['orders_count'] ?? 0,
                    'orders_percent' => $share,
                    'delivery_time_fbo' => $cluster['delivery_time_fbo'] ?? null,
                    'delivery_time_fbs' => $cluster['delivery_time_fbs'] ?? null,
                    'is_local_cluster' => $isLocalCluster,
                    'route_key' => $route['route_key'] ?? null,
                    'route_label' => $route['route_label'] ?? null,
                    'non_local_markup_percent' => $clusterMarkupPercent,
                    'effective_markup_percent' => $effectiveClusterMarkupPercent,
                    'markup_reason' => $markupReason,
                ];
            }

            if ($stockClusterIds !== [] && $demandShareTotal > 0) {
                $expectedLocalityRate = round(min(100.0, $localDemandShare), 2);
            }

            if ($clusters !== []) {
                $weightedLogisticsCost = round($this->estimateWeightedLogisticsCost(
                    (string) ($item['delivery_schema'] ?? 'ALL'),
                    $clusters
                ), 2);
            }

            $isSingleDemandCluster = count(array_filter($clusters, fn (array $cluster): bool => (float) ($cluster['orders_percent'] ?? 0) > 0)) === 1;
            $isSingleStockCluster = count($stockClusterIds) === 1;
            $localityResolved = $isSingleDemandCluster && $isSingleStockCluster;
            $routeResolutionStatus = !empty($stockProfile['dominant_cluster_id'])
                ? 'resolved'
                : (!empty($clusters) ? 'estimated' : 'unknown');
            $localityResolutionStatus = $localityResolved
                ? 'resolved'
                : (($expectedLocalityRate !== null || !empty($clusters)) ? 'estimated' : 'unknown');
            $calculationConfidence = match (true) {
                $routeResolutionStatus === 'resolved' && $dominantClusterShare >= 70 => 'high',
                $routeResolutionStatus === 'resolved' && $dominantClusterShare >= 45 => 'medium',
                $dominantClusterShare >= 70 => 'medium',
                !empty($clusters) => 'low',
                default => 'low',
            };

            $profiles[$sku] = [
                'route_key' => $stockProfile['route_key'] ?? null,
                'route_label' => $stockProfile['route_label'] ?? null,
                'route_resolution_status' => $routeResolutionStatus,
                'locality_resolution_status' => $localityResolutionStatus,
                'calculation_confidence' => $calculationConfidence,
                'profile_source' => 'delivery_analytics',
                'dominant_cluster_id' => $dominantClusterId ?: null,
                'dominant_cluster_share' => $dominantClusterShare > 0 ? round($dominantClusterShare, 2) : null,
                'expected_locality_rate' => $expectedLocalityRate,
                'weighted_non_local_markup_percent' => $hasWeightedMarkup ? round($weightedNonLocalMarkupPercent, 2) : null,
                'weighted_logistics_cost' => $weightedLogisticsCost,
                'markup_allowed' => $markupAllowed,
                'markup_rule_reason' => $markupRuleReason,
                'sales_7_days' => $sales7Days,
                'is_local_sale' => $localityResolved
                    ? ($expectedLocalityRate !== null ? $expectedLocalityRate >= 100.0 : null)
                    : null,
                'clusters_summary' => $clustersSummary,
                'stock_profile' => $stockProfile['stock_profile'] ?? [],
                'sales_profile' => $stockProfile['sales_profile'] ?? [],
                'route_details' => [
                    'stock_clusters' => $stockProfile['stock_profile'] ?? [],
                    'sales_clusters' => $stockProfile['sales_profile'] ?? [],
                    'demand_clusters' => $clustersSummary,
                ],
            ];
        }

        return $profiles;
    }

    private function buildOzonStockProfiles(\Illuminate\Support\Collection $inventoryRaw, array $directSalesByWarehouse = []): array
    {
        $profiles = [];

        // Pre-load all warehouse clusters to avoid N*M queries per SKU
        $warehouseClusterMap = \App\Models\OzonWarehouseCluster::all()
            ->keyBy(fn($c) => $c->warehouse_name_normalized);

        foreach ($inventoryRaw->groupBy('sku') as $sku => $items) {
            $stockItems = $items->filter(fn ($item) => (int) ($item->quantity ?? 0) > 0);
            if ($stockItems->isEmpty()) {
                $stockItems = $items;
            }

            $clusterDistribution = [];
            $clusterNames = [];
            $totalQuantity = 0;
            $salesDistribution = [];
            $totalSales = 0;
            $warehouseBuckets = [];

            foreach ($stockItems as $item) {
                $warehouseName = (string) ($item->warehouse_name ?? '');
                if ($warehouseName === '') {
                    continue;
                }

                $normalizedName = \App\Models\OzonWarehouseCluster::normalizeWarehouseName($warehouseName);
                $cluster = $warehouseClusterMap[$normalizedName] ?? null;
                if (! $cluster) {
                    continue;
                }

                $clusterId = (string) $cluster->cluster_id;
                $quantity = max(0, (int) ($item->quantity ?? 0));
                if ($quantity <= 0) {
                    continue;
                }
                $clusterDistribution[$clusterId] = ($clusterDistribution[$clusterId] ?? 0) + $quantity;
                $clusterNames[$clusterId] = $cluster->cluster_name;
                $totalQuantity += $quantity;
                $warehouseKey = $clusterId.'|'.$warehouseName;
                $warehouseBuckets[$warehouseKey] = [
                    'warehouse_name' => $warehouseName,
                    'cluster_id' => $clusterId,
                    'cluster_name' => $cluster->cluster_name,
                    'region' => $cluster->region,
                    'quantity' => $quantity,
                    'sales_7_days' => 0,
                    'sales_30_days' => 0,
                ];

                if (empty($directSalesByWarehouse[(string) $sku])) {
                    $sales7 = max(0, (int) ($item->sales_7_days ?? 0));
                    $sales = max(0, (int) ($item->sales_30_days ?? 0));
                    $salesDistribution[$clusterId] = ($salesDistribution[$clusterId] ?? 0) + $sales;
                    $totalSales += $sales;
                    $warehouseBuckets[$warehouseKey]['sales_7_days'] = $sales7;
                    $warehouseBuckets[$warehouseKey]['sales_30_days'] = $sales;
                }
            }

            if (!empty($directSalesByWarehouse[(string) $sku])) {
                foreach ($directSalesByWarehouse[(string) $sku] as $warehouseSales) {
                    $warehouseName = (string) ($warehouseSales['warehouse_name'] ?? '');
                    if ($warehouseName === '') {
                        continue;
                    }

                    $normalizedName = \App\Models\OzonWarehouseCluster::normalizeWarehouseName($warehouseName);
                    $cluster = $warehouseClusterMap[$normalizedName] ?? null;
                    if (! $cluster) {
                        continue;
                    }

                    $clusterId = (string) $cluster->cluster_id;
                    $clusterNames[$clusterId] = $cluster->cluster_name;
                    $sales7 = max(0, (int) ($warehouseSales['sales_7_days'] ?? 0));
                    $sales = max(0, (int) ($warehouseSales['sales_30_days'] ?? 0));
                    $salesDistribution[$clusterId] = ($salesDistribution[$clusterId] ?? 0) + $sales;
                    $totalSales += $sales;
                    $warehouseKey = $clusterId.'|'.$warehouseName;
                    if (isset($warehouseBuckets[$warehouseKey])) {
                        $warehouseBuckets[$warehouseKey]['sales_7_days'] = $sales7;
                        $warehouseBuckets[$warehouseKey]['sales_30_days'] = $sales;
                    }
                }
            }

            if ($clusterDistribution === []) {
                continue;
            }

            arsort($clusterDistribution);
            arsort($salesDistribution);
            $dominantClusterId = (string) array_key_first($clusterDistribution);
            $dominantClusterQuantity = (float) ($clusterDistribution[$dominantClusterId] ?? 0);
            $dominantClusterShare = $totalQuantity > 0
                ? round(($dominantClusterQuantity / $totalQuantity) * 100, 2)
                : null;
            $dominantSalesClusterId = $salesDistribution !== [] ? (string) array_key_first($salesDistribution) : null;
            $dominantSalesClusterUnits = $dominantSalesClusterId !== null
                ? (float) ($salesDistribution[$dominantSalesClusterId] ?? 0)
                : 0.0;
            $dominantSalesClusterShare = $totalSales > 0
                ? round(($dominantSalesClusterUnits / $totalSales) * 100, 2)
                : null;

            $stockProfile = [];
            foreach ($clusterDistribution as $clusterId => $qty) {
                $warehouses = array_values(array_filter(
                    $warehouseBuckets,
                    fn (array $bucket): bool => (string) ($bucket['cluster_id'] ?? '') === (string) $clusterId
                ));
                $stockProfile[] = [
                    'cluster_id' => $clusterId,
                    'cluster_name' => $clusterNames[$clusterId] ?? null,
                    'quantity' => $qty,
                    'share_percent' => $totalQuantity > 0 ? round(($qty / $totalQuantity) * 100, 2) : null,
                    'warehouses' => $warehouses,
                ];
            }

            $salesProfile = [];
            foreach ($salesDistribution as $clusterId => $sales) {
                $salesProfile[] = [
                    'cluster_id' => $clusterId,
                    'cluster_name' => $clusterNames[$clusterId] ?? null,
                    'sales_30_days' => $sales,
                    'sales_share_percent' => $totalSales > 0 ? round(($sales / $totalSales) * 100, 2) : null,
                ];
            }

            $profiles[(string) $sku] = [
                'route_key' => null,
                'route_label' => $clusterNames[$dominantClusterId] ?? null,
                'dominant_cluster_id' => $dominantClusterId,
                'dominant_cluster_share' => $dominantClusterShare,
                'cluster_distribution' => $clusterDistribution,
                'cluster_names' => $clusterNames,
                'stock_profile' => $stockProfile,
                'sales_profile' => $salesProfile,
                'total_sales_7_days' => $warehouseBuckets !== [] ? array_sum(array_map(static fn (array $bucket): int => (int) ($bucket['sales_7_days'] ?? 0), $warehouseBuckets)) : 0,
                'dominant_sales_cluster_id' => $dominantSalesClusterId,
                'dominant_sales_cluster_share' => $dominantSalesClusterShare,
            ];
        }

        return $profiles;
    }

    private function mergeOzonSalesByWarehouse(array $fboSalesByWarehouse, array $fbsSalesByWarehouse): array
    {
        $result = [];

        foreach ([['source' => $fboSalesByWarehouse, 'default_type' => 'FBO'], ['source' => $fbsSalesByWarehouse, 'default_type' => 'FBS']] as $entry) {
            foreach ($entry['source'] as $sku => $warehouses) {
                foreach ($warehouses as $warehouseId => $data) {
                    $fulfillmentType = $data['fulfillment_type'] ?? $entry['default_type'];
                    $key = $warehouseId . '_' . $fulfillmentType;
                    $result[$sku][$key] = [
                        'warehouse_name' => $data['warehouse_name'] ?? $warehouseId,
                        'sales_7_days' => (int) ($data['sales_7_days'] ?? 0),
                        'sales_14_days' => (int) ($data['sales_14_days'] ?? 0),
                        'sales_30_days' => (int) ($data['sales_30_days'] ?? 0),
                        'avg_daily_sales' => (float) ($data['avg_daily_sales'] ?? 0),
                        'ordered_units_total' => (int) ($data['ordered_units_total'] ?? 0),
                        'fulfillment_type' => $fulfillmentType,
                    ];
                }
            }
        }

        return $result;
    }

    private function getIntegrationCredentialsSafely(?\App\Models\Integration $integration): array
    {
        if (! $integration) {
            return [];
        }

        try {
            $credentials = $integration->getDecryptedCredentials();
            return is_array($credentials) ? $credentials : [];
        } catch (\Throwable $e) {
            $this->warn("  Не удалось расшифровать локальные credentials integration_id={$integration->id}: {$e->getMessage()}");
            return [];
        }
    }

    private function persistIntegrationCredentials(\App\Models\Integration $integration, array $patch): void
    {
        $current = $this->getIntegrationCredentialsSafely($integration);
        $merged = array_merge($current, array_filter($patch, static fn ($value) => $value !== null && $value !== ''));

        if ($merged === $current || $merged === []) {
            return;
        }

        $integration->forceFill(['credentials' => $merged])->saveQuietly();
        $integration->setAttribute('credentials', $merged);
    }

    private function persistOzonDeliveryProfile(int $integrationId, Product $product, ?array $deliveryProfile): void
    {
        if ($deliveryProfile === null) {
            return;
        }

        $current = is_array($product->ozon_data ?? null) ? $product->ozon_data : [];
        $patch = array_filter([
            'route_key' => $deliveryProfile['route_key'] ?? null,
            'route_label' => $deliveryProfile['route_label'] ?? null,
            'route_resolution_status' => $deliveryProfile['route_resolution_status'] ?? null,
            'locality_resolution_status' => $deliveryProfile['locality_resolution_status'] ?? null,
            'calculation_confidence' => $deliveryProfile['calculation_confidence'] ?? null,
            'profile_source' => $deliveryProfile['profile_source'] ?? null,
            'dominant_cluster_id' => $deliveryProfile['dominant_cluster_id'] ?? null,
            'dominant_cluster_share' => $deliveryProfile['dominant_cluster_share'] ?? null,
            'expected_locality_rate' => $deliveryProfile['expected_locality_rate'] ?? null,
            'weighted_non_local_markup_percent' => $deliveryProfile['weighted_non_local_markup_percent'] ?? null,
            'weighted_logistics_cost' => $deliveryProfile['weighted_logistics_cost'] ?? null,
            'is_local_sale' => $deliveryProfile['is_local_sale'] ?? null,
            'clusters_summary' => $deliveryProfile['clusters_summary'] ?? [],
            'stock_profile' => $deliveryProfile['stock_profile'] ?? [],
            'sales_profile' => $deliveryProfile['sales_profile'] ?? [],
            'route_details' => $deliveryProfile['route_details'] ?? [],
            'delivery_profile_synced_at' => now()->toIso8601String(),
        ], static fn ($value, string $key): bool => $value !== null || in_array($key, ['clusters_summary', 'stock_profile', 'sales_profile', 'route_details'], true), ARRAY_FILTER_USE_BOTH);

        if ($patch === []) {
            return;
        }

        $merged = array_merge($current, $patch);
        if ($merged === $current) {
            $this->upsertOzonDeliveryProfileRecord($integrationId, $product, $deliveryProfile);
            return;
        }

        $product->forceFill(['ozon_data' => $merged])->saveQuietly();
        $product->setAttribute('ozon_data', $merged);
        $this->upsertOzonDeliveryProfileRecord($integrationId, $product, $deliveryProfile);
    }

    private function upsertOzonDeliveryProfileRecord(int $integrationId, Product $product, array $deliveryProfile): void
    {
        $clustersSummary = is_array($deliveryProfile['clusters_summary'] ?? null)
            ? $deliveryProfile['clusters_summary']
            : [];
        $stockProfile = [
            'route_key' => $deliveryProfile['route_key'] ?? null,
            'route_label' => $deliveryProfile['route_label'] ?? null,
            'is_local_sale' => $deliveryProfile['is_local_sale'] ?? null,
            'clusters' => $deliveryProfile['stock_profile'] ?? [],
        ];
        $salesProfile = [
            'clusters' => $deliveryProfile['sales_profile'] ?? [],
        ];
        $clusterProfile = [
            'dominant_cluster_id' => $deliveryProfile['dominant_cluster_id'] ?? null,
            'dominant_cluster_share' => $deliveryProfile['dominant_cluster_share'] ?? null,
            'clusters_summary' => $clustersSummary,
        ];

        OzonSkuDeliveryProfile::updateOrCreate(
            [
                'integration_id' => $integrationId,
                'sku' => $product->sku,
                'scheme' => 'ALL',
            ],
            [
                'offer_id' => $product->sku,
                'ozon_sku' => (string) (($product->ozon_data['sku'] ?? null) ?: ''),
                'stock_profile' => $stockProfile,
                'sales_profile' => $salesProfile,
                'cluster_profile' => $clusterProfile,
                'dominant_stock_cluster_id' => $deliveryProfile['stock_profile'][0]['cluster_id'] ?? null,
                'dominant_stock_cluster_share' => $deliveryProfile['stock_profile'][0]['share_percent'] ?? null,
                'dominant_sales_cluster_id' => $deliveryProfile['dominant_sales_cluster_id'] ?? null,
                'dominant_sales_cluster_share' => $deliveryProfile['dominant_sales_cluster_share'] ?? null,
                'dominant_demand_cluster_id' => $deliveryProfile['dominant_cluster_id'] ?? null,
                'dominant_demand_cluster_share' => $deliveryProfile['dominant_cluster_share'] ?? null,
                'expected_locality_rate' => $deliveryProfile['expected_locality_rate'] ?? null,
                'weighted_non_local_markup_percent' => $deliveryProfile['weighted_non_local_markup_percent'] ?? null,
                'weighted_logistics_cost' => $deliveryProfile['weighted_logistics_cost'] ?? null,
                'profile_source' => $deliveryProfile['profile_source'] ?? 'delivery_analytics',
                'route_resolution_status' => $deliveryProfile['route_resolution_status'] ?? 'unknown',
                'locality_resolution_status' => $deliveryProfile['locality_resolution_status'] ?? 'unknown',
                'calculation_confidence' => $deliveryProfile['calculation_confidence'] ?? 'low',
                'calculated_at' => now(),
            ]
        );
    }

    private function estimateWeightedLogisticsCost(string $deliverySchema, array $clusters): float
    {
        $scheme = strtoupper($deliverySchema) === 'FBS' ? 'FBS' : 'FBO';
        $routeConfig = config('ozon_unit_economics.routes', []);
        $aliases = config('ozon_unit_economics.route_aliases', []);
        $defaultRouteKey = config('ozon_unit_economics.default_route.key', 'cluster_msk');

        $resolveRouteKey = function (?string $clusterId) use ($aliases, $defaultRouteKey): string {
            $needle = mb_strtolower((string) $clusterId);
            foreach ($aliases as $fragment => $routeKey) {
                if ($needle !== '' && str_contains($needle, (string) $fragment)) {
                    return (string) $routeKey;
                }
            }

            return $defaultRouteKey;
        };

        $sum = 0.0;
        foreach ($clusters as $cluster) {
            $share = ((float) ($cluster['orders_percent'] ?? 0)) / 100;
            if ($share <= 0) {
                continue;
            }

            $routeKey = $resolveRouteKey((string) ($cluster['cluster_id'] ?? ''));
            $base = (float) (($routeConfig[$routeKey][$scheme]['up_to_1l'] ?? 0));
            $sum += $share * $base;
        }

        return $sum;
    }

    /**
     * Извлечь числовое значение из строки с единицами измерения
     */
    private function extractNumericValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        // Если уже число — возвращаем как float
        if (is_numeric($value)) {
            return (float) $value;
        }

        // Если не строка — пробуем привести
        if (! is_string($value)) {
            $value = (string) $value;
        }

        // Убираем всё кроме цифр, точки и запятой
        $numeric = preg_replace('/[^\d.,]/', '', $value);
        $numeric = str_replace(',', '.', $numeric);

        return $numeric !== '' ? (float) $numeric : null;
    }
}
