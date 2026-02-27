<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\InventoryWarehouse;
use App\Services\UnitEconomicsService;
use Illuminate\Console\Command;

class SyncUnitEconomicsCommand extends Command
{
    protected $signature = 'unit-economics:sync 
                            {--integration= : Integration ID to sync}
                            {--marketplace= : Marketplace to sync (wildberries, ozon, yandex)}
                            {--all : Sync all integrations}';

    protected $description = 'Sync unit economics from real product data';

    public function handle(UnitEconomicsService $service): int
    {
        $integrationId = $this->option('integration');
        $marketplace = $this->option('marketplace');
        $syncAll = $this->option('all');

        if ($syncAll) {
            return $this->syncAll($service);
        }

        if ($integrationId) {
            return $this->syncByIntegrationId($service, (int) $integrationId);
        }

        if ($marketplace) {
            return $this->syncByMarketplace($service, $marketplace);
        }

        $this->error('Please specify --integration=ID, --marketplace=NAME, or --all');
        return 1;
    }

    private function syncAll(UnitEconomicsService $service): int
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
            
            $result = $this->syncProducts($service, $int->integration_id, $int->marketplace);
            
            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'];
            
            $this->info("  Synced: {$result['synced']}, Errors: {$result['errors']}");
        }

        $this->newLine();
        $this->info("Total synced: {$totalSynced}, Total errors: {$totalErrors}");

        return 0;
    }

    private function syncByIntegrationId(UnitEconomicsService $service, int $integrationId): int
    {
        $marketplace = Product::where('integration_id', $integrationId)->value('marketplace');
        
        if (!$marketplace) {
            $this->error("No products found for integration_id={$integrationId}");
            return 1;
        }

        $this->info("Syncing integration_id={$integrationId} ({$marketplace})...");
        
        $result = $this->syncProducts($service, $integrationId, $marketplace);
        
        $this->info("Synced: {$result['synced']}, Errors: {$result['errors']}");

        return 0;
    }

    private function syncByMarketplace(UnitEconomicsService $service, string $marketplace): int
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
            
            $result = $this->syncProducts($service, $integrationId, $marketplace);
            
            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'];
        }

        $this->info("Total synced: {$totalSynced}, Total errors: {$totalErrors}");

        return 0;
    }

    private function syncProducts(UnitEconomicsService $service, int $integrationId, string $marketplace): array
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
        $vendorToSku = $products->filter(fn($p) => $p->vendor_code)->pluck('sku', 'vendor_code');

        // Агрегируем данные по складам для каждого SKU
        $inventoryRaw = InventoryWarehouse::where('marketplace', $marketplace)
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
            if (!$costFromSettings) {
                $vendorCode = array_search($sku, $vendorToSku->toArray());
                if ($vendorCode) {
                    $costFromSettings = $costPriceSettings[$vendorCode]->cost_price ?? null;
                }
            }
            $costFromInventory = $items->max('cost_price');

            return (object) [
                'sku' => $sku,
                'cost_price' => $costFromSettings ?? $costFromInventory, // Берём из настроек или из inventory
                'sales_30_days' => $items->sum('sales_30_days'), // Суммируем продажи по всем складам
                'storage_cost_per_month' => $items->sum('storage_cost_per_month'), // Суммируем хранение
                'fulfillment_type' => $actualFulfillmentType, // Схема с наибольшими остатками
                'turnover_days' => $items->first()->turnover_days ?? 30,
            ];
        });
        
        // Получаем фактические затраты и индекс локализации из API (если Ozon)
        $actualCosts = [];
        $localizationIndex = null;
        $acquiringData = [];
        $redemptionData = [];
        $productPrices = []; // Актуальные цены из API (включая акционные)
        $manualRedemptionRate = null; // Инициализируем для всех маркетплейсов
        if ($marketplace === 'ozon') {
            try {
                // Приоритет: 1) локальная интеграция, 2) Sellico API, 3) глобальные из config/env
                $clientId = null;
                $apiKey = null;
                
                // 1. Пробуем локальную интеграцию
                if ($integration && !empty($integration->credentials['client_id'])) {
                    $clientId = $integration->credentials['client_id'];
                    $apiKey = $integration->credentials['api_key'] ?? '';
                }
                
                // 2. Пробуем Sellico API
                if (empty($clientId)) {
                    $sellicoService = new \App\Services\SellicoApiService();
                    $sellicoResult = $sellicoService->getIntegrationById($integrationId);
                    
                    if ($sellicoResult['success'] && !empty($sellicoResult['credentials'])) {
                        $clientId = $sellicoResult['credentials']['client_id'] ?? '';
                        $apiKey = $sellicoResult['credentials']['api_key'] ?? '';
                        $this->info("  Credentials получены из Sellico API");
                    }
                }
                
                // 3. Fallback на глобальные из config/env
                if (empty($clientId)) {
                    $clientId = config('services.ozon.client_id', '');
                    $apiKey = config('services.ozon.api_key', '');
                }
                
                if (!empty($clientId) && !empty($apiKey)) {
                    $ozonService = new \App\Domains\Ozon\OzonMarketplace(['client_id' => $clientId, 'api_key' => $apiKey]);
                    $actualCosts = $ozonService->getActualCostsBySku();
                    
                    // Получаем актуальные цены (включая акционные marketing_seller_price)
                    $productPrices = $ozonService->getProductPrices();
                    $promotionCount = count(array_filter($productPrices, fn($p) => $p['is_in_promotion'] ?? false));
                    $this->info("  Цены: " . count($productPrices) . " товаров, " . $promotionCount . " в акциях");
                    
                    // Логируем примеры товаров в акциях для диагностики
                    $promotionExamples = array_filter($productPrices, fn($p) => $p['is_in_promotion'] ?? false);
                    $exampleSkus = array_slice(array_keys($promotionExamples), 0, 3);
                    foreach ($exampleSkus as $exSku) {
                        $ex = $promotionExamples[$exSku];
                        $this->info("    Пример акции: {$exSku} - базовая: {$ex['price']}₽, акционная: {$ex['actual_price']}₽ (-{$ex['promotion_discount']}%)");
                    }
                    
                    // Получаем индекс локализации (среднее время доставки) из API с TTL 24ч
                    if ($integration && !$integration->needsLocalizationCheck()) {
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
                    $isPremium = $integration?->is_premium ?? null;
                    $manualRedemptionRate = $integration?->manual_redemption_rate ?? null;
                    
                    if ($integration && $integration->needsPremiumCheck()) {
                        $premiumStatus = $ozonService->checkPremiumStatus();
                        $isPremium = $premiumStatus['is_premium'] ?? false;
                        
                        // Сохраняем статус в интеграцию (если она существует в локальной БД)
                        if ($integration) {
                            $integration->update([
                                'is_premium' => $isPremium,
                                'premium_checked_at' => now(),
                            ]);
                        }
                        
                        $this->info("  Premium статус: " . ($isPremium ? '✓ Premium (авто-выкуп)' : '✗ Не Premium (ручной ввод)'));
                    } else {
                        $this->info("  Premium статус: " . ($isPremium ? '✓ Premium' : '✗ Не Premium') . " (кэш)");
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
                                        $ozonSkuToOfferIdMap[(string)$ozonSku] = $offerId;
                                    }
                                }
                            });
                        
                        $this->info("  Создан маппинг ozon_sku -> offer_id для " . count($ozonSkuToOfferIdMap) . " товаров");
                        
                        // Premium: получаем данные автоматически из API аналитики с маппингом
                        $redemptionData = $ozonService->getRedemptionRateFromAnalytics(null, null, $ozonSkuToOfferIdMap);
                        
                        if (!empty($redemptionData)) {
                            $fullDataCount = count(array_filter($redemptionData, fn($d) => ($d['has_full_data'] ?? false)));
                            $this->info("  Получен выкуп для " . count($redemptionData) . " товаров (полных: {$fullDataCount})");
                            
                            // Логируем примеры для диагностики
                            $sampleKeys = array_slice(array_keys($redemptionData), 0, 5);
                            $this->info("  Примеры ключей redemptionData: " . implode(', ', $sampleKeys));
                        } else {
                            $this->warn("  ⚠ API аналитики не вернул данных о выкупе");
                        }
                    } else {
                        // Не Premium: используем ручной ввод или fallback через заказы/возвраты
                        if ($manualRedemptionRate !== null && $manualRedemptionRate > 0) {
                            $this->info("  Используем ручной процент выкупа: {$manualRedemptionRate}%");
                            // Ручной ввод будет применён в buildCalculationData
                        } else {
                            // Fallback: пробуем получить через заказы и возвраты
                            $this->info("  Нет ручного выкупа, пробуем fallback через заказы/возвраты");
                            
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
                            
                            if (!empty($redemptionData)) {
                                $this->info("  Fallback: получен выкуп для " . count($redemptionData) . " товаров");
                            } else {
                                $this->warn("  ⚠ Нет данных о выкупе. Установите manual_redemption_rate в настройках интеграции.");
                            }
                        }
                    }
                    
                    // TODO: Эквайринг из финансовых транзакций отключён (слишком много данных, OOM)
                    // $acquiringData = $ozonService->getAcquiringBySku();
                    // Используем фиксированный 1.5% (стандартная ставка Ozon)
                } else {
                    $this->warn("  Нет credentials для Ozon API");
                }
            } catch (\Exception $e) {
                $this->warn("  Не удалось получить данные из API: {$e->getMessage()}");
            }
        }
        
        // === WILDBERRIES API ===
        $wbSalesData = [];
        $wbStorageData = [];
        $wbTariffsData = [];
        $wbSppData = []; // СПП из статистики продаж
        
        if ($marketplace === 'wildberries') {
            try {
                $wbApiKey = null;
                
                // 1. Пробуем локальную интеграцию
                if ($integration && !empty($integration->credentials['api_key'])) {
                    $wbApiKey = $integration->credentials['api_key'];
                }
                
                // 2. Пробуем Sellico API
                if (empty($wbApiKey)) {
                    $sellicoService = new \App\Services\SellicoApiService();
                    $sellicoResult = $sellicoService->getIntegrationById($integrationId);
                    
                    if ($sellicoResult['success'] && !empty($sellicoResult['credentials'])) {
                        $wbApiKey = $sellicoResult['credentials']['api_key'] ?? '';
                        $this->info("  WB Credentials получены из Sellico API");
                    }
                }
                
                if (!empty($wbApiKey)) {
                    $wbService = new \App\Domains\Wildberries\WildberriesMarketplace(['api_key' => $wbApiKey]);
                    
                    // Получаем продажи по SKU (7/14/30 дней)
                    $wbSalesData = $wbService->getSalesBySku();
                    if (!empty($wbSalesData)) {
                        $this->info("  WB Продажи: получено для " . count($wbSalesData) . " SKU");
                    }
                    
                    // Получаем стоимость хранения по SKU
                    $wbStorageData = $wbService->getStorageCostBySku();
                    if (!empty($wbStorageData)) {
                        $this->info("  WB Хранение: получено для " . count($wbStorageData) . " SKU");
                    }
                    
                    // Получаем тарифы на поставку (коэффициенты складов)
                    $wbTariffsData = $wbService->getSupplyTariffs();
                    if (!empty($wbTariffsData)) {
                        $this->info("  WB Тарифы: получено для " . count($wbTariffsData) . " складов");
                    }
                    
                    // === ПОЛУЧАЕМ СПП ИЗ СТАТИСТИКИ ПРОДАЖ ===
                    $wbSppData = $wbService->getSppFromSales(30); // За последние 30 дней
                    if (!empty($wbSppData)) {
                        $this->info("  WB СПП: получено для " . count($wbSppData) . " товаров");
                    }
                    
                    // Ручной % выкупа из интеграции
                    $manualRedemptionRate = $integration?->manual_redemption_rate ?? null;
                    if ($manualRedemptionRate) {
                        $this->info("  WB Ручной % выкупа: {$manualRedemptionRate}%");
                    }
                } else {
                    $this->warn("  Нет credentials для WB API");
                }
            } catch (\Exception $e) {
                $this->warn("  Не удалось получить данные из WB API: {$e->getMessage()}");
            }
        }
        
        // === YANDEX MARKET API ===
        $yandexSalesData = [];
        $yandexPricesData = [];
        
        if ($marketplace === 'yandex' || $marketplace === 'yandex_market') {
            try {
                $yandexToken = null;
                $yandexCampaignId = null;
                $yandexBusinessId = null;
                
                // 1. Пробуем локальную интеграцию
                if ($integration && !empty($integration->credentials['token'])) {
                    $yandexToken = $integration->credentials['token'];
                    $yandexCampaignId = $integration->credentials['campaign_id'] ?? $integration->credentials['client_id'] ?? null;
                    $yandexBusinessId = $integration->credentials['business_id'] ?? null;
                }
                
                // 2. Пробуем Sellico API
                if (empty($yandexToken)) {
                    $sellicoService = new \App\Services\SellicoApiService();
                    $sellicoResult = $sellicoService->getIntegrationById($integrationId);
                    
                    if ($sellicoResult['success'] && !empty($sellicoResult['credentials'])) {
                        $yandexToken = $sellicoResult['credentials']['token'] ?? '';
                        $yandexCampaignId = $sellicoResult['credentials']['campaign_id'] ?? '';
                        $yandexBusinessId = $sellicoResult['credentials']['business_id'] ?? null;
                        $this->info("  Yandex Credentials получены из Sellico API");
                    }
                }
                
                if (!empty($yandexToken) && !empty($yandexCampaignId)) {
                    $yandexService = new \App\Domains\YandexMarket\YandexMarketMarketplace([
                        'token' => $yandexToken,
                        'campaign_id' => $yandexCampaignId,
                        'business_id' => $yandexBusinessId,
                    ]);
                    
                    // Получаем продажи по SKU
                    $yandexSalesData = $yandexService->getSalesBySku();
                    if (!empty($yandexSalesData)) {
                        $this->info("  Yandex Продажи: получено для " . count($yandexSalesData) . " SKU");
                    }
                    
                    // Получаем актуальные цены
                    $yandexPricesData = $yandexService->getProductPrices();
                    if (!empty($yandexPricesData)) {
                        $this->info("  Yandex Цены: получено для " . count($yandexPricesData) . " товаров");
                    }
                    
                    // Ручной % выкупа из интеграции
                    $manualRedemptionRate = $integration?->manual_redemption_rate ?? null;
                    if ($manualRedemptionRate) {
                        $this->info("  Yandex Ручной % выкупа: {$manualRedemptionRate}%");
                    }
                } else {
                    $this->warn("  Нет credentials для Yandex API (token или campaign_id)");
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
            'wildberries' => ['FBO', 'FBS', 'DBS'],
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
                if ($ozonSku && isset($redemptionData[(string)$ozonSku])) {
                    $productRedemption = $redemptionData[(string)$ozonSku];
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
                $actualFulfillmentType = strtoupper($inventory?->fulfillment_type ?? 'FBO');
                
                // Создаём/обновляем записи для ВСЕХ схем работы (для предварительного расчёта)
                foreach ($fulfillmentTypes as $fulfillmentType) {
                    // WB: получаем СПП по nmId товара
                $nmId = $product->wb_data['nmID'] ?? null;
                $productWbSpp = $nmId ? ($wbSppData[$nmId] ?? null) : null;
                
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
                        $productPriceData // Ozon: актуальные цены из API
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
                        'marketplace_data' => array_merge($calculated, [
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
            \App\Jobs\RecalculateUnitEconomicsCacheJob::dispatch($integrationId);
            $this->info("  Запущен пересчёт кэша для integration_id={$integrationId}");
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
        ?array $wbSppData = null,
        ?array $productPriceData = null
    ): array
    {
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
        
        // Продажи: приоритет WB API > inventory
        $salesCount = $wbSalesData['sales_30_days'] ?? $inventory?->sales_30_days ?? 0;
        
        // Хранение: приоритет WB API > inventory
        $storageCost = ($wbStorageData['storage_cost_per_month'] ?? null) ?? $inventory?->storage_cost_per_month ?? 0;

        $data = [
            'sku' => $product->sku,
            'price' => $price,
            'cost_price' => $costPrice,
            'sales_count' => $salesCount,
            'storage_cost' => $storageCost,
            // WB: дополнительные данные о продажах
            'sales_7_days' => $wbSalesData['sales_7_days'] ?? null,
            'sales_14_days' => $wbSalesData['sales_14_days'] ?? null,
            'revenue_30_days' => $wbSalesData['revenue_30_days'] ?? null,
            'avg_daily_sales' => $wbSalesData['avg_daily_sales'] ?? null,
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
                $data['commission_percent'] = $wbData['commission_percent'] ?? $integrationSettings['wb_commission_percent'] ?? 15;
                
                // === КОЭФФИЦИЕНТЫ ИЗ API ТАРИФОВ ===
                // Если есть данные из API тарифов — используем их
                $warehouseCoefficient = 1.0;
                $storageCoefficient = 1.0;
                $deliveryBaseLiter = 46;
                $deliveryAdditionalLiter = 14;
                $storageBaseLiter = 0.08;
                
                if (!empty($wbTariffsData)) {
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
                $data['warehouse_coefficient'] = $wbData['warehouse_coefficient'] ?? $warehouseCoefficient;
                
                // Индекс локализации (ИЛ) — влияет на КТР
                $data['localization_index'] = $wbData['localization_index'] ?? $integrationSettings['localization_index'] ?? 70;
                
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
                if ($wbSppData && isset($wbSppData['spp'])) {
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
                if (isset($wbData['redemption_rate']) && $wbData['redemption_rate'] > 0) {
                    $data['redemption_rate'] = $wbData['redemption_rate'];
                } elseif ($manualRedemptionRate !== null && $manualRedemptionRate > 0) {
                    $data['redemption_rate'] = $manualRedemptionRate;
                } else {
                    $data['redemption_rate'] = 80; // WB обычно ниже выкуп чем Ozon
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
                
                // === ИНДЕКС ЛОКАЛИЗАЦИИ (из API или настроек интеграции) ===
                // Приоритет: API > настройки интеграции > дефолт
                if ($localizationIndex && isset($localizationIndex['average_delivery_time'])) {
                    $data['avg_delivery_time_hours'] = $localizationIndex['average_delivery_time'];
                    $data['localization_index'] = $localizationIndex['tariff_coefficient'];
                    $data['localization_additional_percent'] = $localizationIndex['additional_fee_percent'];
                    $data['localization_status'] = $localizationIndex['tariff_status'];
                } else {
                    $data['avg_delivery_time_hours'] = $integrationSettings['avg_delivery_time_hours'] ?? 29;
                }
                
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
                $data['ozon_compensation'] = $inventory?->ozon_compensation ?? 0;
                
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
                $data['referral_fee_percent'] = 5;
                $data['fby_delivery'] = 50;
                $data['fbs_delivery'] = 40;
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
            
            // Индекс локализации применяется ТОЛЬКО к FBO (склад Ozon)
            $fulfillmentType = $calculated['fulfillment_type'] ?? $data['fulfillment_type'] ?? 'FBO';
            $isFbo = strtoupper($fulfillmentType) === 'FBO';
            
            $detailed = [
                // Комиссия
                'commission_percent' => $calculated['commission_percent'] ?? $data['commission_percent'] ?? null,
                'commission_amount' => $calculated['commission_amount'] ?? null,
                
                // Габариты
                'volume_liters' => $calculated['volume_liters'] ?? $data['volume_liters'] ?? null,
                'volume_weight' => $calculated['volume_weight'] ?? $data['volume_weight'] ?? null,
                'actual_weight' => $calculated['actual_weight'] ?? $data['actual_weight'] ?? null,
                
                // Логистика (декабрь 2025) + индекс локализации из API
                'fulfillment_type' => $fulfillmentType,
                'avg_delivery_time_hours' => $isFbo ? ($data['avg_delivery_time_hours'] ?? 29) : null,
                'localization_index' => $isFbo ? ($data['localization_index'] ?? null) : null,
                'base_logistics_cost' => $calculated['base_logistics_cost'] ?? null,
                'logistics_coefficient' => $isFbo ? ($data['localization_index'] ?? $calculated['logistics_coefficient'] ?? 1.0) : 1.0,
                'additional_commission_percent' => $isFbo ? ($data['localization_additional_percent'] ?? $calculated['additional_commission_percent'] ?? 0) : 0,
                'additional_commission_amount' => $calculated['additional_commission_amount'] ?? null,
                'logistics_with_coefficient' => $calculated['logistics_with_coefficient'] ?? null,
                'logistics_cost' => $calculated['logistics_cost'] ?? null,
                'processing_cost' => $calculated['processing_cost'] ?? null,
                'last_mile_cost' => $calculated['last_mile_cost'] ?? null,
                
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
                'ozon_compensation' => $calculated['ozon_compensation'] ?? $data['ozon_compensation'] ?? null,
                
                // === СТОИМОСТЬ ЗА ЕДИНИЦУ ===
                'logistics_per_unit' => $calculated['base_logistics_cost'] ?? null,
                'last_mile_per_unit' => 25.00,
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
                'localization_index' => $calculated['localization_index'] ?? $data['localization_index'] ?? 70,
                'logistics_coefficient' => $calculated['ktr'] ?? 1.0, // КТР
                'dimensions_coefficient' => $calculated['dimensions_coefficient'] ?? 1.0,
                'logistics_cost' => $calculated['logistics_cost'] ?? null,
                'logistics_with_warehouse' => $calculated['logistics_with_warehouse'] ?? null,
                
                // === СХЕМА РАБОТЫ ===
                'fulfillment_type' => $calculated['fulfillment_type'] ?? $data['fulfillment_type'] ?? 'FBO',
                
                // === % ВЫКУПА 28д ===
                'redemption_rate' => $calculated['redemption_rate'] ?? $data['redemption_rate'] ?? 80,
                
                // === ХРАНЕНИЕ (FBO) ===
                'storage_tariff' => $calculated['storage_tariff'] ?? $data['storage_tariff'] ?? 0.08,
                'storage_coefficient' => $calculated['storage_coefficient'] ?? 1.0,
                'storage_days' => (int) ($calculated['storage_days'] ?? $data['storage_days'] ?? 30),
                'storage_cost' => $calculated['storage_cost'] ?? null,
                'turnover_days' => (int) ($data['turnover_days'] ?? $data['storage_days'] ?? 30),
                
                // === ЭКВАЙРИНГ (WB: 0%) ===
                'acquiring_percent' => $calculated['acquiring_percent'] ?? $data['acquiring_percent'] ?? 0,
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
                
                // === ЛОГИСТИКА ===
                'fby_delivery' => $calculated['fby_delivery'] ?? $data['fby_delivery'] ?? 50,
                'fbs_delivery' => $calculated['fbs_delivery'] ?? $data['fbs_delivery'] ?? 40,
                'logistics_cost' => $calculated['logistics_cost'] ?? null,
                'delivery_cost' => $calculated['delivery_cost'] ?? null,
                
                // === % ВЫКУПА ===
                'redemption_rate' => $calculated['redemption_rate'] ?? $data['redemption_rate'] ?? 95,
                
                // === ХРАНЕНИЕ ===
                'storage_cost' => $calculated['storage_cost'] ?? null,
                'turnover_days' => (int) ($data['turnover_days'] ?? 30),
                
                // === ВОЗВРАТЫ ===
                'return_logistics_cost' => $calculated['return_logistics_cost'] ?? null,
                'expected_return_cost' => $calculated['expected_return_cost'] ?? null,
                'effective_logistics' => $calculated['effective_logistics'] ?? null,
                
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
        if (!is_string($value)) {
            $value = (string) $value;
        }
        
        // Убираем всё кроме цифр, точки и запятой
        $numeric = preg_replace('/[^\d.,]/', '', $value);
        $numeric = str_replace(',', '.', $numeric);
        
        return $numeric !== '' ? (float)$numeric : null;
    }
}
