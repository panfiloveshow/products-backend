<?php

namespace App\Http\Controllers\Api;

use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Http\Controllers\Controller;
use App\Http\Requests\UnitEconomics\CalculateRequest;
use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Jobs\SyncUnitEconomicsJob;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\LocalityMetricDaily;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Models\WildberriesTariffSnapshot;
use App\Services\IntegrationAccessService;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomicsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Контроллер для работы с кэшем юнит-экономики
 *
 * Новая архитектура:
 * - GET возвращает данные из кэша (мгновенно)
 * - PUT обновляет настройки и триггерит пересчёт
 * - POST /recalculate — принудительный пересчёт
 */
class UnitEconomicsCacheController extends Controller
{
    private const EXPORT_TEMPLATE_VERSION = '2026-05-25-04';

    private const EXPORT_TEMPLATE_FORMAT = 'v2';

    private const EXPORT_TEMPLATE_SOURCE = 'UnitEconomicsCacheController::exportExcel';

    private const EXPORT_TEMPLATE_MARKER_CELL = 'AZ1';

    private UnitEconomicsCacheService $cacheService;

    private UnitEconomicsService $unitEconomicsService;

    private UnitEconomicsOrchestrator $orchestrator;

    private IntegrationAccessService $integrationAccessService;

    public function __construct(
        UnitEconomicsCacheService $cacheService,
        UnitEconomicsService $unitEconomicsService,
        UnitEconomicsOrchestrator $orchestrator,
        IntegrationAccessService $integrationAccessService
    ) {
        $this->cacheService = $cacheService;
        $this->unitEconomicsService = $unitEconomicsService;
        $this->orchestrator = $orchestrator;
        $this->integrationAccessService = $integrationAccessService;
    }

    /**
     * Получить юнит-экономику из кэша
     *
     * GET /api/v2/unit-economics/{marketplace}
     */
    public function index(Request $request, string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'fulfillment_type' => 'required|string|in:FBO,FBS,RFBS,EXPRESS,DBS,EDBS,DBW,MIXED,FBY,fbo,fbs,rfbs,express,dbs,edbs,dbw,mixed,fby',
            'search' => 'nullable|string|max:255',
            'profitable' => 'nullable|boolean',
            'quick_filter' => 'nullable|string|in:unprofitable,negative_margin,no_sales_28d,low_confidence,high_non_locality,locality_risk,high_non_local_markup,data_gap',
            'margin_min' => 'nullable|numeric',
            'margin_max' => 'nullable|numeric',
            'profit_min' => 'nullable|numeric',
            'profit_max' => 'nullable|numeric',
            'roi_min' => 'nullable|numeric',
            'roi_max' => 'nullable|numeric',
            'price_min' => 'nullable|numeric',
            'price_max' => 'nullable|numeric',
            'logistics_min' => 'nullable|numeric',
            'logistics_max' => 'nullable|numeric',
            'sales_min' => 'nullable|integer|min:0',
            'sales_max' => 'nullable|integer|min:0',
            'non_local_markup_min' => 'nullable|numeric',
            'non_local_markup_max' => 'nullable|numeric',
            'confidence' => 'nullable|string|in:low,medium,high',
            'locality_state' => 'nullable|string|in:local,non_local,mixed,no_sales',
            'sort' => 'nullable|string|in:sku,product_name,price,net_profit,margin_percent,commission_percent,sales_count,stock,total_stock,current_stock,days_of_stock,relevance',
            'sort_order' => 'nullable|string|in:asc,desc',
            'limit' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ])->validate();

        // Listing должен быть быстрым cache-read endpoint. Не делаем remote refresh
        // интеграции через Sellico API до чтения кэша: если локальной интеграции
        // нет, лучше быстро вернуть ошибку и не блокировать таблицу.
        $resolution = $this->ensureLocalReadableIntegration(
            $request,
            (int) $validated['integration_id'],
            $marketplace
        );
        if (! ($resolution['success'] ?? false)) {
            return response()->json([
                'message' => $resolution['message'] ?? 'Интеграция не найдена',
                'errors' => [
                    'integration_id' => [$resolution['message'] ?? 'Интеграция не найдена'],
                ],
            ], $resolution['status'] ?? 404);
        }

        $query = UnitEconomicsCache::query()
            ->forIntegration($validated['integration_id'])
            ->forMarketplace($marketplace)
            ->forScheme($validated['fulfillment_type'])
            ->search($validated['search'] ?? null)
            ->profitable($validated['profitable'] ?? null)
            ->quickFilter($validated['quick_filter'] ?? null)
            ->marginRange($validated['margin_min'] ?? null, $validated['margin_max'] ?? null)
            ->profitRange($validated['profit_min'] ?? null, $validated['profit_max'] ?? null)
            ->roiRange($validated['roi_min'] ?? null, $validated['roi_max'] ?? null)
            ->priceRange($validated['price_min'] ?? null, $validated['price_max'] ?? null)
            ->effectiveLogisticsRange($validated['logistics_min'] ?? null, $validated['logistics_max'] ?? null)
            ->salesRange($validated['sales_min'] ?? null, $validated['sales_max'] ?? null)
            ->nonLocalMarkupRange($validated['non_local_markup_min'] ?? null, $validated['non_local_markup_max'] ?? null)
            ->confidence($validated['confidence'] ?? null)
            ->localityState($validated['locality_state'] ?? null);
        $statsQuery = clone $query;

        // Сортировка
        $searchQuery = trim((string) ($validated['search'] ?? ''));
        $sortField = $validated['sort'] ?? ($searchQuery !== '' ? 'relevance' : 'sku');
        $sortOrder = $validated['sort_order'] ?? 'asc';
        if ($sortField === 'relevance' && $searchQuery === '') {
            $sortField = 'sku';
        }
        if ($marketplace === 'wildberries' && in_array($sortField, ['stock', 'total_stock', 'current_stock', 'days_of_stock'], true)) {
            $sortField = 'sku';
        }
        $this->applyUnitEconomicsSorting(
            $query,
            $sortField,
            $sortOrder,
            (int) $validated['integration_id'],
            $marketplace,
            $searchQuery
        );

        // Пагинация без paginate(): стандартный paginate() делает COUNT(*) до
        // выборки страницы. Для listing отдаём страницу кэша через limit/offset,
        // а total берём из stats ниже.
        $limit = (int) ($validated['limit'] ?? 50);
        $page = (int) ($validated['page'] ?? 1);
        $pageRows = $query
            ->with('product')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $itemsCollection = collect($pageRows);
        $settingsMap = collect();
        if ($itemsCollection->isNotEmpty()) {
            $settingsMap = UnitEconomicsSettings::where('integration_id', $validated['integration_id'])
                ->whereIn('sku', $itemsCollection->pluck('sku')->unique())
                ->get()
                ->keyBy('sku');
        }

        $pageContext = $this->buildUnitEconomicsPageContext($marketplace, $itemsCollection);

        // Обогащаем данные полями из Product для совместимости с v1
        $items = $itemsCollection->map(function ($cache) use ($validated, $settingsMap, $pageContext) {
            $settings = $settingsMap->get($cache->sku);

            return $this->enrichCacheItem($cache, $validated['fulfillment_type'], $settings, $pageContext);
        })->toArray();

        // Статистика по схемам
        $schemeCounts = $this->getSchemeCounts($validated['integration_id'], $marketplace);

        // Реальная схема работы магазина (для подсветки). На listing не считаем
        // actual_scheme через inventory на cache miss: это отдельная диагностическая
        // задача, а таблице достаточно cached значения или выбранной схемы.
        $actualScheme = Cache::get("ue_actual_scheme_{$validated['integration_id']}_{$marketplace}")
            ?? strtoupper((string) $validated['fulfillment_type']);

        // default_scheme = actual_scheme (схема по умолчанию для выбора таба)
        $defaultScheme = $actualScheme ?? match ($marketplace) {
            'yandex', 'yandex_market' => 'FBY',
            default => 'FBO',
        };

        // Общая статистика. Без фильтров берём лёгкий cached aggregate по интеграции/схеме,
        // чтобы GET страницы не сканировал выборку на каждом открытии.
        $stats = $this->canUseFastStats($validated)
            ? $this->getStats((int) $validated['integration_id'], $marketplace, (string) $validated['fulfillment_type'])
            : $this->getStatsFromQuery($statsQuery);
        $total = (int) ($stats['total_count'] ?? $itemsCollection->count());
        $lastPage = max(1, (int) ceil($total / max(1, $limit)));

        return response()->json([
            'data' => [
                'items' => $items,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $limit,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                ],
                'total' => $total,
                'scheme_counts' => $schemeCounts,
                'actual_scheme' => $actualScheme,
                'default_scheme' => $defaultScheme,
                'stats' => $stats,
            ],
            'stats' => $stats,
        ]);
    }

    private function applyUnitEconomicsSorting(
        \Illuminate\Database\Eloquent\Builder $query,
        string $sortField,
        string $sortOrder,
        ?int $integrationId = null,
        ?string $marketplace = null,
        ?string $searchQuery = null
    ): void {
        $sortOrder = strtolower($sortOrder) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortField, ['stock', 'total_stock', 'current_stock', 'days_of_stock'], true)) {
            $applyInventoryScope = function ($q) use ($integrationId, $marketplace) {
                $q->when($integrationId !== null, fn ($query) => $query->where('inventory_rows.integration_id', $integrationId))
                ->when($marketplace !== null, function ($query) use ($marketplace) {
                    if (in_array($marketplace, ['yandex', 'yandex_market'], true)) {
                        $query->whereIn('inventory_rows.marketplace', ['yandex', 'yandex_market']);
                    } else {
                        $query->where('inventory_rows.marketplace', $marketplace);
                    }
                })
                ->when($marketplace !== null, function ($query) use ($marketplace) {
                    if (in_array($marketplace, ['yandex', 'yandex_market'], true)) {
                        $query->whereIn('inventory_products.marketplace', ['yandex', 'yandex_market']);
                    } else {
                        $query->where('inventory_products.marketplace', $marketplace);
                    }
                });
            };

            $inventoryBySku = DB::table('inventory_warehouses as inventory_rows')
                ->join('products as inventory_products', function ($join) {
                    $join->on('inventory_products.integration_id', '=', 'inventory_rows.integration_id')
                        ->on('inventory_products.sku', '=', 'inventory_rows.sku');
                })
                ->tap($applyInventoryScope)
                ->selectRaw('inventory_products.id as product_id, inventory_rows.quantity, inventory_rows.average_daily_sales');

            $inventoryByBarcode = DB::table('inventory_warehouses as inventory_rows')
                ->join('products as inventory_products', function ($join) {
                    $join->on('inventory_products.integration_id', '=', 'inventory_rows.integration_id')
                        ->on('inventory_products.barcode', '=', 'inventory_rows.sku');
                })
                ->whereNotNull('inventory_products.barcode')
                ->whereColumn('inventory_products.barcode', '!=', 'inventory_products.sku')
                ->tap($applyInventoryScope)
                ->selectRaw('inventory_products.id as product_id, inventory_rows.quantity, inventory_rows.average_daily_sales');

            $inventoryByVendorCode = DB::table('inventory_warehouses as inventory_rows')
                ->join('products as inventory_products', function ($join) {
                    $join->on('inventory_products.integration_id', '=', 'inventory_rows.integration_id')
                        ->on('inventory_products.vendor_code', '=', 'inventory_rows.sku');
                })
                ->whereNotNull('inventory_products.vendor_code')
                ->whereColumn('inventory_products.vendor_code', '!=', 'inventory_products.sku')
                ->where(function ($query) {
                    $query->whereNull('inventory_products.barcode')
                        ->orWhereColumn('inventory_products.vendor_code', '!=', 'inventory_products.barcode');
                })
                ->tap($applyInventoryScope)
                ->selectRaw('inventory_products.id as product_id, inventory_rows.quantity, inventory_rows.average_daily_sales');

            $inventoryUnion = $inventoryBySku
                ->unionAll($inventoryByBarcode)
                ->unionAll($inventoryByVendorCode);

            $inventoryTotals = DB::query()
                ->fromSub($inventoryUnion, 'inventory_matches')
                ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as total_stock, COALESCE(SUM(average_daily_sales), 0) as total_ads')
                ->groupBy('product_id');

            $query->leftJoinSub($inventoryTotals, 'inventory_totals', function ($join) {
                $join->on('inventory_totals.product_id', '=', 'unit_economics_cache.product_id');
            })->select('unit_economics_cache.*');

            if (in_array($sortField, ['stock', 'total_stock', 'current_stock'], true)) {
                $query->orderByRaw('COALESCE(inventory_totals.total_stock, 0) '.$sortOrder)
                    ->orderBy('unit_economics_cache.sku');

                return;
            }

            $query->orderByRaw(
                'CASE WHEN COALESCE(inventory_totals.total_ads, 0) > 0 THEN COALESCE(inventory_totals.total_stock, 0) / inventory_totals.total_ads ELSE -1 END '.$sortOrder
            )->orderBy('unit_economics_cache.sku');

            return;
        }

        if ($sortField === 'relevance') {
            $search = trim((string) $searchQuery);
            if ($search !== '') {
                $driver = $query->getConnection()->getDriverName();
                $likeOperator = $driver === 'pgsql' ? 'ilike' : 'like';
                $query->orderByRaw(
                    'CASE WHEN unit_economics_cache.sku = ? THEN 0 '.
                    'WHEN unit_economics_cache.sku '.$likeOperator.' ? THEN 1 '.
                    'WHEN unit_economics_cache.product_name '.$likeOperator.' ? THEN 2 '.
                    'ELSE 3 END asc',
                    [$search, "{$search}%", "%{$search}%"]
                )->orderBy('unit_economics_cache.sku');

                return;
            }

            $sortField = 'sku';
        }

        $query->orderBy($sortField, $sortOrder);
    }

    /**
     * Быстрая read-only проверка для listing endpoint без внешних HTTP-запросов.
     *
     * @return array{success: bool, status?: int, message?: string, integration?: Integration}
     */
    private function ensureLocalReadableIntegration(Request $request, int $integrationId, string $marketplace): array
    {
        $integration = Integration::find($integrationId);
        if (! $integration) {
            return [
                'success' => false,
                'status' => 404,
                'message' => 'Интеграция не найдена в products-backend cache. Запустите sync интеграции.',
            ];
        }

        $workspaceId = $request->header('X-Sellico-Workspace')
            ?? $request->header('X-Workspace-Id')
            ?? $request->input('workspace');
        if ($workspaceId && (int) $integration->work_space_id !== (int) $workspaceId) {
            return [
                'success' => false,
                'status' => 403,
                'message' => 'Интеграция не принадлежит текущему workspace',
            ];
        }

        $actualMarketplace = $this->normalizeMarketplace((string) $integration->marketplace);
        if ($actualMarketplace !== $marketplace) {
            return [
                'success' => false,
                'status' => 404,
                'message' => 'Интеграция не принадлежит выбранному маркетплейсу',
            ];
        }

        return [
            'success' => true,
            'integration' => $integration,
        ];
    }

    /**
     * Получить один товар
     *
     * GET /api/v2/unit-economics/{marketplace}/{sku}
     */
    public function show(Request $request, string $marketplace, string $sku): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'fulfillment_type' => 'nullable|string|in:FBO,FBS,RFBS,EXPRESS,DBS,EDBS,DBW,MIXED,FBY',
        ])->validate();

        $resolution = $this->integrationAccessService->ensureAccessibleIntegration(
            $request,
            (int) $validated['integration_id'],
            $marketplace
        );
        if (! ($resolution['success'] ?? false)) {
            return response()->json([
                'message' => $resolution['message'] ?? 'Интеграция не найдена',
                'errors' => [
                    'integration_id' => [$resolution['message'] ?? 'Интеграция не найдена'],
                ],
            ], $resolution['status'] ?? 404);
        }

        $fulfillmentType = $validated['fulfillment_type'] ?? match ($marketplace) {
            'yandex', 'yandex_market' => 'FBY',
            default => 'FBO',
        };

        $cache = UnitEconomicsCache::with('product')
            ->where('integration_id', $validated['integration_id'])
            ->where('marketplace', $marketplace)
            ->where('sku', $sku)
            ->where('fulfillment_type', $fulfillmentType)
            ->first();

        if (! $cache) {
            return response()->json([
                'error' => 'Unit economics not found',
                'message' => 'Данные не найдены. Возможно, требуется синхронизация.',
            ], 404);
        }

        // Получаем настройки пользователя
        $settings = UnitEconomicsSettings::where('integration_id', $validated['integration_id'])
            ->where('sku', $sku)
            ->first();

        $pageItems = UnitEconomicsCache::with('product')
            ->where('integration_id', $validated['integration_id'])
            ->when(
                in_array($marketplace, ['yandex', 'yandex_market'], true),
                fn ($query) => $query->whereIn('marketplace', ['yandex', 'yandex_market']),
                fn ($query) => $query->where('marketplace', $marketplace)
            )
            ->where('sku', $sku)
            ->get();
        $pageContext = $this->buildUnitEconomicsPageContext($marketplace, $pageItems);

        $data = $this->enrichCacheItem($cache, $fulfillmentType, $settings, $pageContext);
        if ($marketplace === 'ozon') {
            unset($data['avg_delivery_time_hours'], $data['logistics_coefficient'], $data['additional_commission_percent']);
        }

        $allSchemes = $pageItems
            ->map(function (UnitEconomicsCache $item) use ($marketplace, $settings, $pageContext) {
                $row = $this->enrichCacheItem($item, (string) $item->fulfillment_type, $settings, $pageContext);
                if ($marketplace === 'ozon') {
                    unset($row['avg_delivery_time_hours'], $row['logistics_coefficient'], $row['additional_commission_percent']);
                }

                return $row;
            })
            ->keyBy('fulfillment_type');

        return response()->json([
            'data' => $data,
            'settings' => $settings,
            'all_schemes' => $allSchemes,
        ]);
    }

    /**
     * Обновить настройки пользователя
     *
     * PUT /api/v2/unit-economics/settings/{sku}
     */
    public function updateSettings(Request $request, string $sku): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'cost_price' => 'nullable|numeric|min:0',
            'drr_percent' => 'nullable|numeric|min:0|max:100',
            'our_share_percent' => 'nullable|numeric|min:0|max:100',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'vat_percent' => 'nullable|numeric|min:0|max:100',
            'redemption_rate_override' => 'nullable|numeric|min:0|max:100',
            // WB-специфичные
            'spp_percent' => 'nullable|numeric|min:0|max:100',
            // ИЛ (индекс локализации) — хранится на уровне интеграции, но принимаем здесь для удобства
            'localization_index' => 'nullable|numeric|min:0.50|max:2.50',
            // ИРП (индекс распределения продаж) — хранится на уровне WB-интеграции, значение из ЛК WB в процентах
            'sales_distribution_index' => 'nullable|numeric|min:0|max:2.50',
            // Габариты НЕ редактируемые — берутся из API маркетплейса
        ])->validate();

        $resolution = $this->integrationAccessService->ensureAccessibleIntegration(
            $request,
            (int) $validated['integration_id']
        );
        if (! ($resolution['success'] ?? false)) {
            return response()->json([
                'message' => $resolution['message'] ?? 'Интеграция не найдена',
                'errors' => [
                    'integration_id' => [$resolution['message'] ?? 'Интеграция не найдена'],
                ],
            ], $resolution['status'] ?? 404);
        }

        $integrationId = $validated['integration_id'];
        $localizationIndex = $validated['localization_index'] ?? null;
        $salesDistributionIndex = $validated['sales_distribution_index'] ?? null;
        unset($validated['integration_id'], $validated['localization_index'], $validated['sales_distribution_index']);

        // ИЛ и ИРП — настройки WB-интеграции, а не отдельного SKU.
        if ($localizationIndex !== null || $salesDistributionIndex !== null) {
            $integration = Integration::find($integrationId);
            if ($integration) {
                $settings = is_array($integration->settings) ? $integration->settings : [];
                $update = [
                    'settings' => $settings,
                ];

                if ($localizationIndex !== null) {
                    $settings['wb_localization_index'] = (float) $localizationIndex;
                    $update['localization_index'] = $localizationIndex;
                }

                if ($salesDistributionIndex !== null) {
                    $settings['wb_sales_distribution_index'] = (float) $salesDistributionIndex;
                }

                $update['settings'] = $settings;
                $integration->update($update);
            }
        }

        // Обновляем или создаём настройки товара
        $settings = UnitEconomicsSettings::updateOrCreate(
            ['integration_id' => $integrationId, 'sku' => $sku],
            array_filter($validated, fn ($v) => $v !== null)
        );

        // ИЛ/ИРП — настройки всей WB-интеграции, поэтому пересчитываем всю интеграцию.
        // Обычные SKU-настройки пересчитывают только выбранный SKU по всем схемам.
        if ($localizationIndex !== null || $salesDistributionIndex !== null) {
            $this->cacheService->onIntegrationSettingsChanged($integrationId);
        } else {
            $this->cacheService->onSettingsChanged($integrationId, $sku);
        }

        // Возвращаем обновлённые данные
        $cache = UnitEconomicsCache::where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->get()
            ->keyBy('fulfillment_type');

        // Получаем актуальный localization_index из интеграции
        $integration = Integration::find($integrationId);

        return response()->json([
            'message' => 'Settings updated; cache recalculation queued',
            'settings' => $settings,
            'localization_index' => (float) ($integration->localization_index ?? 1.0),
            'sales_distribution_index' => (float) (($integration->settings['wb_sales_distribution_index'] ?? null) ?? 0.0),
            'cache' => $cache,
        ]);
    }

    /**
     * Массовое обновление настроек
     *
     * PUT /api/v2/unit-economics/settings/bulk
     */
    public function bulkUpdateSettings(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'items' => 'required|array|min:1|max:100',
            'items.*.sku' => 'required|string',
            'items.*.cost_price' => 'nullable|numeric|min:0',
            'items.*.drr_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.our_share_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.vat_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.redemption_rate_override' => 'nullable|numeric|min:0|max:100',
            // Габариты (мм и г)
            'items.*.length_mm' => 'nullable|numeric|min:0',
            'items.*.width_mm' => 'nullable|numeric|min:0',
            'items.*.height_mm' => 'nullable|numeric|min:0',
            'items.*.weight_g' => 'nullable|numeric|min:0',
            // WB-специфичные
            'items.*.spp_percent' => 'nullable|numeric|min:0|max:100',
        ])->validate();

        $resolution = $this->integrationAccessService->ensureAccessibleIntegration(
            $request,
            (int) $validated['integration_id']
        );
        if (! ($resolution['success'] ?? false)) {
            return response()->json([
                'message' => $resolution['message'] ?? 'Интеграция не найдена',
                'errors' => [
                    'integration_id' => [$resolution['message'] ?? 'Интеграция не найдена'],
                ],
            ], $resolution['status'] ?? 404);
        }

        $integrationId = $validated['integration_id'];
        $skus = [];

        foreach ($validated['items'] as $item) {
            $sku = $item['sku'];
            unset($item['sku']);

            UnitEconomicsSettings::updateOrCreate(
                ['integration_id' => $integrationId, 'sku' => $sku],
                array_filter($item, fn ($v) => $v !== null)
            );

            $skus[] = $sku;
        }

        // Триггерим асинхронный пересчёт кэша для всех изменённых товаров
        $this->cacheService->onBulkSettingsChanged($integrationId, $skus);

        return response()->json([
            'message' => 'Bulk settings updated; cache recalculation queued',
            'updated_count' => count($skus),
        ]);
    }

    /**
     * Импорт ИЛ/ИРП Wildberries из Excel/CSV выгрузки ЛК WB.
     *
     * Поддерживает два формата:
     * - файл, где есть строки "Индекс локализации" / "Индекс распределения продаж";
     * - детализацию по артикулам с колонками "Количество заказов", "КТР" и/или "КРП".
     */
    public function importWildberriesIndexes(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:20480',
        ])->validate();

        $resolution = $this->integrationAccessService->ensureAccessibleIntegration(
            $request,
            (int) $validated['integration_id']
        );
        if (! ($resolution['success'] ?? false)) {
            return response()->json([
                'message' => $resolution['message'] ?? 'Интеграция не найдена',
                'errors' => [
                    'integration_id' => [$resolution['message'] ?? 'Интеграция не найдена'],
                ],
            ], $resolution['status'] ?? 404);
        }

        $integration = Integration::find((int) $validated['integration_id']);
        if (! $integration || $integration->marketplace !== 'wildberries') {
            return response()->json([
                'message' => 'Импорт ИЛ/ИРП доступен только для Wildberries интеграций',
            ], 422);
        }

        try {
            $parsed = $this->parseWildberriesIndexesSpreadsheet($request->file('file'));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Не удалось прочитать Excel/CSV файл',
                'details' => $e->getMessage(),
            ], 422);
        }

        $localizationIndex = $parsed['localization_index'];
        $salesDistributionIndex = $parsed['sales_distribution_index'];
        if ($localizationIndex === null && $salesDistributionIndex === null) {
            return response()->json([
                'message' => 'В файле не найдены ИЛ или ИРП',
                'details' => 'Загрузите Excel из ЛК WB с виджетами ИЛ/ИРП или детализацию с колонками "Количество заказов", "КТР", "КРП".',
                'warnings' => $parsed['warnings'],
            ], 422);
        }

        $settings = is_array($integration->settings) ? $integration->settings : [];
        if ($localizationIndex !== null) {
            $settings['wb_localization_index'] = $localizationIndex;
        }
        if ($salesDistributionIndex !== null) {
            $settings['wb_sales_distribution_index'] = $salesDistributionIndex;
        }
        $settings['wb_indexes_source'] = 'excel_import';
        $settings['wb_indexes_imported_at'] = now()->toIso8601String();

        $update = ['settings' => $settings];
        if ($localizationIndex !== null) {
            $update['localization_index'] = $localizationIndex;
        }
        $integration->update($update);

        $this->cacheService->onIntegrationSettingsChanged((int) $integration->id);

        return response()->json([
            'message' => 'Индексы WB импортированы; пересчёт кэша поставлен в очередь',
            'localization_index' => $localizationIndex,
            'sales_distribution_index' => $salesDistributionIndex,
            'source' => $parsed['source'],
            'rows_processed' => $parsed['rows_processed'],
            'warnings' => $parsed['warnings'],
        ]);
    }

    private function parseWildberriesIndexesSpreadsheet(\Illuminate\Http\UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $rows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            foreach ($sheet->toArray(null, true, true, true) as $row) {
                $values = array_values($row);
                if ($this->spreadsheetRowIsEmpty($values)) {
                    continue;
                }
                $rows[] = $values;
            }
        }

        $result = [
            'localization_index' => null,
            'sales_distribution_index' => null,
            'source' => 'unknown',
            'rows_processed' => count($rows),
            'warnings' => [],
        ];

        $this->extractWildberriesIndexesFromLabels($rows, $result);
        $this->extractWildberriesIndexesFromDetailTable($rows, $result);

        if ($result['localization_index'] !== null) {
            $result['localization_index'] = round((float) $result['localization_index'], 2);
        }
        if ($result['sales_distribution_index'] !== null) {
            $result['sales_distribution_index'] = round((float) $result['sales_distribution_index'], 4);
        }

        return $result;
    }

    private function extractWildberriesIndexesFromLabels(array $rows, array &$result): void
    {
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $cellIndex => $cell) {
                $label = $this->normalizeWildberriesIndexText($cell);
                if ($label === '') {
                    continue;
                }

                if ($result['localization_index'] === null && $this->isWildberriesLocalizationLabel($label)) {
                    $value = $this->findSpreadsheetNumericNear($rows, $rowIndex, $cellIndex, 'localization');
                    if ($value !== null) {
                        $result['localization_index'] = $value;
                        $result['source'] = 'excel_label';
                    }
                }

                if ($result['sales_distribution_index'] === null && $this->isWildberriesSalesDistributionLabel($label)) {
                    $value = $this->findSpreadsheetNumericNear($rows, $rowIndex, $cellIndex, 'percent');
                    if ($value !== null) {
                        $result['sales_distribution_index'] = $value;
                        $result['source'] = 'excel_label';
                    }
                }
            }
        }
    }

    private function extractWildberriesIndexesFromDetailTable(array $rows, array &$result): void
    {
        if ($result['localization_index'] !== null && $result['sales_distribution_index'] !== null) {
            return;
        }

        foreach ($rows as $headerIndex => $row) {
            $headers = array_map(fn ($value) => $this->normalizeWildberriesIndexText($value), $row);
            $ordersColumn = $this->findWildberriesIndexColumn($headers, [
                'количествозаказ',
                'колвозак',
                'заказовшт',
                'заказышт',
                'всегозаказ',
                'общееколичествозаказ',
                'заказы',
            ]);
            $ktrColumn = $this->findWildberriesIndexColumn($headers, [
                'ктр',
                'коэффициенттерриториальногораспределения',
                'коэффтерриториальногораспределения',
                'коэфтерриториальногораспределения',
                'коэффициенттерритраспределения',
                'коэффтерритраспределения',
                'коэфтерритраспределения',
                'ктерриториальногораспределения',
            ]);
            $krpColumn = $this->findWildberriesIndexColumn($headers, [
                'крп',
                'коэффициентраспределенияпродаж',
                'коэффраспределенияпродаж',
                'коэфраспределенияпродаж',
                'коэффициентраспрпродаж',
                'коэффраспрпродаж',
                'коэфраспрпродаж',
                'краспределенияпродаж',
            ]);

            if ($ordersColumn === null || ($ktrColumn === null && $krpColumn === null)) {
                continue;
            }

            $totalOrdersForKtr = 0.0;
            $weightedKtr = 0.0;
            $totalOrdersForKrp = 0.0;
            $weightedKrp = 0.0;

            for ($i = $headerIndex + 1; $i < count($rows); $i++) {
                $orders = $this->parseSpreadsheetNumber($rows[$i][$ordersColumn] ?? null, 'plain');
                if ($orders === null || $orders <= 0) {
                    continue;
                }

                if ($ktrColumn !== null) {
                    $ktr = $this->parseSpreadsheetNumber($rows[$i][$ktrColumn] ?? null, 'localization');
                    if ($ktr !== null && $ktr >= 0.5 && $ktr <= 2.5) {
                        $weightedKtr += $orders * $ktr;
                        $totalOrdersForKtr += $orders;
                    }
                }

                if ($krpColumn !== null) {
                    $krp = $this->parseSpreadsheetNumber($rows[$i][$krpColumn] ?? null, 'percent');
                    if ($krp !== null && $krp >= 0 && $krp <= 2.5) {
                        $weightedKrp += $orders * $krp;
                        $totalOrdersForKrp += $orders;
                    }
                }
            }

            if ($result['localization_index'] === null && $totalOrdersForKtr > 0) {
                $result['localization_index'] = $weightedKtr / $totalOrdersForKtr;
                $result['source'] = 'excel_detail_weighted';
            }

            if ($result['sales_distribution_index'] === null && $totalOrdersForKrp > 0) {
                $result['sales_distribution_index'] = $weightedKrp / $totalOrdersForKrp;
                $result['source'] = 'excel_detail_weighted';
            }

            return;
        }

        $result['warnings'][] = 'Не нашёл таблицу детализации с колонками "Количество заказов", "КТР" или "КРП".';
    }

    private function spreadsheetRowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeWildberriesIndexText(mixed $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        $text = str_replace('ё', 'е', $text);

        return preg_replace('/[^a-zа-я0-9%]+/u', '', $text) ?: '';
    }

    private function isWildberriesLocalizationLabel(string $label): bool
    {
        return str_contains($label, 'индекслокализации')
            || $label === 'ил'
            || str_starts_with($label, 'ил%')
            || str_starts_with($label, 'илкоэффициент')
            || str_contains($label, 'текущийил')
            || str_contains($label, 'значениеил')
            || str_contains($label, 'илпродавца');
    }

    private function isWildberriesSalesDistributionLabel(string $label): bool
    {
        return str_contains($label, 'индексраспределенияпродаж')
            || $label === 'ирп'
            || str_starts_with($label, 'ирп%')
            || str_contains($label, 'текущийирп')
            || str_contains($label, 'значениеирп')
            || str_contains($label, 'ирппродавца');
    }

    private function findSpreadsheetNumericNear(array $rows, int $rowIndex, int $cellIndex, string $mode): ?float
    {
        $sameRow = $rows[$rowIndex] ?? [];
        for ($i = $cellIndex + 1; $i < min(count($sameRow), $cellIndex + 6); $i++) {
            $value = $this->parseSpreadsheetNumber($sameRow[$i] ?? null, $mode);
            if ($value !== null) {
                return $value;
            }
        }

        $nextRow = $rows[$rowIndex + 1] ?? [];
        for ($i = max(0, $cellIndex - 1); $i < min(count($nextRow), $cellIndex + 4); $i++) {
            $value = $this->parseSpreadsheetNumber($nextRow[$i] ?? null, $mode);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function findWildberriesIndexColumn(array $headers, array $needles): ?int
    {
        foreach ($headers as $index => $header) {
            foreach ($needles as $needle) {
                if ($header !== '' && str_contains($header, $needle)) {
                    return (int) $index;
                }
            }
        }

        return null;
    }

    private function parseSpreadsheetNumber(mixed $value, string $mode): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            $hasPercentSign = false;
        } else {
            $text = trim((string) $value);
            $hasPercentSign = str_contains($text, '%');
            $text = str_replace(["\xc2\xa0", ' ', '%'], '', $text);
            $text = str_replace(',', '.', $text);
            $text = preg_replace('/[^0-9.\-]/', '', $text) ?: '';
            if ($text === '' || ! is_numeric($text)) {
                return null;
            }
            $number = (float) $text;
        }

        if ($mode === 'localization') {
            if ($hasPercentSign || $number > 2.5) {
                $number = $number / 100;
            }

            return $number >= 0.5 && $number <= 2.5 ? $number : null;
        }

        if ($mode === 'percent') {
            if (! $hasPercentSign && $number > 0 && $number <= 0.05) {
                $number *= 100;
            }

            return $number >= 0 && $number <= 2.5 ? $number : null;
        }

        return $number;
    }

    /**
     * Принудительный пересчёт кэша для интеграции
     *
     * POST /api/v2/unit-economics/recalculate/{integrationId}
     */
    public function recalculate(int $integrationId): JsonResponse
    {
        if (! Integration::whereKey($integrationId)->exists()) {
            return response()->json([
                'message' => 'Integration not found',
            ], 404);
        }

        RecalculateUnitEconomicsCacheJob::dispatch($integrationId)
            ->onQueue('unit-economics');

        return response()->json([
            'message' => 'Пересчёт поставлен в очередь',
            'data' => [
                'integration_id' => $integrationId,
                'status' => 'queued',
            ],
        ], 202);
    }

    /**
     * Получить статистику кэша
     *
     * GET /api/v2/unit-economics/cache-stats/{integrationId}
     */
    public function cacheStats(int $integrationId): JsonResponse
    {
        $stats = $this->cacheService->getCacheStats($integrationId);

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Экспорт юнит-экономики в Excel (XLSX)
     *
     * GET /api/unit-economics/{marketplace}/export/excel
     */
    public function exportExcel(Request $request, string $marketplace): StreamedResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'fulfillment_type' => 'required|string|in:FBO,FBS,RFBS,EXPRESS,DBS,EDBS,DBW,MIXED,FBY,fbo,fbs,rfbs,express,dbs,edbs,dbw,mixed,fby',
            // Те же фильтры, что принимает index() — чтобы выгрузка матчила
            // ровно то, что менеджер видит на странице, без листания пагинации.
            'search' => 'nullable|string|max:255',
            'profitable' => 'nullable|boolean',
            'quick_filter' => 'nullable|string|in:unprofitable,negative_margin,no_sales_28d,low_confidence,high_non_locality,locality_risk,high_non_local_markup,data_gap',
            'margin_min' => 'nullable|numeric',
            'margin_max' => 'nullable|numeric',
            'profit_min' => 'nullable|numeric',
            'profit_max' => 'nullable|numeric',
            'roi_min' => 'nullable|numeric',
            'roi_max' => 'nullable|numeric',
            'price_min' => 'nullable|numeric',
            'price_max' => 'nullable|numeric',
            'logistics_min' => 'nullable|numeric',
            'logistics_max' => 'nullable|numeric',
            'sales_min' => 'nullable|integer|min:0',
            'sales_max' => 'nullable|integer|min:0',
            'non_local_markup_min' => 'nullable|numeric',
            'non_local_markup_max' => 'nullable|numeric',
            'confidence' => 'nullable|string|in:low,medium,high',
            'locality_state' => 'nullable|string|in:local,non_local,mixed,no_sales',
            'period' => 'nullable|integer|in:7,28',
            'period_days' => 'nullable|integer|in:7,28',
            'as_of' => 'nullable|date',
        ])->validate();

        $resolution = $this->integrationAccessService->ensureAccessibleIntegration(
            $request,
            (int) $validated['integration_id'],
            $marketplace
        );
        if (! ($resolution['success'] ?? false)) {
            abort($resolution['status'] ?? 404, $resolution['message'] ?? 'Интеграция не найдена');
        }

        $fulfillmentType = $validated['fulfillment_type'];
        $integrationId = (int) $validated['integration_id'];
        $ozonLocalityPeriodDays = (int) ($validated['period_days'] ?? $validated['period'] ?? 28);
        $ozonLocalitySnapshotDate = $marketplace === 'ozon'
            ? (
                isset($validated['as_of'])
                    ? LocalityMetricDaily::query()
                        ->where('integration_id', $integrationId)
                        ->where('period_days', $ozonLocalityPeriodDays)
                        ->whereDate('snapshot_date', $validated['as_of'])
                        ->value('snapshot_date')
                    : LocalityMetricDaily::query()
                        ->where('integration_id', $integrationId)
                        ->where('period_days', $ozonLocalityPeriodDays)
                        ->max('snapshot_date')
            )
            : null;

        // Те же фильтры, что в index — выгружаем «то что видит менеджер»
        // (без пагинации: Excel должен содержать ВСЕ отфильтрованные строки).
        // Важно: не грузим весь Eloquent-набор сразу. На больших интеграциях это
        // легко превращалось в 30s+ и высокий memory pressure.
        $exportQuery = UnitEconomicsCache::query()
            ->forIntegration($integrationId)
            ->forMarketplace($marketplace)
            ->forScheme($fulfillmentType)
            ->search($validated['search'] ?? null)
            ->profitable($validated['profitable'] ?? null)
            ->marginRange($validated['margin_min'] ?? null, $validated['margin_max'] ?? null)
            ->quickFilter($validated['quick_filter'] ?? null)
            ->profitRange($validated['profit_min'] ?? null, $validated['profit_max'] ?? null)
            ->roiRange($validated['roi_min'] ?? null, $validated['roi_max'] ?? null)
            ->priceRange($validated['price_min'] ?? null, $validated['price_max'] ?? null)
            ->effectiveLogisticsRange($validated['logistics_min'] ?? null, $validated['logistics_max'] ?? null)
            ->salesRange($validated['sales_min'] ?? null, $validated['sales_max'] ?? null)
            ->nonLocalMarkupRange($validated['non_local_markup_min'] ?? null, $validated['non_local_markup_max'] ?? null)
            ->confidence($validated['confidence'] ?? null)
            ->localityState($validated['locality_state'] ?? null)
            ->with('product')
            ->orderBy('sku')
            ->orderBy('id');

        $enrichedItems = [];
        $exportQuery->chunk(500, function (Collection $items) use (
            &$enrichedItems,
            $integrationId,
            $marketplace,
            $fulfillmentType,
            $ozonLocalitySnapshotDate,
            $ozonLocalityPeriodDays
        ) {
            $settingsMap = UnitEconomicsSettings::where('integration_id', $integrationId)
                ->whereIn('sku', $items->pluck('sku')->unique()->values()->all())
                ->get()
                ->keyBy('sku');

            $localityBySku = collect();
            if ($marketplace === 'ozon' && $ozonLocalitySnapshotDate) {
                $localityBySku = LocalityMetricDaily::query()
                    ->where('integration_id', $integrationId)
                    ->where('period_days', $ozonLocalityPeriodDays)
                    ->where('snapshot_date', $ozonLocalitySnapshotDate)
                    ->whereIn('sku', $items->pluck('sku')->unique()->values()->all())
                    ->get([
                        'sku',
                        'period_days',
                        'snapshot_date',
                        'orders_count',
                        'revenue_total',
                        'local_share_percent',
                        'avg_markup_percent',
                        'calculation_confidence',
                    ])
                    ->keyBy('sku');
            }

            $pageContext = $this->buildUnitEconomicsPageContext($marketplace, $items);

            // Обогащаем данные. Финансовый пересчёт под фронт-формулу делается
            // позже в buildUnitEconomicsSpreadsheet, чтобы Excel показывал ровно
            // те же net_profit/margin/to_settlement, что менеджер видит в UI.
            foreach ($items as $cache) {
                $settings = $settingsMap->get($cache->sku);
                /** @var LocalityMetricDaily|null $localityRow */
                $localityRow = $localityBySku->get($cache->sku);

                $enriched = $this->enrichCacheItem($cache, $fulfillmentType, $settings, $pageContext);
                if ($marketplace === 'ozon') {
                    $enriched = $this->applyOzonLocalityMetricsToExportItem(
                        $enriched,
                        $localityRow,
                        $ozonLocalitySnapshotDate !== null,
                        $ozonLocalityPeriodDays,
                        $ozonLocalitySnapshotDate
                    );
                }

                $enrichedItems[] = $enriched;
            }
        });

        // Получаем имя интеграции
        $integration = Integration::find($integrationId);
        $integrationName = $integration?->name ?? "ID {$integrationId}";

        // Генерируем Excel
        $spreadsheet = $this->buildUnitEconomicsSpreadsheet(
            $enrichedItems,
            $integrationName,
            $marketplace,
            $fulfillmentType
        );

        $date = now()->format('Y-m-d');
        $time = now()->format('His');
        $filename = "unit-economics-{$marketplace}-{$fulfillmentType}-{$date}-{$time}.xlsx";

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, $this->buildExportHeaders($filename));
    }

    /**
     * Генерация Spreadsheet для юнит-экономики
     */
    private function buildUnitEconomicsSpreadsheet(
        array $items,
        string $integrationName,
        string $marketplace,
        string $fulfillmentType
    ): Spreadsheet {
        // Финансовые поля считаем по той же формуле, что фронт
        // (UnitEconomicsPage.tsx mapOzonItemsToRows): амуны процентов = % × price,
        // toSettlement = price - все_аммы - effectiveLogistics, profit = toSettlement - costPrice.
        // Иначе Excel показывает cache.net_profit (со своими корректировками типа
        // marketplace_compensation), а UI — локально пересчитанное значение,
        // и менеджеры видят разные цифры по одному и тому же SKU.
        $items = $this->recalculateFinanceFieldsForExport($items, $marketplace);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Юнит-экономика');

        // ── Определения колонок ──
        $columns = [
            'A'  => ['header' => 'Артикул',             'width' => 15, 'field' => 'sku',                      'format' => '@'],
            'B'  => ['header' => 'Наименование',        'width' => 40, 'field' => 'product_name',             'format' => '@'],
            'C'  => ['header' => 'Цена, ₽',             'width' => 12, 'field' => 'price',                    'format' => '#,##0.00 "₽"'],
            'D'  => ['header' => 'Себестоимость, ₽',    'width' => 14, 'field' => 'cost_price',               'format' => '#,##0.00 "₽"'],
            'E'  => ['header' => 'Наценка, x',          'width' => 11, 'field' => 'markup_percent',           'format' => '0.00'],
            'F'  => ['header' => 'Продажи, шт',         'width' => 11, 'field' => 'sales_count',              'format' => '#,##0'],
            'G'  => ['header' => 'Выручка, ₽',          'width' => 13, 'field' => 'revenue',                  'format' => '#,##0.00 "₽"'],
            'H'  => ['header' => 'Оборач-ть, дн',       'width' => 12, 'field' => 'turnover_days',            'format' => '0'],
            'I'  => ['header' => 'Комиссия, %',         'width' => 11, 'field' => 'commission_percent',       'format' => '0.00"%"'],
            'J'  => ['header' => 'Комиссия, ₽',         'width' => 12, 'field' => 'commission_amount',        'format' => '#,##0.00 "₽"'],
            'K'  => ['header' => 'Логистика, ₽',        'width' => 12, 'field' => 'logistics_cost',           'format' => '#,##0.00 "₽"'],
            'L'  => ['header' => 'Посл. миля, ₽',       'width' => 12, 'field' => 'last_mile_cost',           'format' => '#,##0.00 "₽"'],
            'M'  => ['header' => 'Эфф. логистика, ₽',   'width' => 15, 'field' => 'effective_logistics',      'format' => '#,##0.00 "₽"'],
            'N'  => ['header' => 'Возвраты, ₽',         'width' => 12, 'field' => 'expected_return_cost',     'format' => '#,##0.00 "₽"'],
            'O'  => ['header' => 'Хранение, ₽',         'width' => 12, 'field' => 'storage_cost',             'format' => '#,##0.00 "₽"'],
            'P'  => ['header' => 'Эквайринг, %',        'width' => 12, 'field' => 'acquiring_percent',        'format' => '0.00"%"'],
            'Q'  => ['header' => 'РК, %',               'width' => 8,  'field' => 'drr_percent',              'format' => '0.00"%"'],
            'R'  => ['header' => 'Наша часть, %',       'width' => 12, 'field' => 'our_share_percent',        'format' => '0.00"%"'],
            'S'  => ['header' => 'Налог, %',            'width' => 9,  'field' => 'tax_percent',              'format' => '0.00"%"'],
            'T'  => ['header' => 'НДС, %',              'width' => 8,  'field' => 'vat_percent',              'format' => '0.00"%"'],
            'U'  => ['header' => 'Локальность',         'width' => 14, 'field' => 'locality',                 'format' => '@'],
            'V'  => ['header' => 'Локальность, %',      'width' => 13, 'field' => 'expected_locality_rate',   'format' => '0.00"%"'],
            'W'  => ['header' => 'Нелок. наценка экран, %', 'width' => 18, 'field' => 'non_local_markup_percent', 'format' => '0.00"%"'],
            'X'  => ['header' => 'Причина наценки',     'width' => 28, 'field' => 'markup_rule_reason_label', 'format' => '@'],
            'Y'  => ['header' => '% выкупа',            'width' => 10, 'field' => 'redemption_rate',          'format' => '0.00"%"'],
            'Z'  => ['header' => 'Статус данных',       'width' => 13, 'field' => 'calculation_confidence',   'format' => '@'],
            'AA' => ['header' => 'Итого затраты, ₽',    'width' => 14, 'field' => 'total_costs',              'format' => '#,##0.00 "₽"'],
            'AB' => ['header' => 'Прибыль',             'width' => 12, 'field' => 'net_profit',               'format' => '#,##0.00 "₽"'],
            'AC' => ['header' => 'Маржа, %',            'width' => 10, 'field' => 'margin_percent',           'format' => '0.00"%"'],
            'AD' => ['header' => 'ROI, %',              'width' => 10, 'field' => 'roi_percent',              'format' => '0.00"%"'],
            'AE' => ['header' => 'На р/с, ₽',           'width' => 12, 'field' => 'to_settlement_account',    'format' => '#,##0.00 "₽"'],
            'AF' => ['header' => 'Индекс цены',          'width' => 12, 'field' => 'current_price_index',      'format' => '0.00'],
            'AG' => ['header' => 'КС, %',                'width' => 10, 'field' => 'warehouse_coef_percent',   'format' => '0.00"%"'],
            'AH' => ['header' => 'КС, ₽',                'width' => 10, 'field' => 'warehouse_coef_amount',    'format' => '#,##0.00 "₽"'],
            'AI' => ['header' => 'ИЛ',                   'width' => 9,  'field' => 'localization_index',       'format' => '0.00'],
            'AJ' => ['header' => 'ИЛ, ₽',                'width' => 10, 'field' => 'localization_amount',      'format' => '#,##0.00 "₽"'],
            'AK' => ['header' => 'ИРП, %',               'width' => 10, 'field' => 'sales_distribution_index', 'format' => '0.00"%"'],
            'AL' => ['header' => 'ИРП, ₽',               'width' => 10, 'field' => 'sales_distribution_amount','format' => '#,##0.00 "₽"'],
            'AM' => ['header' => 'СПП, %',                'width' => 10, 'field' => 'spp_percent',              'format' => '0.00"%"'],
            'AN' => ['header' => 'СПП, ₽',                'width' => 10, 'field' => 'spp_amount',               'format' => '#,##0.00 "₽"'],
            'AO' => ['header' => 'Нелок. наценка, ₽',     'width' => 15, 'field' => 'non_local_markup_amount',  'format' => '#,##0.00 "₽"'],
            'AP' => ['header' => 'Нелок. ожид., %',       'width' => 15, 'field' => 'weighted_non_local_markup_percent', 'format' => '0.00"%"'],
            'AQ' => ['header' => 'Источник наценки',      'width' => 20, 'field' => 'non_local_markup_source',  'format' => '@'],
        ];
        $isWildberriesExport = $marketplace === 'wildberries';
        if (! $isWildberriesExport) {
            // Ozon export: не показываем WB-специфичные столбцы.
            foreach (['AG', 'AH', 'AI', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AO', 'AP', 'AQ'] as $wbColumn) {
                unset($columns[$wbColumn]);
            }
        }
        $lastCol = array_key_last($columns) ?: 'AF';

        // ── Ширины колонок ──
        foreach ($columns as $col => $def) {
            $sheet->getColumnDimension($col)->setWidth($def['width']);
        }

        // ── Группировка колонок (outline) — можно сворачивать/разворачивать в Excel ──
        $collapsibleGroups = [
            ['I', 'J'],   // Комиссия (% + ₽)
            ['P', 'T'],   // Проценты прочих сборов
            ['V', 'X'],   // Деталь локальности и наценки
        ];
        foreach ($collapsibleGroups as [$from, $to]) {
            $fromIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($from);
            $toIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($to);
            for ($i = $fromIndex; $i <= $toIndex; $i++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
                $sheet->getColumnDimension($colLetter)->setOutlineLevel(1);
                $sheet->getColumnDimension($colLetter)->setCollapsed(false);
            }
        }
        $sheet->setShowSummaryRight(false);

        // ── Logo ──
        $logoPath = storage_path('app/sellico-logo.png');
        if (file_exists($logoPath)) {
            $drawing = new Drawing();
            $drawing->setName('Sellico');
            $drawing->setDescription('Sellico Logo');
            $drawing->setPath($logoPath);
            $drawing->setHeight(36);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(5);
            $drawing->setOffsetY(5);
            $drawing->setWorksheet($sheet);
        }

        // ── Row 1: Заголовок "SELLICO — Юнит-экономика" (B1, A1 занята логотипом) ──
        $sheet->setCellValue('B1', 'SELLICO — Юнит-экономика');
        $sheet->mergeCells("B1:{$lastCol}1");
        $sheet->getStyle('B1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 18,
                'color' => ['rgb' => '16a34a'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(45);

        // ── Row 2: Мета-информация ──
        $fulfillmentLabel = strtoupper($fulfillmentType);
        $date = now()->format('d.m.Y');
        $sheet->setCellValue('B2', "Магазин: {$integrationName} | Маркетплейс: {$marketplace} | Схема: {$fulfillmentLabel} | Дата: {$date}");
        $sheet->mergeCells("B2:{$lastCol}2");
        $sheet->getStyle('B2')->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '6b7280'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // ── Row 3: Пустой разделитель ──
        $sheet->getRowDimension(3)->setRowHeight(8);

        // ── Row 4: Заголовки таблицы ──
        $headerRow = 4;
        foreach ($columns as $col => $def) {
            $sheet->setCellValue("{$col}{$headerRow}", $def['header']);
        }
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '16a34a'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        // Автофильтр
        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$headerRow}");

        // ── Row 5+: Данные ──
        $dataStartRow = 5;
        $currentRow = $dataStartRow;

        // Текстовые колонки (формат '@')
        $textColumns = array_values(array_intersect(['A', 'B', 'U', 'X', 'Z', 'AQ'], array_keys($columns)));
        // Колонки, которые должны отображаться пустыми при null (а не как 0)
        $nullableNumericColumns = array_values(array_intersect(['V', 'AF'], array_keys($columns)));

        foreach ($items as $item) {
            $isEvenRow = ($currentRow - $dataStartRow) % 2 === 1;
            $isLowConfidence = strtolower((string) ($item['calculation_confidence'] ?? '')) === 'low';
            $isInPromotion = (bool) ($item['is_in_promotion'] ?? false);

            foreach ($columns as $col => $def) {
                $field = $def['field'];

                if ($col === 'A') {
                    $sheet->setCellValue("A{$currentRow}", $this->resolveDisplayArticle($item));
                } elseif ($col === 'E') {
                    // E: Наценка — Excel-формула = Цена / Себестоимость
                    $sheet->setCellValue("E{$currentRow}", "=IF(D{$currentRow}>0,C{$currentRow}/D{$currentRow},0)");
                } elseif ($col === 'G') {
                    if (! empty($item['export_revenue_is_period_snapshot'])) {
                        // G: фактическая выручка за выбранный период из locality snapshot.
                        // Её нельзя пересчитывать как Цена × Продажи: цена могла измениться,
                        // а в snapshot уже лежит реальная сумма заказов периода.
                        $sheet->setCellValue("G{$currentRow}", (float) ($item['revenue'] ?? 0));
                    } else {
                        // G: Выручка — меняется при ручной правке цены или продаж
                        $sheet->setCellValue("G{$currentRow}", "=C{$currentRow}*F{$currentRow}");
                    }
                } elseif ($col === 'J') {
                    // J: Комиссия ₽ — зависит от цены и комиссии %
                    $sheet->setCellValue("J{$currentRow}", "=C{$currentRow}*I{$currentRow}/100");
                } elseif ($col === 'M') {
                    // M: Эффективная логистика из API/UI. В Ozon она уже включает возвраты
                    // и нелокальную наценку, поэтому N/W остаются детализацией, а не плюсуются повторно.
                    $sheet->setCellValue("M{$currentRow}", (float) ($item['effective_logistics'] ?? 0));
                } elseif ($col === 'AA') {
                    // AA: Итого затраты — та же модель, что на экране. Для WB отдельно
                    // учитываем СПП и хранение; для Ozon хранение/возвраты не дублируем,
                    // потому что экранная формула использует effective_logistics.
                    $sheet->setCellValue("AA{$currentRow}", $isWildberriesExport
                        ? "=D{$currentRow}+J{$currentRow}+M{$currentRow}+O{$currentRow}+AN{$currentRow}"
                            . "+(C{$currentRow}*P{$currentRow}/100)+(C{$currentRow}*Q{$currentRow}/100)"
                            . "+(C{$currentRow}*S{$currentRow}/100)"
                        : "=D{$currentRow}+J{$currentRow}+M{$currentRow}"
                            . "+(C{$currentRow}*P{$currentRow}/100)+(C{$currentRow}*Q{$currentRow}/100)"
                            . "+(C{$currentRow}*R{$currentRow}/100)+(C{$currentRow}*S{$currentRow}/100)"
                            . "+(C{$currentRow}*T{$currentRow}/100)"
                    );
                } elseif ($col === 'AB') {
                    // AB: Прибыль — ключевая формула, меняется от цены/расходов/себестоимости.
                    $sheet->setCellValue("AB{$currentRow}", "=AE{$currentRow}-D{$currentRow}");
                } elseif ($col === 'AC') {
                    // AC: Маржа — Excel-формула =IF(C>0, AB/C*100, 0)
                    $sheet->setCellValue("AC{$currentRow}", "=IF(C{$currentRow}>0,AB{$currentRow}/C{$currentRow}*100,0)");
                } elseif ($col === 'AD') {
                    // AD: ROI — Excel-формула =IF(D>0, AB/D*100, 0)
                    $sheet->setCellValue("AD{$currentRow}", "=IF(D{$currentRow}>0,AB{$currentRow}/D{$currentRow}*100,0)");
                } elseif ($col === 'AE') {
                    // AE: На р/с — цена минус маркетплейс/процентные расходы без себестоимости.
                    $sheet->setCellValue("AE{$currentRow}", $isWildberriesExport
                        ? "=C{$currentRow}-J{$currentRow}-M{$currentRow}-O{$currentRow}-AN{$currentRow}"
                            . "-(C{$currentRow}*P{$currentRow}/100)-(C{$currentRow}*Q{$currentRow}/100)"
                            . "-(C{$currentRow}*S{$currentRow}/100)"
                        : "=C{$currentRow}-J{$currentRow}-M{$currentRow}"
                            . "-(C{$currentRow}*P{$currentRow}/100)-(C{$currentRow}*Q{$currentRow}/100)"
                            . "-(C{$currentRow}*R{$currentRow}/100)-(C{$currentRow}*S{$currentRow}/100)"
                            . "-(C{$currentRow}*T{$currentRow}/100)"
                    );
                } elseif ($col === 'U') {
                    // U: Локальность (лейбл)
                    $sheet->setCellValue("U{$currentRow}", $this->resolveLocalityLabel($item));
                } elseif ($col === 'Z') {
                    // Z: пользовательский статус данных без пугающих low/medium формулировок.
                    $sheet->setCellValue("Z{$currentRow}", $this->resolveConfidenceLabel($item['calculation_confidence'] ?? null));
                } elseif (in_array($col, $nullableNumericColumns, true)) {
                    // Числовые nullable — пустая ячейка при null
                    $value = $item[$field] ?? null;
                    $sheet->setCellValue("{$col}{$currentRow}", $value === null ? '' : (float) $value);
                } elseif (in_array($col, $textColumns, true)) {
                    // Текстовые поля
                    $sheet->setCellValue("{$col}{$currentRow}", $item[$field] ?? '');
                } else {
                    // Числовые поля
                    $sheet->setCellValue("{$col}{$currentRow}", (float) ($item[$field] ?? 0));
                }
            }

            // Приоритет заливки: low-confidence > акция > чередование
            if ($isLowConfidence) {
                // Светло-оранжевый фон — данные неточные, требует внимания
                $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'fff7ed'],
                    ],
                ]);
            } elseif ($isInPromotion) {
                // Светло-голубой — товар в акции
                $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'eff6ff'],
                    ],
                ]);
            } elseif ($isEvenRow) {
                // Чередование: белый / светло-зелёный
                $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'f0fdf4'],
                    ],
                ]);
            }

            // Маркер акции: левый синий бордер на колонке C (Цена)
            if ($isInPromotion) {
                $sheet->getStyle("C{$currentRow}")->applyFromArray([
                    'borders' => [
                        'left' => [
                            'borderStyle' => Border::BORDER_THICK,
                            'color' => ['rgb' => '2563eb'],
                        ],
                    ],
                ]);
            }

            // Маркер просроченной фиксации тарифа: оранжевая рамка вокруг артикула
            $fixedUntil = $item['fixed_until'] ?? null;
            $fixationApplied = (bool) ($item['fixation_applied'] ?? false);
            if ($fixationApplied && $fixedUntil) {
                try {
                    $expiresAt = \Carbon\Carbon::parse($fixedUntil);
                    if ($expiresAt->isPast()) {
                        $sheet->getStyle("A{$currentRow}")->applyFromArray([
                            'font' => ['color' => ['rgb' => 'c2410c'], 'bold' => true],
                            'borders' => [
                                'outline' => [
                                    'borderStyle' => Border::BORDER_MEDIUM,
                                    'color' => ['rgb' => 'f97316'],
                                ],
                            ],
                        ]);
                    }
                } catch (\Throwable $e) {
                    // некорректный формат даты — игнорируем
                }
            }

            // Красный цвет для отрицательной прибыли (AB)
            $netProfit = (float) ($item['net_profit'] ?? 0);
            if ($netProfit < 0) {
                $sheet->getStyle("AB{$currentRow}")->applyFromArray([
                    'font' => ['color' => ['rgb' => 'dc2626']],
                ]);
            }

            // Красный цвет для отрицательной маржи (AC)
            $marginPercent = (float) ($item['margin_percent'] ?? 0);
            if ($marginPercent < 0) {
                $sheet->getStyle("AC{$currentRow}")->applyFromArray([
                    'font' => ['color' => ['rgb' => 'dc2626']],
                ]);
            }

            $currentRow++;
        }

        $dataEndRow = $currentRow - 1;

        // ── Форматы ячеек (по определению колонок) ──
        if ($dataEndRow >= $dataStartRow) {
            foreach ($columns as $col => $def) {
                $sheet->getStyle("{$col}{$dataStartRow}:{$col}{$dataEndRow}")
                    ->getNumberFormat()
                    ->setFormatCode($def['format']);
            }
        }

        // ── Строка ИТОГО ──
        $summaryRow = $currentRow;
        $sheet->setCellValue("A{$summaryRow}", 'ИТОГО');

        // SUM формулы для денежных колонок и количеств
        $sumCols = array_values(array_intersect(
            ['C', 'F', 'G', 'J', 'K', 'L', 'M', 'N', 'O', 'AA', 'AB', 'AE', 'AH', 'AJ', 'AN', 'AO'],
            array_keys($columns)
        ));
        foreach ($sumCols as $col) {
            if ($dataEndRow >= $dataStartRow) {
                $sheet->setCellValue("{$col}{$summaryRow}", "=SUM({$col}{$dataStartRow}:{$col}{$dataEndRow})");
            } else {
                $sheet->setCellValue("{$col}{$summaryRow}", 0);
            }
        }

        // AVERAGE формулы для процентных/относительных колонок
        $avgCols = array_values(array_intersect(
            ['E', 'H', 'I', 'P', 'Q', 'R', 'S', 'T', 'V', 'W', 'Y', 'AC', 'AD', 'AF', 'AG', 'AI', 'AK', 'AM', 'AP'],
            array_keys($columns)
        ));
        foreach ($avgCols as $col) {
            if ($dataEndRow >= $dataStartRow) {
                $sheet->setCellValue("{$col}{$summaryRow}", "=AVERAGE({$col}{$dataStartRow}:{$col}{$dataEndRow})");
            } else {
                $sheet->setCellValue("{$col}{$summaryRow}", 0);
            }
        }

        // Стиль строки ИТОГО
        $sheet->getStyle("A{$summaryRow}:{$lastCol}{$summaryRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'dcfce7'],
            ],
        ]);

        // Форматы для строки ИТОГО (по определению колонок)
        foreach ($columns as $col => $def) {
            $sheet->getStyle("{$col}{$summaryRow}")
                ->getNumberFormat()
                ->setFormatCode($def['format']);
        }

        // ── Границы для всей таблицы (row 4 до ИТОГО) ──
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$summaryRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'd1d5db'],
                ],
            ],
        ]);

        // ── Заморозка панелей: строка 4 (заголовки) + колонки A:B (SKU + Название) ──
        $sheet->freezePane('C5');

        // ── Дополнительные листы ──
        $this->buildSummarySheet($spreadsheet, $items, $integrationName, $marketplace, $fulfillmentType);
        $this->buildClustersSheet($spreadsheet, $items);
        $this->buildMetadataSheet($spreadsheet, $items, $integrationName, $marketplace, $fulfillmentType);

        // Активный лист при открытии — основной
        $spreadsheet->setActiveSheetIndex(0);

        $this->writeExportTemplateMarker($spreadsheet);

        return $spreadsheet;
    }

    /**
     * Build mandatory export headers so clients can safely detect legacy responses.
     *
     * @return array<string, string>
     */
    private function buildExportHeaders(string $filename): array
    {
        return [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Unit-Economics-Export-Version' => self::EXPORT_TEMPLATE_VERSION,
            'X-Unit-Economics-Export-Format' => self::EXPORT_TEMPLATE_FORMAT,
            'X-Unit-Economics-Export-Source' => self::EXPORT_TEMPLATE_SOURCE,
            // For cross-origin frontends we must expose custom headers explicitly.
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Unit-Economics-Export-Version, X-Unit-Economics-Export-Format, X-Unit-Economics-Export-Source',
        ];
    }

    /**
     * Write a hidden machine-readable export marker to the main worksheet.
     */
    private function writeExportTemplateMarker(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setCellValue(self::EXPORT_TEMPLATE_MARKER_CELL, self::EXPORT_TEMPLATE_VERSION);
        $sheet->getColumnDimension('AZ')->setVisible(false);
    }

    /**
     * Пересчёт финансовых полей под фронт-формулы.
     *
     * Ozon считает проценты как % × price и вычитает effective_logistics один раз.
     * WB дополнительно вычитает СПП и хранение, как WBProductsTable при ручной правке.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function recalculateFinanceFieldsForExport(array $items, string $marketplace): array
    {
        $isWildberriesExport = $marketplace === 'wildberries';

        foreach ($items as &$item) {
            $price = (float) ($item['price'] ?? 0);
            $costPrice = (float) ($item['cost_price'] ?? 0);
            $commissionPercent = (float) ($item['commission_percent'] ?? 0);
            $acquiringPercent = (float) ($item['acquiring_percent'] ?? 0);
            $taxPercent = (float) ($item['tax_percent'] ?? 0);
            $vatPercent = (float) ($item['vat_percent'] ?? 0);
            $drrPercent = (float) ($item['drr_percent'] ?? 0);
            $ourSharePercent = (float) ($item['our_share_percent'] ?? 0);
            $effectiveLogistics = (float) ($item['effective_logistics'] ?? 0);
            $storageCost = $isWildberriesExport ? (float) ($item['storage_cost'] ?? 0) : 0.0;
            $sppPercent = (float) ($item['spp_percent'] ?? 0);

            $commissionAmount = $price * $commissionPercent / 100;
            $acquiringAmount = $price * $acquiringPercent / 100;
            $taxAmount = $price * $taxPercent / 100;
            $vatAmount = $isWildberriesExport ? 0.0 : $price * $vatPercent / 100;
            $drrAmount = $price * $drrPercent / 100;
            $ourShareAmount = $isWildberriesExport ? 0.0 : $price * $ourSharePercent / 100;
            $sppAmount = $isWildberriesExport ? $price * $sppPercent / 100 : 0.0;

            $toSettlement = $price - $commissionAmount - $effectiveLogistics
                - $acquiringAmount - $taxAmount - $vatAmount
                - $ourShareAmount - $drrAmount - $storageCost - $sppAmount;
            $netProfit = $toSettlement - $costPrice;
            $totalCosts = $costPrice + $commissionAmount + $effectiveLogistics
                + $acquiringAmount + $taxAmount + $vatAmount
                + $ourShareAmount + $drrAmount + $storageCost + $sppAmount;

            $item['commission_amount'] = round($commissionAmount, 2);
            $item['acquiring_amount'] = round($acquiringAmount, 2);
            $item['tax_amount'] = round($taxAmount, 2);
            $item['vat_amount'] = round($vatAmount, 2);
            $item['drr_amount'] = round($drrAmount, 2);
            $item['our_share_amount'] = round($ourShareAmount, 2);
            $item['spp_amount'] = round($sppAmount, 2);
            $item['total_costs'] = round($totalCosts, 2);
            $item['to_settlement_account'] = round($toSettlement, 2);
            $item['net_profit'] = round($netProfit, 2);
            $item['margin_percent'] = $price > 0 ? round(($netProfit / $price) * 100, 2) : 0.0;
            $item['roi_percent'] = $costPrice > 0 ? round(($netProfit / $costPrice) * 100, 2) : 0.0;
        }
        unset($item);

        return $items;
    }

    /**
     * For Ozon the visible non-local markup must be factual when order API data exists.
     *
     * @return array{0: float, 1: float, 2: bool}
     */
    private function resolveOzonDisplayNonLocalMarkup(
        array $orderEconomicsSummary,
        float $expectedMarkupPercent,
        float $expectedMarkupAmount
    ): array {
        $ordersCount = (int) ($orderEconomicsSummary['orders_count'] ?? 0);
        if ($ordersCount <= 0 || ! array_key_exists('avg_non_local_markup_percent', $orderEconomicsSummary)) {
            return [
                round($expectedMarkupPercent, 2),
                round($expectedMarkupAmount, 2),
                false,
            ];
        }

        $factualPercent = round((float) $orderEconomicsSummary['avg_non_local_markup_percent'], 2);
        $factualAmount = array_key_exists('avg_non_local_markup_amount', $orderEconomicsSummary)
            ? round((float) $orderEconomicsSummary['avg_non_local_markup_amount'], 2)
            : round($expectedMarkupAmount, 2);

        return [$factualPercent, $factualAmount, true];
    }

    /**
     * Лист «Сводка» — KPI-блок + топ прибыльных/убыточных
     */
    private function buildSummarySheet(
        Spreadsheet $spreadsheet,
        array $items,
        string $integrationName,
        string $marketplace,
        string $fulfillmentType
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Сводка');

        // Агрегаты
        $totalCount = count($items);
        $profitable = 0;
        $unprofitable = 0;
        $totalRevenue = 0.0;
        $totalProfit = 0.0;
        $marginSum = 0.0;
        $marginCount = 0;
        $lowConfidence = 0;
        $inPromotion = 0;
        $nonLocal = 0;
        $noMarkupData = 0;

        foreach ($items as $item) {
            $profit = (float) ($item['net_profit'] ?? 0);
            if ($profit > 0) {
                $profitable++;
            } else {
                $unprofitable++;
            }
            $totalRevenue += (float) ($item['revenue'] ?? 0);
            $totalProfit += $profit;
            if (isset($item['margin_percent']) && $item['margin_percent'] !== null) {
                $marginSum += (float) $item['margin_percent'];
                $marginCount++;
            }
            if (strtolower((string) ($item['calculation_confidence'] ?? '')) === 'low') {
                $lowConfidence++;
            }
            if (! empty($item['is_in_promotion'])) {
                $inPromotion++;
            }
            if (($item['is_local_sale'] ?? null) === false) {
                $nonLocal++;
            }
            if (($item['markup_rule_reason'] ?? null) === 'no_markup_for_cluster') {
                $noMarkupData++;
            }
        }
        $avgMargin = $marginCount > 0 ? round($marginSum / $marginCount, 2) : 0;

        // Заголовок
        $sheet->setCellValue('A1', 'SELLICO — Сводка по юнит-экономике');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '16a34a']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->setCellValue('A2', "Магазин: {$integrationName} | Маркетплейс: {$marketplace} | Схема: " . strtoupper($fulfillmentType) . ' | Дата: ' . now()->format('d.m.Y'));
        $sheet->mergeCells('A2:D2');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '6b7280']],
        ]);

        // KPI-блок
        $kpis = [
            ['Всего товаров',        $totalCount,                                           '#,##0'],
            ['Прибыльных',           $profitable,                                           '#,##0'],
            ['Убыточных',            $unprofitable,                                         '#,##0'],
            ['Общая выручка, ₽',     round($totalRevenue, 2),                               '#,##0.00 "₽"'],
            ['Общая прибыль, ₽',     round($totalProfit, 2),                                '#,##0.00 "₽"'],
            ['Средняя маржа, %',     $avgMargin,                                            '0.00"%"'],
            ['Low-confidence, шт',   $lowConfidence,                                        '#,##0'],
            ['В акции, шт',          $inPromotion,                                          '#,##0'],
            ['Не локальных, шт',     $nonLocal,                                             '#,##0'],
            ['Без данных кластера',  $noMarkupData,                                         '#,##0'],
        ];

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(3);
        $sheet->getColumnDimension('D')->setWidth(40);

        $row = 4;
        foreach ($kpis as [$label, $value, $format]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($format);
            $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
                'font' => ['size' => 11],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'd1d5db']]],
            ]);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'f0fdf4']],
            ]);
            $row++;
        }

        // Топ-5 прибыльных и убыточных
        $sorted = collect($items)->sortByDesc(fn ($i) => (float) ($i['net_profit'] ?? 0))->values();
        $topProfitable = $sorted->take(5);
        $topLosing = $sorted->reverse()->take(5);

        $topRow = 4;
        $sheet->setCellValue("D{$topRow}", 'ТОП-5 прибыльных SKU');
        $sheet->mergeCells("D{$topRow}:D" . ($topRow));
        $sheet->getStyle("D{$topRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '16a34a']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $topRow++;
        foreach ($topProfitable as $item) {
            $sku = $this->resolveDisplayArticle($item);
            $profit = (float) ($item['net_profit'] ?? 0);
            $sheet->setCellValue("D{$topRow}", $sku . ' — ' . number_format($profit, 2, '.', ' ') . ' ₽');
            $sheet->getStyle("D{$topRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'd1d5db']]],
            ]);
            $topRow++;
        }

        $topRow++;
        $sheet->setCellValue("D{$topRow}", 'ТОП-5 убыточных SKU');
        $sheet->getStyle("D{$topRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'dc2626']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $topRow++;
        foreach ($topLosing as $item) {
            $sku = $this->resolveDisplayArticle($item);
            $profit = (float) ($item['net_profit'] ?? 0);
            $sheet->setCellValue("D{$topRow}", $sku . ' — ' . number_format($profit, 2, '.', ' ') . ' ₽');
            $sheet->getStyle("D{$topRow}")->applyFromArray([
                'font' => ['color' => ['rgb' => 'dc2626']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'd1d5db']]],
            ]);
            $topRow++;
        }
    }

    /**
     * Лист «Кластеры» — разбивка clusters_summary по SKU (откуда приходят заказы и с какой наценкой)
     */
    private function buildClustersSheet(Spreadsheet $spreadsheet, array $items): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Кластеры');

        $headers = [
            'A' => ['header' => 'Артикул',           'width' => 15],
            'B' => ['header' => 'Наименование',      'width' => 40],
            'C' => ['header' => 'Заказы, %',         'width' => 11],
            'D' => ['header' => 'Локальный',         'width' => 11],
            'E' => ['header' => 'Эфф. наценка, %',   'width' => 15],
            'F' => ['header' => 'Логистика, ₽',      'width' => 13],
        ];
        foreach ($headers as $col => $def) {
            $sheet->getColumnDimension($col)->setWidth($def['width']);
            $sheet->setCellValue("{$col}1", $def['header']);
        }
        $sheet->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '16a34a']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->setAutoFilter('A1:F1');

        $row = 2;
        foreach ($items as $item) {
            $clusters = $item['clusters_summary'] ?? [];
            if (! is_array($clusters) || $clusters === []) {
                continue;
            }
            foreach ($clusters as $cluster) {
                if (! is_array($cluster)) {
                    continue;
                }
                $sheet->setCellValue("A{$row}", $this->resolveDisplayArticle($item));
                $sheet->setCellValue("B{$row}", $item['product_name'] ?? '');
                $sheet->setCellValue("C{$row}", (float) ($cluster['orders_percent'] ?? 0));
                $sheet->setCellValue("D{$row}", ! empty($cluster['is_local_cluster']) ? 'Да' : 'Нет');
                $sheet->setCellValue("E{$row}", (float) ($cluster['effective_markup_percent'] ?? 0));
                $sheet->setCellValue("F{$row}", (float) ($cluster['logistics_cost'] ?? $cluster['logistics_amount'] ?? 0));
                $row++;
            }
        }

        $lastDataRow = $row - 1;
        if ($lastDataRow >= 2) {
            $sheet->getStyle("C2:C{$lastDataRow}")->getNumberFormat()->setFormatCode('0.00"%"');
            $sheet->getStyle("E2:E{$lastDataRow}")->getNumberFormat()->setFormatCode('0.00"%"');
            $sheet->getStyle("F2:F{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00 "₽"');
            $sheet->getStyle("A2:F{$lastDataRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'd1d5db']]],
            ]);
            $sheet->freezePane('A2');
        } else {
            $sheet->setCellValue('A2', 'Нет данных о кластерах (нет активной фиксации или нет заказов по кластерам за период)');
            $sheet->mergeCells('A2:F2');
            $sheet->getStyle('A2')->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '6b7280']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
    }

    /**
     * Лист «Метаданные» — параметры расчёта, версии тарифов, источники данных
     */
    private function buildMetadataSheet(
        Spreadsheet $spreadsheet,
        array $items,
        string $integrationName,
        string $marketplace,
        string $fulfillmentType
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Метаданные');

        // Собираем версии тарифов и источники
        $tariffVersions = [];
        $tariffSources = [];
        $profileSources = [];
        $fixationCount = 0;
        $apiSourceCount = 0;
        $fallbackCount = 0;
        foreach ($items as $item) {
            if (! empty($item['tariff_version'])) {
                $tariffVersions[(string) $item['tariff_version']] = true;
            }
            if (! empty($item['tariff_source'])) {
                $tariffSources[(string) $item['tariff_source']] = true;
            }
            $ps = (string) ($item['profile_source'] ?? '');
            if ($ps !== '') {
                $profileSources[$ps] = ($profileSources[$ps] ?? 0) + 1;
            }
            if (! empty($item['fixation_applied'])) {
                $fixationCount++;
            }
            if ($ps === 'api') {
                $apiSourceCount++;
            } elseif ($ps !== '') {
                $fallbackCount++;
            }
        }

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(60);

        $sheet->setCellValue('A1', 'Параметры экспорта');
        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '16a34a']],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $rows = [
            ['Магазин',              $integrationName],
            ['Маркетплейс',          $marketplace],
            ['Схема',                strtoupper($fulfillmentType)],
            ['Дата генерации',       now()->format('d.m.Y H:i')],
            ['Export template version', self::EXPORT_TEMPLATE_VERSION],
            ['Export format',        self::EXPORT_TEMPLATE_FORMAT],
            ['Export source',        self::EXPORT_TEMPLATE_SOURCE],
            ['Количество SKU',       count($items)],
            ['Версии тарифов',       implode(', ', array_keys($tariffVersions)) ?: '—'],
            ['Источники тарифов',    implode(', ', array_keys($tariffSources)) ?: '—'],
            ['SKU с фиксацией',      $fixationCount],
            ['Источник: API',        $apiSourceCount],
            ['Источник: fallback',   $fallbackCount],
            ['Источник данных спроса', 'Ozon Delivery Analytics API'],
            ['Источник остатков',      'Ozon Stocks API'],
            ['Источник продаж',        'Ozon postings / sales by warehouse'],
        ];

        $row = 3;
        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", (string) $value);
            $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'd1d5db']]],
            ]);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'f0fdf4']],
            ]);
            $row++;
        }
    }

    /**
     * Определение лейбла локальности для Excel-экспорта
     */
    private function resolveLocalityLabel(array $item): string
    {
        $isLocal = $item['is_local_sale'] ?? null;
        if ($isLocal === true) {
            return 'Локальная';
        }

        if ($isLocal === false) {
            return 'Нелокальная';
        }

        $localityRate = $item['expected_locality_rate'] ?? null;
        if ($localityRate !== null) {
            $localityRate = (float) $localityRate;
            if ($localityRate >= 99.99) {
                return 'Локальная';
            }
            if ($localityRate <= 0.01) {
                return 'Нелокальная';
            }

            return 'Оценка ' . round((float) $localityRate) . '%';
        }

        return '';
    }

    private function resolveDisplayArticle(array $item): string
    {
        foreach (['article', 'vendor_code', 'sku'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Для Ozon-экспорта подмешиваем per-SKU locality-метрики из snapshot'а
     * выбранного периода: продажи, выручку, local_share_percent и avg_markup_percent.
     */
    private function applyOzonLocalityMetricsToExportItem(
        array $item,
        ?LocalityMetricDaily $localityRow,
        bool $hasLocalitySnapshot = false,
        int $periodDays = 28,
        mixed $snapshotDate = null
    ): array
    {
        if (! $localityRow && ! $hasLocalitySnapshot) {
            return $item;
        }

        $ordersCount = $localityRow ? (int) ($localityRow->orders_count ?? 0) : 0;
        $revenueTotal = $localityRow ? (float) ($localityRow->revenue_total ?? 0) : 0.0;

        $item['sales_count'] = $ordersCount;
        $item['orders_count'] = $ordersCount;
        $item['revenue'] = round($revenueTotal, 2);
        $item['export_revenue_is_period_snapshot'] = true;
        $item['export_sales_period_days'] = (int) ($localityRow->period_days ?? $periodDays);
        $item['export_sales_snapshot_date'] = $localityRow
            ? optional($localityRow->snapshot_date)->toDateString()
            : ($snapshotDate instanceof \DateTimeInterface
                ? $snapshotDate->format('Y-m-d')
                : ($snapshotDate ? (string) $snapshotDate : null));
        $item['export_sales_source'] = 'locality_metrics_daily';

        if (! $localityRow) {
            return $item;
        }

        $localityRate = $localityRow->local_share_percent !== null
            ? round((float) $localityRow->local_share_percent, 2)
            : ($item['expected_locality_rate'] ?? null);
        $markupPercent = $localityRow->avg_markup_percent !== null
            ? round((float) $localityRow->avg_markup_percent, 2)
            : ($item['non_local_markup_percent'] ?? 0.0);

        $item['expected_locality_rate'] = $localityRate;
        $item['raw_non_local_markup_percent'] = $markupPercent;
        $item['non_local_markup_percent'] = $markupPercent;
        $item['weighted_non_local_markup_percent'] = $markupPercent;
        $item['non_local_markup_source'] = 'locality_metrics_daily';

        if ($localityRate !== null) {
            if ($localityRate >= 99.99) {
                $item['is_local_sale'] = true;
            } elseif ($localityRate <= 0.01) {
                $item['is_local_sale'] = false;
            } else {
                // Для смешанной локальности не форсируем legacy boolean-метку.
                $item['is_local_sale'] = null;
            }
        }

        $item['calculation_confidence'] = (string) ($localityRow->calculation_confidence ?: ($item['calculation_confidence'] ?? 'medium'));

        return $item;
    }

    /**
     * Человекочитаемый статус данных для Excel.
     */
    private function resolveConfidenceLabel(?string $confidence): string
    {
        return match (strtolower((string) $confidence)) {
            'high', 'medium' => 'Проверено',
            'low'            => 'Проверить',
            default  => '',
        };
    }

    /**
     * Получить количество товаров по схемам (кэшируется на 60 сек)
     */
    private function getSchemeCounts(int $integrationId, string $marketplace): array
    {
        $cacheKey = "ue_scheme_counts_{$integrationId}_{$marketplace}";

        return Cache::remember($cacheKey, 60, function () use ($integrationId, $marketplace) {
            $counts = UnitEconomicsCache::where('integration_id', $integrationId)
                ->where('marketplace', $marketplace)
                ->selectRaw('fulfillment_type, COUNT(*) as count')
                ->groupBy('fulfillment_type')
                ->pluck('count', 'fulfillment_type')
                ->toArray();

            $schemes = match ($marketplace) {
                'ozon' => ['FBO', 'FBS', 'RFBS', 'EXPRESS'],
                'wildberries' => ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'],
                'yandex', 'yandex_market' => ['FBY', 'FBS', 'DBS', 'EXPRESS'],
                default => ['FBO', 'FBS'],
            };

            $result = [];
            foreach ($schemes as $scheme) {
                $result[$scheme] = $counts[$scheme] ?? 0;
            }

            return $result;
        });
    }

    /**
     * Получить реальную схему работы магазина (кэшируется на 5 мин)
     */
    private function getActualScheme(int $integrationId, string $marketplace): ?string
    {
        $cacheKey = "ue_actual_scheme_{$integrationId}_{$marketplace}";

        return Cache::remember($cacheKey, 300, function () use ($integrationId, $marketplace) {
            // Остатки — самый свежий источник фактической схемы. unit_economics может
            // быть stale после смены склада/схемы и тогда уводит экран в FBO.
            $inventoryScheme = $this->resolveActualSchemeFromInventory($integrationId, $marketplace);
            if ($inventoryScheme) {
                return $inventoryScheme;
            }

            // Затем пробуем из unit_economics.
            $actualScheme = UnitEconomics::where('integration_id', $integrationId)
                ->where('marketplace', $marketplace)
                ->where('is_actual_scheme', true)
                ->selectRaw('fulfillment_type, COUNT(*) as count')
                ->groupBy('fulfillment_type')
                ->orderByDesc('count')
                ->value('fulfillment_type');

            if ($actualScheme) {
                return $actualScheme;
            }

            // Fallback на Product.fulfillment_type
            return Product::where('integration_id', $integrationId)
                ->where('marketplace', $marketplace)
                ->whereNotNull('fulfillment_type')
                ->where('fulfillment_type', '!=', '')
                ->selectRaw('fulfillment_type, COUNT(*) as count')
                ->groupBy('fulfillment_type')
                ->orderByDesc('count')
                ->value('fulfillment_type');
        });
    }

    private function resolveActualSchemeFromInventory(int $integrationId, string $marketplace, ?string $sku = null): ?string
    {
        if (! Schema::hasTable('inventory_warehouses')) {
            return null;
        }

        $cacheKey = 'ue_inventory_actual_scheme_'
            .$integrationId.'_'
            .$marketplace.'_'
            .($sku !== null ? md5($sku) : 'all');

        return Cache::remember($cacheKey, 300, function () use ($integrationId, $marketplace, $sku) {
            $query = InventoryWarehouse::query()
                ->where('integration_id', $integrationId)
                ->where('marketplace', $marketplace)
                ->whereNotNull('fulfillment_type')
                ->where('fulfillment_type', '!=', '');

            if ($sku !== null && trim($sku) !== '') {
                $query->where('sku', trim($sku));
            }

            $rows = $query
                ->selectRaw('UPPER(fulfillment_type) as fulfillment_type, SUM(quantity) as total_quantity, COUNT(*) as rows_count')
                ->groupByRaw('UPPER(fulfillment_type)')
                ->get()
                ->map(function ($row) {
                    $scheme = $this->normalizeFulfillmentScheme($row->fulfillment_type);

                    return [
                        'scheme' => $scheme,
                        'total_quantity' => (float) ($row->total_quantity ?? 0),
                        'rows_count' => (int) ($row->rows_count ?? 0),
                    ];
                })
                ->filter(fn (array $row): bool => $row['scheme'] !== null)
                ->values();

            if ($rows->isEmpty()) {
                return null;
            }

            $withStock = $rows->filter(fn (array $row): bool => $row['total_quantity'] > 0);
            $winner = ($withStock->isNotEmpty() ? $withStock : $rows)
                ->sortByDesc('rows_count')
                ->sortByDesc('total_quantity')
                ->first();

            return $winner['scheme'] ?? null;
        });
    }

    private function normalizeFulfillmentScheme(mixed $value): ?string
    {
        $scheme = strtoupper(trim((string) $value));

        if ($scheme === '') {
            return null;
        }

        return match (true) {
            str_contains($scheme, 'REALFBS'), str_contains($scheme, 'RFBS') => 'RFBS',
            str_contains($scheme, 'EXPRESS') => 'EXPRESS',
            str_contains($scheme, 'FBS') => 'FBS',
            str_contains($scheme, 'FBO') => 'FBO',
            default => $scheme,
        };
    }

    /**
     * Получить статистику по схеме (оптимизировано: 1 запрос вместо 6)
     */
    private function getStats(int $integrationId, string $marketplace, string $fulfillmentType): array
    {
        $cacheKey = "ue_stats_{$integrationId}_{$marketplace}_".strtoupper($fulfillmentType);

        return Cache::remember($cacheKey, 60, function () use ($integrationId, $marketplace, $fulfillmentType) {
            $stats = UnitEconomicsCache::where('integration_id', $integrationId)
                ->where('marketplace', $marketplace)
                ->where('fulfillment_type', strtoupper($fulfillmentType))
                ->selectRaw('
                    COUNT(*) as total_count,
                    SUM(CASE WHEN net_profit > 0 THEN 1 ELSE 0 END) as profitable_count,
                    SUM(CASE WHEN net_profit <= 0 THEN 1 ELSE 0 END) as unprofitable_count,
                    AVG(margin_percent) as avg_margin,
                    SUM(revenue) as total_revenue,
                    SUM(total_costs) as total_costs,
                    SUM(net_profit) as total_profit
                ')
                ->first();

            return [
                'total_count' => (int) ($stats->total_count ?? 0),
                'profitable_count' => (int) ($stats->profitable_count ?? 0),
                'unprofitable_count' => (int) ($stats->unprofitable_count ?? 0),
                'avg_margin' => round((float) ($stats->avg_margin ?? 0), 2),
                'average_margin' => round((float) ($stats->avg_margin ?? 0), 2),
                'total_revenue' => round((float) ($stats->total_revenue ?? 0), 2),
                'total_costs' => round((float) ($stats->total_costs ?? 0), 2),
                'total_profit' => round((float) ($stats->total_profit ?? 0), 2),
            ];
        });
    }

    private function canUseFastStats(array $validated): bool
    {
        foreach ([
            'search',
            'profitable',
            'quick_filter',
            'margin_min',
            'margin_max',
            'profit_min',
            'profit_max',
            'roi_min',
            'roi_max',
            'price_min',
            'price_max',
            'logistics_min',
            'logistics_max',
            'sales_min',
            'sales_max',
            'non_local_markup_min',
            'non_local_markup_max',
            'confidence',
            'locality_state',
        ] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null && $validated[$field] !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Агрегаты для текущей отфильтрованной выборки, но без пагинации.
     * Карточки на фронте не должны считаться только по первым 50 строкам.
     */
    private function getStatsFromQuery($query): array
    {
        $stats = $query
            ->selectRaw('
                COUNT(*) as total_count,
                SUM(CASE WHEN net_profit > 0 THEN 1 ELSE 0 END) as profitable_count,
                SUM(CASE WHEN net_profit <= 0 THEN 1 ELSE 0 END) as unprofitable_count,
                AVG(margin_percent) as avg_margin,
                SUM(revenue) as total_revenue,
                SUM(total_costs) as total_costs,
                SUM(net_profit) as total_profit
            ')
            ->first();

        return [
            'total_count' => (int) ($stats->total_count ?? 0),
            'profitable_count' => (int) ($stats->profitable_count ?? 0),
            'unprofitable_count' => (int) ($stats->unprofitable_count ?? 0),
            'avg_margin' => round((float) ($stats->avg_margin ?? 0), 2),
            'average_margin' => round((float) ($stats->avg_margin ?? 0), 2),
            'total_revenue' => round((float) ($stats->total_revenue ?? 0), 2),
            'total_costs' => round((float) ($stats->total_costs ?? 0), 2),
            'total_profit' => round((float) ($stats->total_profit ?? 0), 2),
        ];
    }

    /**
     * Одна выборка остатков/интеграций на страницу, чтобы не бить N+1 в enrichCacheItem.
     *
     * @return array{
     *   warehouses_by_key: Collection,
     *   inventory_by_product_key: Collection,
     *   actual_schemes_by_product_key: Collection,
     *   wb_warehouses_by_key: Collection,
     *   wb_warehouses_by_product_key: Collection,
     *   integrations_by_id: Collection
     * }|null
     */
    private function buildUnitEconomicsPageContext(string $marketplace, Collection $itemsCollection): ?array
    {
        if ($itemsCollection->isEmpty() || ! Schema::hasTable('inventory_warehouses')) {
            return null;
        }

        $integrationIds = $itemsCollection->pluck('integration_id')->unique()->filter()->values()->all();
        if ($integrationIds === []) {
            return null;
        }

        $inventoryLookupKeys = [];
        foreach ($itemsCollection as $cache) {
            foreach ($this->resolveInventoryLookupKeys($cache, $cache->product) as $lookupKey) {
                $inventoryLookupKeys[$lookupKey] = true;
            }
        }

        $skus = array_keys($inventoryLookupKeys);
        if ($skus === []) {
            return null;
        }

        $warehouseRows = InventoryWarehouse::query()
            ->whereIn('sku', $skus)
            ->whereIn('integration_id', $integrationIds)
            ->when(
                in_array($marketplace, ['yandex', 'yandex_market'], true),
                fn ($query) => $query->whereIn('marketplace', ['yandex', 'yandex_market']),
                fn ($query) => $query->where('marketplace', $marketplace)
            )
            ->get([
                'id',
                'marketplace',
                'fulfillment_type',
                'warehouse_id',
                'warehouse_name',
                'warehouse_coefficient',
                'quantity',
                'average_daily_sales',
                'last_updated',
                'sku',
                'integration_id',
            ]);

        $warehousesByKey = $warehouseRows->groupBy(fn ($w) => $w->sku.'|'.$w->integration_id);
        $warehousesByProductKey = $itemsCollection->mapWithKeys(function (UnitEconomicsCache $cache) use ($warehousesByKey) {
            $lookupKeys = $this->resolveInventoryLookupKeys($cache, $cache->product);
            $warehouseItems = collect($lookupKeys)
                ->flatMap(fn (string $lookupKey) => $warehousesByKey->get($lookupKey.'|'.$cache->integration_id, collect()))
                ->unique('id')
                ->values();

            return [$this->unitEconomicsProductContextKey($cache) => $warehouseItems];
        });

        $actualSchemesByProductKey = $warehousesByProductKey->map(
            fn (Collection $warehouses): ?string => $this->resolveActualSchemeFromWarehouseRows($warehouses)
        );

        $integrationsById = collect();
        if ($marketplace === 'wildberries') {
            $integrationsById = Integration::query()
                ->whereIn('id', $integrationIds)
                ->get()
                ->keyBy('id');
        }

        return [
            'warehouses_by_key' => $warehousesByKey,
            'inventory_by_product_key' => $warehousesByProductKey,
            'actual_schemes_by_product_key' => $actualSchemesByProductKey,
            'wb_warehouses_by_key' => $warehousesByKey,
            'wb_warehouses_by_product_key' => $warehousesByProductKey,
            'integrations_by_id' => $integrationsById,
        ];
    }

    private function unitEconomicsProductContextKey(UnitEconomicsCache $cache): string
    {
        return ($cache->product_id ?? $cache->sku).'|'.$cache->integration_id;
    }

    private function resolveActualSchemeFromWarehouseRows(Collection $warehouses): ?string
    {
        $rows = $warehouses
            ->filter(fn ($row): bool => filled($row->fulfillment_type))
            ->groupBy(fn ($row): string => strtoupper((string) $row->fulfillment_type))
            ->map(function (Collection $items, string $scheme): ?array {
                $normalizedScheme = $this->normalizeFulfillmentScheme($scheme);
                if ($normalizedScheme === null) {
                    return null;
                }

                return [
                    'scheme' => $normalizedScheme,
                    'total_quantity' => (float) $items->sum('quantity'),
                    'rows_count' => $items->count(),
                ];
            })
            ->filter()
            ->values();

        if ($rows->isEmpty()) {
            return null;
        }

        $withStock = $rows->filter(fn (array $row): bool => $row['total_quantity'] > 0);
        $winner = ($withStock->isNotEmpty() ? $withStock : $rows)
            ->sortByDesc('rows_count')
            ->sortByDesc('total_quantity')
            ->first();

        return $winner['scheme'] ?? null;
    }

    /**
     * Обогатить данные кэша полями из Product для совместимости с v1 API
     */
    private function enrichCacheItem(
        UnitEconomicsCache $cache,
        string $fulfillmentType,
        ?UnitEconomicsSettings $settings = null,
        ?array $pageContext = null
    ): array {
        $product = $cache->product;
        $ozonData = $product?->ozon_data ?? [];
        $yandexData = $product?->yandex_data ?? [];
        $commissions = $ozonData['commissions'] ?? [];
        $redemption = $ozonData['redemption'] ?? [];
        $salesCount = max(1, (int) $cache->sales_count);

        // Получаем реальную схему товара из preload-остатков страницы. Fallback оставлен
        // для show()/экспорта без контекста, но index не делает запрос на каждую строку.
        $preloadedActualScheme = ($pageContext['actual_schemes_by_product_key'] ?? null)?->get($this->unitEconomicsProductContextKey($cache));
        $realScheme = $preloadedActualScheme
            ?? ($pageContext === null ? $this->resolveActualSchemeFromInventory(
                (int) $cache->integration_id,
                (string) $cache->marketplace,
                (string) $cache->sku
            ) : null)
            ?? $product?->fulfillment_type
            ?? match ($cache->marketplace) {
                'yandex', 'yandex_market' => 'FBY',
                default => 'FBO',
            };

        // Базовые данные из кэша
        $data = $cache->toArray();

        // Убираем relation чтобы не дублировать
        unset($data['product']);

        $marketplaceData = is_array($data['marketplace_data'] ?? null) ? $data['marketplace_data'] : [];

        // Артикул продавца. sku у WB = штрихкод (для совпадения с кэшем/отчётами), реальный
        // артикул лежит в products.vendor_code. У Ozon vendor_code = offer_id = sku. Поэтому
        // единое поле article = vendor_code ?: sku — корректный артикул для всех маркетплейсов.
        $vendorCode = $product?->vendor_code;
        $data['vendor_code'] = $vendorCode;
        $data['article'] = ($vendorCode !== null && $vendorCode !== '') ? $vendorCode : $cache->sku;

        // Добавляем поля для совместимости с v1
        $data['actual_weight'] = $product ? (float) ($product->weight ?? 0) / 1000 : 0;
        $data['turnover_days'] = $product?->turnover_days ?? 30;
        $volumeWeight = $cache->volume_weight ?? $product?->volume_weight;
        $chargeableVolumeLiters = $data['chargeable_volume_liters']
            ?? ($data['marketplace_data']['chargeable_volume_liters'] ?? null)
            ?? ($cache->volume_liters !== null ? max((float) $cache->volume_liters, (float) (($volumeWeight ?? 0) * 5)) : null);

        // Габариты как объект
        $data['dimensions'] = [
            'length' => $cache->depth ? number_format((float) $cache->depth, 2, '.', '') : null,
            'width' => $cache->width ? number_format((float) $cache->width, 2, '.', '') : null,
            'height' => $cache->height ? number_format((float) $cache->height, 2, '.', '') : null,
            'weight' => $cache->weight ? number_format((float) $cache->weight, 2, '.', '') : null,
            'volume' => $cache->volume_liters ? number_format((float) $cache->volume_liters, 4, '.', '') : null,
            'volume_weight' => $volumeWeight !== null ? number_format((float) $volumeWeight, 4, '.', '') : null,
            'chargeable_volume' => $chargeableVolumeLiters !== null ? number_format((float) $chargeableVolumeLiters, 4, '.', '') : null,
        ];

        // Комиссии по схемам из ozon_data
        $data['commissions'] = $commissions;

        // Данные выкупа: каноничные значения берём из кэша (rate/source/counts),
        // ozon_data['redemption'] оставляем как сырое поле на случай, если фронт
        // всё ещё смотрит туда — но авторитет у полей верхнего уровня из кэша.
        $data['redemption'] = array_merge(is_array($redemption) ? $redemption : [], [
            'redemption_rate' => $cache->redemption_rate !== null ? (float) $cache->redemption_rate : null,
            'redemption_source' => $cache->redemption_source,
            'orders_count' => $cache->orders_count,
            'returns_count' => $cache->returns_count,
            'delivered_count' => $marketplaceData['delivered_count']
                ?? $redemption['delivered_count']
                ?? null,
            'cancelled_count' => $marketplaceData['cancelled_count']
                ?? $marketplaceData['cancellations_count']
                ?? $marketplaceData['cancellations']
                ?? $redemption['cancelled_count']
                ?? $redemption['cancellations_count']
                ?? $redemption['cancellations']
                ?? null,
            'not_redeemed_count' => $marketplaceData['not_redeemed_count']
                ?? $redemption['not_redeemed_count']
                ?? null,
            'in_flight_count' => $marketplaceData['in_flight_count']
                ?? $redemption['in_flight_count']
                ?? null,
            'period_days' => $marketplaceData['redemption_period_days']
                ?? $marketplaceData['redemption']['period_days']
                ?? $redemption['period_days']
                ?? \App\Domains\Ozon\UnitEconomics\RedemptionSource::fromStringSafe($cache->redemption_source)->periodDays(),
            // Семейство источника — стабильный фронт-контракт независимо от
            // конкретного sub-источника (postings_28d/analytics_api_28d/… → 'postings'/'api'/…)
            'source_family' => \App\Domains\Ozon\UnitEconomics\RedemptionSource::fromStringSafe($cache->redemption_source)->family()->value,
            'is_reliable' => \App\Domains\Ozon\UnitEconomics\RedemptionSource::fromStringSafe($cache->redemption_source)->family()->isReliable(),
            'is_default' => in_array(
                $cache->redemption_source ?? 'default',
                ['default', 'no_sales_28d'],
                true
            ),
            'is_no_sales' => ($cache->redemption_source ?? null) === 'no_sales_28d',
        ]);

        // Поля *_per_unit
        $data['logistics_per_unit'] = round((float) $cache->logistics_cost, 2);
        $data['last_mile_per_unit'] = round((float) $cache->last_mile_cost, 2);
        $data['commission_per_unit'] = round((float) $cache->commission_amount, 2);
        $data['acquiring_per_unit'] = round((float) $cache->acquiring_amount, 2);
        $data['storage_per_unit'] = round((float) $cache->storage_cost, 2);
        $data['total_costs_per_unit'] = round((float) $cache->total_costs / $salesCount, 2);

        // Дополнительные поля
        $deliveryLogistics = (float) $cache->logistics_cost;
        if ($deliveryLogistics <= 0) {
            $deliveryLogistics = (float) $cache->base_logistics_cost;
        }
        $deliveryCost = $deliveryLogistics + (float) $cache->last_mile_cost;
        $data['delivery_cost'] = round($deliveryCost, 2);

        $expectedReturnCost = (float) $cache->expected_return_cost;
        if ($expectedReturnCost <= 0) {
            $redemptionRate = $cache->redemption_rate;
            $returnBase = (float) $cache->return_logistics_cost;
            if ($returnBase <= 0) {
                $returnBase = $deliveryCost;
            }

            // Доля «не доехавших» (отмены + невыкупы) — из % выкупа.
            $returnFraction = 0.0;
            if ($redemptionRate !== null) {
                $rate = max(0, min(100, (float) $redemptionRate));
                $returnFraction = (100 - $rate) / 100;
            }

            // Ozon: пост-доставочные возвраты (/v1/returns/*) выкуп по постингам
            // не видит, поэтому добавляем их долю отдельно — так же, как в
            // OzonUnitEconomicsCalculator. Для WB/Yandex возвраты уже зашиты в
            // redemption_rate, повторно не учитываем.
            if ($cache->marketplace === 'ozon') {
                $ordersForReturns = (int) ($cache->orders_count ?? 0);
                $returnsForReturns = (int) ($cache->returns_count ?? 0);
                if ($ordersForReturns > 0 && $returnsForReturns > 0) {
                    $returnFraction = min(1.0, $returnFraction + ($returnsForReturns / $ordersForReturns));
                }
            }

            if ($returnFraction > 0) {
                $expectedReturnCost = $returnBase * $returnFraction;
            }
        }

        $data['expected_return_cost'] = round($expectedReturnCost, 2);
        $data['expected_returns_per_unit'] = round($expectedReturnCost, 2);

        $effectiveLogistics = (float) $cache->effective_logistics;
        if ($effectiveLogistics <= 0) {
            $effectiveLogistics = $deliveryCost + $expectedReturnCost;
        }
        $data['effective_logistics'] = round($effectiveLogistics, 2);

        if ($cache->marketplace === 'ozon') {
            $ozonData = is_array($product?->ozon_data ?? null) ? $product->ozon_data : [];
            $marketplaceData = is_array($data['marketplace_data'] ?? null) ? $data['marketplace_data'] : [];

            unset(
                $data['avg_delivery_time_hours'],
                $data['logistics_coefficient'],
                $data['additional_commission_percent'],
                $data['tariff_status'],
                $data['logistics_with_coefficient'],
                $data['additional_commission_amount']
            );

            $data['tariff_version'] = $cache->tariff_version;
            $data['tariff_effective_from'] = optional($cache->tariff_effective_from)?->toDateString();
            $data['tariff_source'] = $cache->tariff_source;
            $pricingStrategy = is_array($marketplaceData['pricing_strategy'] ?? null) ? $marketplaceData['pricing_strategy'] : [];
            $data['pricing_strategy'] = $pricingStrategy ?: null;
            $data['competitor_price'] = isset($marketplaceData['competitor_price'])
                ? round((float) $marketplaceData['competitor_price'], 2)
                : (isset($pricingStrategy['competitor_price']) ? round((float) $pricingStrategy['competitor_price'], 2) : null);
            $data['current_price_index'] = isset($marketplaceData['current_price_index'])
                ? round((float) $marketplaceData['current_price_index'], 4)
                : (isset($pricingStrategy['current_price_index']) ? round((float) $pricingStrategy['current_price_index'], 4) : null);
            $data['current_price_is_favorable'] = array_key_exists('current_price_is_favorable', $marketplaceData)
                ? ($marketplaceData['current_price_is_favorable'] === null ? null : (bool) $marketplaceData['current_price_is_favorable'])
                : (array_key_exists('current_price_is_favorable', $pricingStrategy)
                    ? ($pricingStrategy['current_price_is_favorable'] === null ? null : (bool) $pricingStrategy['current_price_is_favorable'])
                    : null);
            $data['current_price_index_label'] = $marketplaceData['current_price_index_label']
                ?? $pricingStrategy['current_price_index_label']
                ?? null;
            $data['current_price_competitor_delta'] = isset($marketplaceData['current_price_competitor_delta'])
                ? round((float) $marketplaceData['current_price_competitor_delta'], 2)
                : (isset($pricingStrategy['current_price_competitor_delta']) ? round((float) $pricingStrategy['current_price_competitor_delta'], 2) : null);
            $data['current_price_competitor_delta_percent'] = isset($marketplaceData['current_price_competitor_delta_percent'])
                ? round((float) $marketplaceData['current_price_competitor_delta_percent'], 2)
                : (isset($pricingStrategy['current_price_competitor_delta_percent']) ? round((float) $pricingStrategy['current_price_competitor_delta_percent'], 2) : null);
            $activeFixation = is_array($ozonData['active_fixation'] ?? null) ? $ozonData['active_fixation'] : [];
            $orderEconomicsSummary = is_array($marketplaceData['order_economics_summary'] ?? null)
                ? $marketplaceData['order_economics_summary']
                : (is_array($ozonData['order_economics_summary'] ?? null) ? $ozonData['order_economics_summary'] : []);
            $data['route_key'] = $cache->route_key
                ?? $marketplaceData['route_key']
                ?? ($activeFixation['shipping_cluster_id'] ?? null)
                ?? ($ozonData['route_key'] ?? null);
            $data['route_label'] = $cache->route_label
                ?? $marketplaceData['route_label']
                ?? ($activeFixation['shipping_cluster_name'] ?? null)
                ?? ($ozonData['route_label'] ?? null);
            // shipping_cluster_id / shipping_cluster_name удалены из ответа —
            // используйте route_key / route_label (идентичные значения)
            $data['destination_cluster_id'] = $marketplaceData['destination_cluster_id']
                ?? null;
            $data['destination_cluster_name'] = $marketplaceData['destination_cluster_name']
                ?? null;
            $data['fixation_applied'] = $marketplaceData['fixation_applied']
                ?? ($activeFixation['fixation_applied'] ?? false);
            $data['fixation_id'] = $marketplaceData['fixation_id']
                ?? ($activeFixation['fixation_id'] ?? null);
            $data['fixation_base_date'] = $marketplaceData['fixation_base_date']
                ?? ($activeFixation['fixation_base_date'] ?? null);
            $data['fixed_until'] = $marketplaceData['fixed_until']
                ?? ($activeFixation['fixed_until'] ?? null);
            $data['tariff_version_used'] = $marketplaceData['tariff_version_used']
                ?? ($activeFixation['tariff_version_used'] ?? $data['tariff_version']);
            $data['markup_version_used'] = $marketplaceData['markup_version_used']
                ?? ($activeFixation['markup_version_used'] ?? $data['tariff_version']);
            $data['calculation_mode'] = $marketplaceData['calculation_mode']
                ?? ($activeFixation['calculation_mode'] ?? 'preview');
            $data['is_local_sale'] = $cache->is_local_sale;
            if ($data['is_local_sale'] === null && array_key_exists('is_local_sale', $marketplaceData)) {
                $data['is_local_sale'] = $marketplaceData['is_local_sale'];
            }
            if ($data['is_local_sale'] === null && array_key_exists('is_local_sale', $ozonData)) {
                $data['is_local_sale'] = $ozonData['is_local_sale'];
            }
            $data['price_segment'] = $cache->price_segment;
            $data['sales_fee_percent'] = round((float) ($cache->sales_fee_percent ?? $cache->commission_percent), 2);
            $data['route_resolution_status'] = $marketplaceData['route_resolution_status']
                ?? $ozonData['route_resolution_status']
                ?? ($data['route_key'] ? 'resolved' : 'unknown');
            $data['locality_resolution_status'] = $marketplaceData['locality_resolution_status']
                ?? $ozonData['locality_resolution_status']
                ?? ($data['is_local_sale'] !== null ? 'resolved' : 'unknown');
            $data['calculation_confidence'] = $marketplaceData['calculation_confidence']
                ?? $ozonData['calculation_confidence']
                ?? ($data['route_key'] ? 'high' : 'low');
            $data['profile_source'] = $marketplaceData['profile_source']
                ?? $ozonData['profile_source']
                ?? ($data['route_key'] ? 'api' : 'repo_fallback');
            $data['dominant_cluster_id'] = $marketplaceData['dominant_cluster_id']
                ?? $ozonData['dominant_cluster_id']
                ?? null;
            $data['dominant_cluster_share'] = isset($marketplaceData['dominant_cluster_share'])
                ? round((float) $marketplaceData['dominant_cluster_share'], 2)
                : (isset($ozonData['dominant_cluster_share'])
                    ? round((float) $ozonData['dominant_cluster_share'], 2)
                    : null);
            $data['sales_7_days'] = isset($marketplaceData['sales_7_days'])
                ? (int) $marketplaceData['sales_7_days']
                : (isset($ozonData['sales_7_days']) ? (int) $ozonData['sales_7_days'] : null);
            // markup_allowed считаем по актуальным sales_7_days, а не по stale значению из кеша.
            // Наценка за нелокальную продажу Ozon относится к FBO; FBS-заказы не запускают её.
            $isFboScheme = strtoupper($cache->fulfillment_type ?? '') === 'FBO';
            $sellerSales7Days = $data['sales_7_days'];
            $data['markup_allowed'] = $isFboScheme && ($sellerSales7Days === null || $sellerSales7Days >= 50);
            $profitRange = $this->normalizeProfitRangeForNetProfit($marketplaceData, (float) $cache->net_profit);
            $data['profit_min'] = $profitRange['profit_min'];
            $data['profit_base'] = $profitRange['profit_base'];
            $data['profit_max'] = $profitRange['profit_max'];
            $marketplaceData['profit_min'] = $data['profit_min'];
            $marketplaceData['profit_base'] = $data['profit_base'];
            $marketplaceData['profit_max'] = $data['profit_max'];
            $data['clusters_summary'] = is_array($marketplaceData['clusters_summary'] ?? null)
                ? $marketplaceData['clusters_summary']
                : (is_array($ozonData['clusters_summary'] ?? null)
                    ? $ozonData['clusters_summary']
                    : []);
            $data['stock_profile'] = is_array($marketplaceData['stock_profile'] ?? null)
                ? $marketplaceData['stock_profile']
                : (is_array($ozonData['stock_profile'] ?? null)
                    ? $ozonData['stock_profile']
                    : []);
            $data['sales_profile'] = is_array($marketplaceData['sales_profile'] ?? null)
                ? $marketplaceData['sales_profile']
                : (is_array($ozonData['sales_profile'] ?? null)
                    ? $ozonData['sales_profile']
                    : []);
            [$data['clusters_summary'], $data['sales_profile']] = $this->normalizeOzonClusterMarkupData(
                $data['clusters_summary'],
                $data['sales_profile'],
                $data['stock_profile'],
                (bool) ($data['markup_allowed'] ?? true),
                $isFboScheme ? 'fbo_lt_50_orders_7d' : 'non_fbo_no_nonlocal_markup'
            );
            $marketplaceData['clusters_summary'] = $data['clusters_summary'];
            $marketplaceData['sales_profile'] = $data['sales_profile'];

            // expected_locality_rate: сначала пробуем пересчитать из свежих clusters_summary,
            // только в отсутствие данных — fallback на is_local_sale (100%/0%).
            $localityShare = 0.0;
            $hasLocalityDemand = false;
            foreach ($data['clusters_summary'] as $clusterRow) {
                if (! is_array($clusterRow)) {
                    continue;
                }
                $share = (float) ($clusterRow['orders_percent'] ?? 0);
                if ($share <= 0) {
                    continue;
                }
                $hasLocalityDemand = true;
                if (! empty($clusterRow['is_local_cluster'])) {
                    $localityShare += $share;
                }
            }
            if ($hasLocalityDemand) {
                $data['expected_locality_rate'] = round(min(100.0, $localityShare), 2);
            } else {
                $data['expected_locality_rate'] = $data['is_local_sale'] === null
                    ? null
                    : ($data['is_local_sale'] ? 100.0 : 0.0);
            }

            // Единственный источник истины для наценки — Σ(share × effective_markup_percent).
            // Никаких чтений из $cache->non_local_markup_percent или $marketplaceData —
            // значения всегда пересчитываются на лету из обогащённых clusters_summary.
            $recalculatedWeighted = 0.0;
            foreach ($data['clusters_summary'] as $clusterRow) {
                if (! is_array($clusterRow)) {
                    continue;
                }
                $share = (float) ($clusterRow['orders_percent'] ?? 0);
                if ($share <= 0) {
                    continue;
                }
                $recalculatedWeighted += ($share / 100) * (float) ($clusterRow['effective_markup_percent'] ?? 0);
            }
            $weightedMarkup = round($recalculatedWeighted, 2);
            [$displayMarkup, $displayMarkupAmount, $hasFactualMarkup] = $this->resolveOzonDisplayNonLocalMarkup(
                $orderEconomicsSummary,
                $weightedMarkup,
                round((float) $cache->price * ($weightedMarkup / 100), 2)
            );
            $displayMarkupSource = $hasFactualMarkup ? 'order_economics_summary' : 'delivery_profile';
            $data['weighted_non_local_markup_percent'] = $weightedMarkup;
            $data['expected_non_local_markup_percent'] = $weightedMarkup;
            $data['non_local_markup_percent'] = $displayMarkup;
            $data['logistics_markup_percent'] = $displayMarkup;
            $data['non_local_markup_amount'] = $displayMarkupAmount;
            $data['logistics_markup_amount'] = $data['non_local_markup_amount'];
            $data['raw_non_local_markup_percent'] = $weightedMarkup;
            $data['non_local_markup_source'] = $displayMarkupSource;
            if ($hasFactualMarkup) {
                $data['factual_non_local_markup_percent'] = $displayMarkup;
                $data['factual_non_local_markup_amount'] = $displayMarkupAmount;
            }

            $marketplaceData['weighted_non_local_markup_percent'] = $weightedMarkup;
            $marketplaceData['expected_non_local_markup_percent'] = $weightedMarkup;
            $marketplaceData['non_local_markup_percent'] = $displayMarkup;
            $marketplaceData['logistics_markup_percent'] = $displayMarkup;
            $marketplaceData['non_local_markup_amount'] = $data['non_local_markup_amount'];
            $marketplaceData['logistics_markup_amount'] = $data['logistics_markup_amount'];
            $marketplaceData['raw_non_local_markup_percent'] = $weightedMarkup;
            $marketplaceData['non_local_markup_source'] = $displayMarkupSource;
            if ($hasFactualMarkup) {
                $marketplaceData['factual_non_local_markup_percent'] = $displayMarkup;
                $marketplaceData['factual_non_local_markup_amount'] = $displayMarkupAmount;
            }

            $data['markup_rule_reason'] = null;
            if (! $isFboScheme) {
                $data['markup_rule_reason'] = 'non_fbo_no_nonlocal_markup';
            } elseif ($data['markup_allowed'] === false) {
                $data['markup_rule_reason'] = 'fbo_lt_50_orders_7d';
            } elseif (($data['expected_locality_rate'] ?? null) !== null && (float) $data['expected_locality_rate'] >= 99.99) {
                $data['markup_rule_reason'] = 'local_cluster';
            } elseif ($weightedMarkup > 0) {
                $data['markup_rule_reason'] = 'non_local_markup_applied';
            } else {
                $data['markup_rule_reason'] = 'no_markup_for_cluster';
            }
            $data['markup_rule_reason_label'] = $this->humanizeOzonMarkupReason(
                is_string($data['markup_rule_reason'] ?? null) ? $data['markup_rule_reason'] : null,
                $data['sales_7_days'] ?? null
            );
            $marketplaceData['markup_rule_reason'] = $data['markup_rule_reason'];
            $marketplaceData['markup_rule_reason_label'] = $data['markup_rule_reason_label'];
            // Не отдаём «Кластер поставки» во фронт — поля используются только внутренне
            // (фиксации/локальность). Для маршрута используй route_label / route_key.
            unset(
                $marketplaceData['shipping_cluster_id'],
                $marketplaceData['shipping_cluster_name']
            );
            $data['marketplace_data'] = $marketplaceData;
            $data['shipping_routes'] = is_array($marketplaceData['shipping_routes'] ?? null)
                ? $marketplaceData['shipping_routes']
                : [];
            $data['route_details'] = is_array($marketplaceData['route_details'] ?? null)
                ? $marketplaceData['route_details']
                : (is_array($ozonData['route_details'] ?? null)
                    ? $ozonData['route_details']
                    : []);
            $data['profile_data_sources'] = [
                'demand' => 'Ozon Delivery Analytics API',
                'stocks' => 'Ozon Stocks API',
                'sales' => 'Ozon postings / sales by warehouse',
            ];
            $data['order_economics_summary'] = $orderEconomicsSummary;
        } else {
            $data['logistics_with_coefficient'] = round((float) $cache->logistics_cost, 2);
            $data['additional_commission_amount'] = round((float) $cache->price * (float) $cache->additional_commission_percent / 100, 2);
        }

        // Флаги схемы — сравниваем с реальной схемой товара из UnitEconomics
        $data['is_actual_scheme'] = strtoupper($realScheme) === strtoupper($cache->fulfillment_type);
        $data['original_scheme'] = $realScheme;

        // Поля для таблицы (на верхнем уровне для удобства фронтенда)
        // Приоритет: настройки пользователя > кэш > продукт
        // В БД размеры хранятся в мм, вес в г
        $ozonData = $product?->ozon_data ?? [];
        $lengthMm = $settings?->length_mm ?? $cache->depth ?? $product?->depth ?? $ozonData['length_mm'] ?? $ozonData['dimensions']['depth'] ?? null;
        $widthMm = $settings?->width_mm ?? $cache->width ?? $product?->width ?? $ozonData['width_mm'] ?? $ozonData['dimensions']['width'] ?? null;
        $heightMm = $settings?->height_mm ?? $cache->height ?? $product?->height ?? $ozonData['height_mm'] ?? $ozonData['dimensions']['height'] ?? null;
        $weightG = $settings?->weight_g ?? $cache->weight ?? $product?->weight ?? $ozonData['weight_g'] ?? $ozonData['dimensions']['weight'] ?? null;

        $volumeLiters = $cache->volume_liters;
        if ($volumeLiters === null && $lengthMm !== null && $widthMm !== null && $heightMm !== null) {
            // Производная метрика из реальных габаритов (не мок)
            $volumeLiters = ((float) $lengthMm * (float) $widthMm * (float) $heightMm) / 1000000;
        }

        $data['scheme'] = $cache->fulfillment_type;

        // Информация о схеме для фронтенда
        $scheme = strtoupper($cache->fulfillment_type ?? 'FBO');
        $data['scheme_info'] = $this->getSchemeInfo($scheme, $cache->marketplace);

        $data['volume_liters'] = $volumeLiters !== null ? round((float) $volumeLiters, 4) : null;
        $data['volume_weight'] = $volumeWeight !== null ? round((float) $volumeWeight, 4) : null;
        $data['chargeable_volume_liters'] = $chargeableVolumeLiters !== null ? round((float) $chargeableVolumeLiters, 4) : null;
        $data['length_mm'] = $lengthMm !== null ? round((float) $lengthMm, 0) : null;
        $data['width_mm'] = $widthMm !== null ? round((float) $widthMm, 0) : null;
        $data['height_mm'] = $heightMm !== null ? round((float) $heightMm, 0) : null;
        $data['weight_g'] = $weightG !== null ? round((float) $weightG, 0) : null;

        // === WB-специфичные поля (данные из wb_data аналогично ozon_data) ===
        $marketplace = $cache->marketplace;
        if ($marketplace === 'wildberries') {
            $wbData = $product?->wb_data ?? [];
            $wbCommissions = $wbData['commissions'] ?? [];
            $price = (float) $cache->price;
            $costPrice = (float) ($settings?->cost_price ?? $cache->cost_price);

            $data['acquiring_percent'] = round((float) ($data['acquiring_percent'] ?? $cache->acquiring_percent ?? 1.5), 2);
            $data['acquiring_amount'] = round((float) ($data['acquiring_amount'] ?? $cache->acquiring_amount ?? ($price * $data['acquiring_percent'] / 100)), 2);
            $data['acquiring_per_unit'] = round((float) ($data['acquiring_per_unit'] ?? ($data['acquiring_amount'] ?? 0)), 2);

            // Габариты из wb_data (приоритет: настройки > wb_data > кэш)
            if (! $lengthMm && isset($wbData['length_mm'])) {
                $lengthMm = $wbData['length_mm'];
                $data['length_mm'] = round((float) $lengthMm, 0);
            }
            if (! $widthMm && isset($wbData['width_mm'])) {
                $widthMm = $wbData['width_mm'];
                $data['width_mm'] = round((float) $widthMm, 0);
            }
            if (! $heightMm && isset($wbData['height_mm'])) {
                $heightMm = $wbData['height_mm'];
                $data['height_mm'] = round((float) $heightMm, 0);
            }
            if (! $weightG && isset($wbData['weight_g'])) {
                $weightG = $wbData['weight_g'];
                $data['weight_g'] = round((float) $weightG, 0);
            }

            // Пересчитываем объём если есть габариты из wb_data
            if ($lengthMm && $widthMm && $heightMm && ! $volumeLiters) {
                $volumeLiters = ((float) $lengthMm * (float) $widthMm * (float) $heightMm) / 1000000;
                $data['volume_liters'] = round($volumeLiters, 4);
            }

            // Комиссия из wb_data.commissions (аналогично ozon_data.commissions)
            $schemeKey = strtolower($cache->fulfillment_type ?? 'fbo');
            $commissionPercent = (float) ($cache->commission_percent ?? $wbCommissions[$schemeKey]['percent'] ?? 15);
            $data['commission_percent'] = $commissionPercent;
            $data['commission_amount'] = round((float) ($cache->commission_amount ?? ($price * $commissionPercent / 100)), 2);

            // СПП (скидка постоянного покупателя) — приоритет: настройки > wb_data >
            // marketplace_data кэша (туда синк кладёт витринный/фактический СПП) > 0.
            // Без marketplace_data СПП всегда 0: wb_data это поле не содержит, а
            // колонки spp_percent в unit_economics_cache нет.
            $sppPercent = (float) ($settings?->spp_percent ?? $wbData['spp_percent'] ?? $marketplaceData['spp_percent'] ?? $cache->spp_percent ?? 0);
            $data['spp_percent'] = $sppPercent;

            // СПП на WB финансирует маркетплейс — он информационный и НЕ уменьшает
            // выручку/комиссию/сумму к перечислению (они считаются от действующей цены).
            // Цена покупателя (что реально платит покупатель) = цена до СПП (old_price =
            // зачёркнутая = card.wb.ru basic, от неё и измерен СПП) × (1 - СПП%).
            $sppBasePrice = max(0.0, (float) ($cache->old_price ?: $price));
            $customerPrice = $sppBasePrice > 0 ? $sppBasePrice * (1 - $sppPercent / 100) : $price;
            $data['customer_price'] = round($customerPrice, 2);
            $data['spp_amount'] = round(max(0.0, $sppBasePrice - $customerPrice), 2);

            // Наценка, x = цена / себестоимость
            $data['markup_multiplier'] = $costPrice > 0 ? round($price / $costPrice, 2) : 0;

            // КС (коэффициент склада). Единый источник с расчётом: взвешенный по
            // остаткам коэффициент складов товара из wb_data.stock_warehouses +
            // снапшоты тарифов WB (реальные имена складов: Электросталь, Коледино…).
            // Раньше тултип брался из InventoryWarehouse, где у WB всё лежит под общим
            // «Мой склад» без коэффициента — отсюда расхождение «КС 156%, но нет данных
            // по складам». Теперь число КС и тултип берутся отсюда же.
            $wbDataForCoef = is_array($product?->wb_data ?? null) ? $product->wb_data : [];
            $wbBreakdown = $this->cacheService->resolveWildberriesWarehouseBreakdown(
                (int) $cache->integration_id,
                $wbDataForCoef
            );
            $avgWarehouseCoef = (float) ($wbBreakdown['coefficient'] ?? 1.0);
            $warehouseCoefPercent = (float) ($wbBreakdown['percent'] ?? ($avgWarehouseCoef * 100));
            $warehouseDetails = is_array($wbBreakdown['details'] ?? null) ? $wbBreakdown['details'] : [];
            $wbHasStock = (bool) ($wbBreakdown['has_stock'] ?? false);
            $wbIntegrationAvg = (float) ($wbBreakdown['integration_avg'] ?? $avgWarehouseCoef);

            // Нет остатков ни на одном складе — показываем честно, что КС это среднее
            // по магазину, а не пустой тултип «Нет данных по складам» при ненулевом КС.
            if (! $wbHasStock && $wbIntegrationAvg > 0) {
                $warehouseDetails = [[
                    'warehouse_id' => null,
                    'warehouse_name' => 'Нет остатков — средний КС по магазину',
                    'coefficient_raw' => round($wbIntegrationAvg, 3),
                    'coefficient' => round($wbIntegrationAvg * 100, 0),
                    'quantity' => 0,
                    'share_percent' => 0.0,
                ]];
            }

            $data['warehouse_coef_percent'] = round((float) ($marketplaceData['warehouse_coef_percent'] ?? $warehouseCoefPercent), 0);
            $data['warehouse_coefficient'] = round((float) ($marketplaceData['warehouse_coefficient'] ?? $avgWarehouseCoef), 3);
            $data['warehouse_details'] = $warehouseDetails; // Детализация по складам для tooltip
            $baseLogistics = (float) $cache->base_logistics_cost;
            // Сумма надбавки КС = базовая логистика × (коэффициент - 1)
            $data['warehouse_coef_amount'] = round((float) ($marketplaceData['warehouse_coef_amount'] ?? ($baseLogistics * ($avgWarehouseCoef - 1))), 2);

            // ИЛ (индекс локализации) — из интеграции (настройка магазина)
            if ($pageContext !== null && isset($pageContext['integrations_by_id'])) {
                $integration = $pageContext['integrations_by_id']->get($cache->integration_id);
            } else {
                $integration = Integration::find($cache->integration_id);
            }
            $integrationSettings = is_array($integration?->settings ?? null) ? $integration->settings : [];
            $localizationIndex = (float) (
                $marketplaceData['localization_index']
                ?? $integrationSettings['wb_localization_index']
                ?? $integration?->localization_index
                ?? $cache->logistics_coefficient
                ?? 1.0
            );
            $data['localization_index'] = $localizationIndex;
            // Сумма надбавки/скидки ИЛ = базовая логистика × КС × (ИЛ - 1)
            $data['localization_amount'] = round((float) ($marketplaceData['localization_amount'] ?? ($baseLogistics * $avgWarehouseCoef * ($localizationIndex - 1))), 2);

            $salesDistributionIndex = (float) (
                $marketplaceData['sales_distribution_index']
                ?? $marketplaceData['sales_distribution_index_percent']
                ?? $integrationSettings['wb_sales_distribution_index']
                ?? $integrationSettings['sales_distribution_index']
                ?? 0.0
            );
            $salesDistributionAmount = (float) (
                $marketplaceData['sales_distribution_amount']
                ?? (((float) ($cache->old_price ?: $cache->price)) * ($salesDistributionIndex / 100))
            );
            $data['sales_distribution_index'] = round($salesDistributionIndex, 4);
            $data['sales_distribution_amount'] = round($salesDistributionAmount, 2);

            // Базовая логистика
            $data['base_logistics'] = round($baseLogistics, 2);

            // Обратная логистика
            $data['return_logistics'] = round((float) $cache->return_logistics_cost, 2);

            // Всего затрат, %
            $commissionAmount = $price * $commissionPercent / 100;
            $totalExpenses = $commissionAmount + (float) $cache->logistics_cost +
                             $expectedReturnCost + (float) $cache->storage_cost + (float) ($data['acquiring_amount'] ?? 0);
            $data['total_expenses_percent'] = $price > 0 ? round($totalExpenses / $price * 100, 2) : 0;

            // На р/с (to_settlement_account) — от действующей цены, т.к. СПП финансирует
            // WB и не уменьшает сумму к перечислению продавцу.
            $toSettlement = $price - $commissionAmount -
                           (float) $cache->logistics_cost - $expectedReturnCost - (float) $cache->storage_cost - (float) ($data['acquiring_amount'] ?? 0);
            $data['to_settlement_account'] = round($toSettlement, 2);

            // Сохраняем wb_data.commissions для фронтенда (аналогично ozon_data)
            $data['commissions'] = $wbCommissions;
        }

        $inventorySnapshot = $this->resolveInventorySnapshot($cache, $product, $pageContext);
        $data['internal_stock'] = $inventorySnapshot['internal_stock'];
        $data['marketplace_stock'] = $inventorySnapshot['marketplace_stock'];
        $data['current_stock'] = $inventorySnapshot['current_stock'];
        $data['total_stock'] = $inventorySnapshot['current_stock'];
        $data['stock'] = $inventorySnapshot['current_stock'];
        $data['average_daily_sales'] = $inventorySnapshot['average_daily_sales'];
        $data['days_of_stock'] = $inventorySnapshot['days_of_stock'];
        $data['stock_status'] = $inventorySnapshot['stock_status'];
        $data['last_updated'] = $inventorySnapshot['last_updated'];

        if ($marketplace === 'yandex' || $marketplace === 'yandex_market') {
            $price = (float) $cache->price;

            $data['yandex'] = [
                'shopSku' => $cache->sku,
                'marketSku' => $yandexData['marketSku'] ?? null,
                'categoryId' => $yandexData['categoryId'] ?? null,
                'parameters' => $yandexData['parameters'] ?? [],
            ];

            // Разбивка тарифов из yandex_data['tariffs'] — только для отображения детализации
            // Итоговые суммы (commission, acquiring, logistics, total_costs, net_profit) берём из кэша —
            // они уже корректно рассчитаны калькулятором через tariffBreakdown
            $tariffBreakdown = [];
            foreach ($yandexData['tariffs'] ?? [] as $t) {
                $type = strtoupper((string) ($t['type'] ?? ''));
                $amount = (float) ($t['amount'] ?? 0);
                if ($type !== '' && ! isset($tariffBreakdown[$type])) {
                    $tariffBreakdown[$type] = $amount;
                }
            }

            // Детализация тарифов для фронта
            $data['agency_commission'] = round($tariffBreakdown['AGENCY_COMMISSION'] ?? 0, 2);
            $data['payment_transfer'] = round($tariffBreakdown['PAYMENT_TRANSFER'] ?? 0, 2);
            $data['delivery_to_customer'] = round($tariffBreakdown['DELIVERY_TO_CUSTOMER'] ?? (float) $cache->logistics_cost, 2);
            $data['crossregional_delivery'] = round($tariffBreakdown['CROSSREGIONAL_DELIVERY'] ?? 0, 2);
            $data['middle_mile'] = round($tariffBreakdown['MIDDLE_MILE'] ?? 0, 2);
            $data['express_delivery'] = round($tariffBreakdown['EXPRESS_DELIVERY'] ?? 0, 2);
            $data['sorting'] = round($tariffBreakdown['SORTING'] ?? (float) $cache->processing_cost, 2);

            // Комиссия и acquiring — из кэша (уже рассчитаны калькулятором)
            $data['referral_fee_percent'] = round((float) $cache->commission_percent, 2);
            $data['fby_delivery'] = round($data['delivery_to_customer'] + ($tariffBreakdown['MIDDLE_MILE'] ?? 0), 2);
            $data['fbs_delivery'] = round($data['delivery_to_customer'], 2);
            $data['return_logistics'] = round((float) $cache->return_logistics_cost, 2);
            $data['return_processing'] = round((float) $cache->return_processing_cost, 2);
            $data['packaging_cost'] = round((float) $cache->packaging_cost, 2);
            $data['to_settlement_account'] = round(
                $price - (float) $cache->commission_amount - (float) $cache->acquiring_amount - (float) $cache->logistics_cost - $expectedReturnCost - (float) $cache->storage_cost,
                2
            );
        }

        // Рекламные расходы (пока 0)
        $data['advertising_cost'] = 0;
        $data['litrobonus'] = 0;

        // Для realFBS
        if (! isset($data['own_delivery_cost'])) {
            $data['own_delivery_cost'] = in_array(strtoupper((string) ($cache->fulfillment_type ?? '')), ['RFBS', 'EXPRESS', 'DBS', 'EDBS'], true)
                ? round((float) $cache->logistics_cost, 2)
                : 0;
        }
        if (! isset($data['ozon_compensation'])) {
            $data['ozon_compensation'] = 0;
        }

        // === Себестоимость: приоритет settings > cache (для ВСЕХ маркетплейсов) ===
        $settingsCostPrice = ($settings?->cost_price && $settings->cost_price > 0) ? (float) $settings->cost_price : null;
        if ($settingsCostPrice !== null) {
            $data['cost_price'] = $settingsCostPrice;
        }

        // === Настройки пользователя (приоритет: settings > cache) ===
        $data['drr_percent'] = (float) ($settings?->drr_percent ?? $cache->drr_percent ?? 0);
        $data['our_share_percent'] = (float) ($settings?->our_share_percent ?? $cache->our_share_percent ?? 0);
        $data['tax_percent'] = (float) ($settings?->tax_percent ?? $cache->tax_percent ?? 6);
        $data['vat_percent'] = (float) ($settings?->vat_percent ?? $cache->vat_percent ?? 0);

        // Суммы на основе процентов
        $price = (float) $cache->price;
        $data['drr_amount'] = round((float) ($cache->drr_amount ?? ($price * $data['drr_percent'] / 100)), 2);
        $data['our_share_amount'] = round((float) ($cache->our_share_amount ?? ($price * $data['our_share_percent'] / 100)), 2);
        $data['tax_amount'] = round((float) ($cache->tax_amount ?? ($price * $data['tax_percent'] / 100)), 2);
        $data['vat_amount'] = round((float) ($cache->vat_amount ?? ($price * $data['vat_percent'] / 100)), 2);

        return $data;
    }

    /**
     * Старые Ozon profit_min/profit_base/profit_max могли быть сохранены до
     * пост-расходов (ДРР, налог, НДС, наша доля). Для отображения сдвигаем весь
     * диапазон так, чтобы его база совпадала с текущей net_profit строки кэша.
     *
     * @return array{profit_min:float,profit_base:float,profit_max:float}
     */
    private function normalizeProfitRangeForNetProfit(array $marketplaceData, float $netProfit): array
    {
        $profitBase = isset($marketplaceData['profit_base'])
            ? (float) $marketplaceData['profit_base']
            : $netProfit;
        $profitDelta = $netProfit - $profitBase;
        $profitMin = isset($marketplaceData['profit_min'])
            ? (float) $marketplaceData['profit_min'] + $profitDelta
            : $netProfit;
        $profitMax = isset($marketplaceData['profit_max'])
            ? (float) $marketplaceData['profit_max'] + $profitDelta
            : $netProfit;
        $profitRangeValues = [$profitMin, $netProfit, $profitMax];

        return [
            'profit_min' => round(min($profitRangeValues), 2),
            'profit_base' => round($netProfit, 2),
            'profit_max' => round(max($profitRangeValues), 2),
        ];
    }

    /**
     * @return array{
     *   internal_stock:int,
     *   marketplace_stock:int,
     *   current_stock:int,
     *   average_daily_sales:float,
     *   days_of_stock:?int,
     *   stock_status:string,
     *   last_updated:?string
     * }
     */
    private function resolveInventorySnapshot(
        UnitEconomicsCache $cache,
        ?Product $product,
        ?array $pageContext = null
    ): array {
        $warehouses = null;
        $inventoryLookupKeys = $this->resolveInventoryLookupKeys($cache, $product);

        if ($pageContext !== null && isset($pageContext['inventory_by_product_key'])) {
            $warehouses = $pageContext['inventory_by_product_key']->get(
                $this->unitEconomicsProductContextKey($cache),
                collect()
            );
        } elseif (
            $cache->marketplace === 'wildberries'
            && $pageContext !== null
            && isset($pageContext['wb_warehouses_by_product_key'])
        ) {
            $warehouses = $pageContext['wb_warehouses_by_product_key']->get(
                $this->unitEconomicsProductContextKey($cache),
                collect()
            );
        }

        if ($warehouses === null) {
            $warehouseQuery = InventoryWarehouse::query()
                ->where('integration_id', $cache->integration_id)
                ->whereIn('sku', $inventoryLookupKeys)
                ->select(['quantity', 'average_daily_sales', 'last_updated']);

            if (in_array($cache->marketplace, ['yandex', 'yandex_market'], true)) {
                $warehouseQuery->whereIn('marketplace', ['yandex', 'yandex_market']);
            } else {
                $warehouseQuery->where('marketplace', $cache->marketplace);
            }

            $warehouses = $warehouseQuery->get();
        }

        $internalStock = (int) ($product?->stock ?? 0);
        $marketplaceStock = (int) $warehouses->sum('quantity');
        $currentStock = $internalStock + $marketplaceStock;
        $averageDailySales = round((float) $warehouses->sum('average_daily_sales'), 4);
        $daysOfStock = $averageDailySales > 0 ? (int) round($currentStock / $averageDailySales) : null;
        $lastUpdated = $warehouses->max('last_updated');

        $stockStatus = match (true) {
            $currentStock <= 0 => 'critical',
            $daysOfStock !== null && $daysOfStock <= 7 => 'critical',
            $daysOfStock !== null && $daysOfStock <= 14 => 'low',
            $daysOfStock !== null && $daysOfStock > 60 => 'excess',
            default => 'optimal',
        };

        return [
            'internal_stock' => $internalStock,
            'marketplace_stock' => $marketplaceStock,
            'current_stock' => $currentStock,
            'average_daily_sales' => $averageDailySales,
            'days_of_stock' => $daysOfStock,
            'stock_status' => $stockStatus,
            'last_updated' => $lastUpdated?->toISOString(),
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveInventoryLookupKeys(UnitEconomicsCache $cache, ?Product $product): array
    {
        $keys = [
            $product?->sku,
            $product?->barcode,
            $product?->vendor_code,
            $cache->sku,
        ];

        return collect($keys)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    // ==================== МЕТОДЫ ИЗ V1 (ПЕРЕНЕСЁННЫЕ) ====================

    /**
     * Расчёт юнит-экономики для товара
     * POST /api/v2/unit-economics/calculate/{marketplace}
     *
     * Использует новую доменную архитектуру
     */
    public function calculate(CalculateRequest $request, string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        if ($marketplace === 'wildberries') {
            return response()->json([
                'message' => 'WB live calculation endpoint is deprecated. Use GET /unit-economics/wildberries for cached data or POST /unit-economics/recalculate/{integrationId}.',
                'data' => [
                    'marketplace' => $marketplace,
                    'deprecated' => true,
                    'replacement' => [
                        'read' => '/api/unit-economics/wildberries',
                        'recalculate' => '/api/unit-economics/recalculate/{integrationId}',
                    ],
                ],
            ], 410);
        }

        $validated = $request->validated();
        $validated['marketplace'] = $marketplace;
        $validated['fulfillment_type'] = $validated['fulfillment_type'] ?? match ($marketplace) {
            'yandex_market' => 'FBY',
            default => 'FBO',
        };
        if ($marketplace === 'ozon') {
            $validated = $this->unitEconomicsService->enrichOzonInputWithProfile($validated);
        }

        try {
            $input = CalculationInput::fromArray($validated);
            $result = $this->orchestrator->calculate($input);

            return response()->json([
                'data' => $result->toArray(),
            ]);
        } catch (\Exception $e) {
            // Fallback на legacy сервис
            $result = $this->unitEconomicsService->calculate($marketplace, $validated);

            return response()->json([
                'data' => $result,
            ]);
        }
    }

    /**
     * Сравнение маркетплейсов
     * GET /api/v2/unit-economics/comparison
     */
    public function comparison(): JsonResponse
    {
        $comparison = $this->unitEconomicsService->getMarketplaceComparison();

        return response()->json([
            'data' => $comparison,
        ]);
    }

    /**
     * Общая статистика
     * GET /api/v2/unit-economics/stats
     */
    public function stats(): JsonResponse
    {
        $stats = $this->unitEconomicsService->getOverallStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Статистика по маркетплейсу
     * GET /api/v2/unit-economics/stats/{marketplace}
     */
    public function statsByMarketplace(string $marketplace): JsonResponse
    {
        $stats = $this->unitEconomicsService->getStatsByMarketplace($marketplace);

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Комиссии маркетплейса
     * GET /api/v2/unit-economics/commissions/{marketplace}
     *
     * Использует новые доменные классы
     */
    public function commissions(string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);

        if ($marketplace === 'wildberries' && request()->integer('integration_id') > 0) {
            $integrationId = request()->integer('integration_id');
            $resolution = $this->integrationAccessService->ensureAccessibleIntegration(
                request(),
                $integrationId,
                $marketplace
            );
            if (! ($resolution['success'] ?? false)) {
                return response()->json([
                    'message' => $resolution['message'] ?? 'Интеграция не найдена',
                    'errors' => [
                        'integration_id' => [$resolution['message'] ?? 'Интеграция не найдена'],
                    ],
                ], $resolution['status'] ?? 404);
            }

            $rows = WildberriesTariffSnapshot::query()
                ->where('integration_id', $integrationId)
                ->where('tariff_type', 'commission')
                ->orderByDesc('effective_date')
                ->get();

            if ($rows->isNotEmpty()) {
                return response()->json([
                    'data' => [
                        'marketplace' => $marketplace,
                        'source' => 'wildberries_tariff_snapshots',
                        'integration_id' => $integrationId,
                        'categories' => $rows->map(fn ($row) => [
                            'subject_id' => $row->subject_id,
                            'subject_name' => $row->subject_name,
                            'scheme' => $row->scheme,
                            'commission' => (float) data_get($row->payload, 'percent', 0),
                            'effective_date' => optional($row->effective_date)->toDateString(),
                        ])->values(),
                    ],
                ]);
            }
        }

        $commissionCalculator = $this->getCommissionCalculator($marketplace);

        if ($commissionCalculator) {
            $isWildberriesFallback = $marketplace === 'wildberries';

            return response()->json([
                'data' => [
                    'marketplace' => $marketplace,
                    'source' => $isWildberriesFallback ? 'wildberries_legacy_static_fallback' : 'domain_static_fallback',
                    'deprecated' => $isWildberriesFallback,
                    'message' => $isWildberriesFallback
                        ? 'Передайте integration_id и выполните синхронизацию тарифов, чтобы получить комиссии из WB API snapshots.'
                        : null,
                    'categories' => $commissionCalculator->getAllCommissions(),
                    'acquiring_rate' => $commissionCalculator->getAcquiringRate(),
                ],
            ]);
        }

        // Fallback
        $commissions = $this->unitEconomicsService->getCommissions($marketplace);

        return response()->json(['data' => $commissions]);
    }

    /**
     * Тарифы маркетплейса
     * GET /api/v2/unit-economics/tariffs/{marketplace}
     *
     * Использует новые доменные классы
     */
    public function tariffs(string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);

        if ($marketplace === 'wildberries' && request()->integer('integration_id') > 0) {
            $integrationId = request()->integer('integration_id');
            $resolution = $this->integrationAccessService->ensureAccessibleIntegration(
                request(),
                $integrationId,
                $marketplace
            );
            if (! ($resolution['success'] ?? false)) {
                return response()->json([
                    'message' => $resolution['message'] ?? 'Интеграция не найдена',
                    'errors' => [
                        'integration_id' => [$resolution['message'] ?? 'Интеграция не найдена'],
                    ],
                ], $resolution['status'] ?? 404);
            }

            $rows = WildberriesTariffSnapshot::query()
                ->where('integration_id', $integrationId)
                ->whereIn('tariff_type', ['box', 'pallet', 'return', 'acceptance'])
                ->orderByDesc('effective_date')
                ->get();

            if ($rows->isNotEmpty()) {
                return response()->json([
                    'data' => [
                        'marketplace' => $marketplace,
                        'source' => 'wildberries_tariff_snapshots',
                        'integration_id' => $integrationId,
                        'tariffs' => $rows->groupBy('tariff_type')->map(fn ($items) => $items->values())->toArray(),
                    ],
                ]);
            }
        }

        $tariffsProvider = $this->getTariffsProvider($marketplace);
        $schemes = $this->orchestrator->getSupportedSchemes($marketplace);

        if ($tariffsProvider && ! empty($schemes)) {
            $isWildberriesFallback = $marketplace === 'wildberries';
            $tariffs = [];
            foreach ($schemes as $scheme) {
                $tariffs[$scheme] = $tariffsProvider->getLogisticsTariffs($scheme);
            }

            return response()->json([
                'data' => [
                    'marketplace' => $marketplace,
                    'source' => $isWildberriesFallback ? 'wildberries_legacy_static_fallback' : 'domain_static_fallback',
                    'deprecated' => $isWildberriesFallback,
                    'message' => $isWildberriesFallback
                        ? 'Передайте integration_id и выполните синхронизацию тарифов, чтобы получить тарифы из WB API snapshots.'
                        : null,
                    'schemes' => $schemes,
                    'tariffs' => $tariffs,
                ],
            ]);
        }

        // Fallback
        $tariffs = $this->unitEconomicsService->getTariffs($marketplace);

        return response()->json(['data' => $tariffs]);
    }

    /**
     * Получить калькулятор комиссий для маркетплейса
     */
    private function getCommissionCalculator(string $marketplace): ?object
    {
        return match ($marketplace) {
            'wildberries' => app(\App\Domains\Wildberries\Tariffs\CommissionCalculator::class),
            'ozon' => app(\App\Domains\Ozon\Tariffs\CommissionCalculator::class),
            'yandex_market', 'yandex' => app(\App\Domains\YandexMarket\Tariffs\CommissionCalculator::class),
            default => null,
        };
    }

    /**
     * Получить провайдер тарифов для маркетплейса
     */
    private function getTariffsProvider(string $marketplace): ?object
    {
        return match ($marketplace) {
            'wildberries' => app(\App\Domains\Wildberries\Tariffs\WildberriesTariffs::class),
            'ozon' => app(\App\Domains\Ozon\Tariffs\OzonTariffs::class),
            'yandex_market', 'yandex' => app(\App\Domains\YandexMarket\Tariffs\YandexMarketTariffs::class),
            default => null,
        };
    }

    /**
     * Сравнение товаров между маркетплейсами
     * GET /api/v2/unit-economics/product-comparison
     */
    public function productComparison(Request $request): JsonResponse
    {
        $integrationId = $request->input('integration_id');

        $comparison = $this->unitEconomicsService->getProductComparison(
            $integrationId ? (int) $integrationId : null
        );

        return response()->json([
            'data' => $comparison,
        ]);
    }

    /**
     * Синхронизация юнит-экономики (в фоне)
     * POST /api/v2/unit-economics/sync/{integrationId}
     */
    public function sync(Request $request, int $integrationId): JsonResponse
    {
        $integration = Integration::find($integrationId);

        if (! $integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integration not found',
            ], 404);
        }

        $periodStart = $request->input('period_start');
        $periodEnd = $request->input('period_end');

        SyncUnitEconomicsJob::dispatch($integrationId, $periodStart, $periodEnd);

        return response()->json([
            'success' => true,
            'message' => 'Unit economics sync started',
            'integration_id' => $integrationId,
        ]);
    }

    /**
     * Синхронная синхронизация
     * POST /api/v2/unit-economics/sync-now/{integrationId}
     */
    public function syncNow(Request $request, int $integrationId): JsonResponse
    {
        $integration = Integration::find($integrationId);

        if (! $integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integration not found',
            ], 404);
        }

        $periodStart = $request->input('period_start');
        $periodEnd = $request->input('period_end');

        try {
            $result = $this->unitEconomicsService->syncFromRealData(
                $integration,
                $periodStart,
                $periodEnd
            );

            return response()->json([
                'success' => true,
                'message' => 'Unit economics synced successfully',
                'synced' => $result['synced'],
                'errors' => $result['errors'],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Массовое сохранение
     * POST /api/v2/unit-economics/save
     */
    public function save(Request $request): JsonResponse
    {
        $items = $request->all();

        if (! is_array($items) || empty($items)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data format. Expected array of items.',
            ], 400);
        }

        try {
            $result = $this->unitEconomicsService->bulkSave($items);

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить информацию о схеме для фронтенда
     *
     * @param  string  $scheme  Код схемы (FBO, FBS, DBS, EDBS, DBW)
     * @param  string  $marketplace  Маркетплейс
     */
    private function getSchemeInfo(string $scheme, string $marketplace): array
    {
        if ($marketplace === 'wildberries') {
            return match ($scheme) {
                'FBO', 'FBW' => [
                    'code' => 'FBO',
                    'name' => 'Склад WB',
                    'full_name' => 'FBW — Склад Wildberries',
                    'logistics_by' => 'wildberries',
                    'storage_by' => 'wildberries',
                    'has_wb_logistics' => true,
                    'has_warehouse_coef' => true,
                    'has_localization_index' => true,
                ],
                'FBS' => [
                    'code' => 'FBS',
                    'name' => 'Ваш склад, лог. WB',
                    'full_name' => 'FBS — Ваш склад, логистика Wildberries',
                    'logistics_by' => 'wildberries',
                    'storage_by' => 'seller',
                    'has_wb_logistics' => true,
                    'has_warehouse_coef' => true,
                    'has_localization_index' => true,
                ],
                'DBS' => [
                    'code' => 'DBS',
                    'name' => 'Своя доставка',
                    'full_name' => 'DBS — Своя доставка',
                    'logistics_by' => 'seller',
                    'storage_by' => 'seller',
                    'has_wb_logistics' => false,
                    'has_warehouse_coef' => false,
                    'has_localization_index' => false,
                ],
                'EDBS' => [
                    'code' => 'EDBS',
                    'name' => 'Экспресс своя',
                    'full_name' => 'EDBS — Экспресс своя доставка',
                    'logistics_by' => 'seller',
                    'storage_by' => 'seller',
                    'has_wb_logistics' => false,
                    'has_warehouse_coef' => false,
                    'has_localization_index' => false,
                ],
                'DBW' => [
                    'code' => 'DBW',
                    'name' => 'Курьер WB от вас',
                    'full_name' => 'DBW — Курьер Wildberries от вас',
                    'logistics_by' => 'wildberries',
                    'storage_by' => 'seller',
                    'has_wb_logistics' => true,
                    'has_warehouse_coef' => true,
                    'has_localization_index' => true,
                ],
                default => [
                    'code' => $scheme,
                    'name' => $scheme,
                    'full_name' => $scheme,
                    'logistics_by' => 'unknown',
                    'storage_by' => 'unknown',
                    'has_wb_logistics' => false,
                    'has_warehouse_coef' => false,
                    'has_localization_index' => false,
                ],
            };
        }

        // Ozon схемы
        if ($marketplace === 'ozon') {
            return match ($scheme) {
                'FBO' => [
                    'code' => 'FBO',
                    'name' => 'Склад Ozon',
                    'full_name' => 'FBO — Fulfillment by Ozon',
                    'logistics_by' => 'ozon',
                    'storage_by' => 'ozon',
                ],
                'FBS' => [
                    'code' => 'FBS',
                    'name' => 'Ваш склад',
                    'full_name' => 'FBS — Fulfillment by Seller',
                    'logistics_by' => 'ozon',
                    'storage_by' => 'seller',
                ],
                'RFBS' => [
                    'code' => 'RFBS',
                    'name' => 'Своя доставка',
                    'full_name' => 'RFBS — Real FBS (своя доставка)',
                    'logistics_by' => 'seller',
                    'storage_by' => 'seller',
                ],
                'EXPRESS' => [
                    'code' => 'EXPRESS',
                    'name' => 'Экспресс',
                    'full_name' => 'EXPRESS — Экспресс-доставка',
                    'logistics_by' => 'ozon',
                    'storage_by' => 'seller',
                ],
                default => [
                    'code' => $scheme,
                    'name' => $scheme,
                    'full_name' => $scheme,
                    'logistics_by' => 'unknown',
                    'storage_by' => 'unknown',
                ],
            };
        }

        if ($marketplace === 'yandex' || $marketplace === 'yandex_market') {
            return match ($scheme) {
                'FBY' => [
                    'code' => 'FBY',
                    'name' => 'Склад Маркета',
                    'full_name' => 'FBY — Fulfillment by Yandex',
                    'logistics_by' => 'yandex_market',
                    'storage_by' => 'yandex_market',
                ],
                'FBS' => [
                    'code' => 'FBS',
                    'name' => 'Ваш склад',
                    'full_name' => 'FBS — Fulfillment by Seller',
                    'logistics_by' => 'yandex_market',
                    'storage_by' => 'seller',
                ],
                'DBS' => [
                    'code' => 'DBS',
                    'name' => 'Своя доставка',
                    'full_name' => 'DBS — Delivery by Seller',
                    'logistics_by' => 'seller',
                    'storage_by' => 'seller',
                ],
                'EXPRESS' => [
                    'code' => 'EXPRESS',
                    'name' => 'Экспресс',
                    'full_name' => 'EXPRESS — Экспресс-доставка',
                    'logistics_by' => 'yandex_market',
                    'storage_by' => 'seller',
                ],
                default => [
                    'code' => $scheme,
                    'name' => $scheme,
                    'full_name' => $scheme,
                    'logistics_by' => 'unknown',
                    'storage_by' => 'unknown',
                ],
            };
        }

        return [
            'code' => $scheme,
            'name' => $scheme,
            'full_name' => $scheme,
            'logistics_by' => 'unknown',
            'storage_by' => 'unknown',
        ];
    }

    private function normalizeMarketplace(string $marketplace): string
    {
        return match (strtolower($marketplace)) {
            'wb' => 'wildberries',
            'yandex', 'yandex_market' => 'yandex_market',
            default => strtolower($marketplace),
        };
    }

    private function humanizeOzonMarkupReason(?string $reason, ?int $sales7Days = null): ?string
    {
        return match ($reason) {
            'local_cluster' => 'Надбавка не применяется: товар продаётся в локальном кластере',
            'fbo_lt_50_orders_7d' => 'Надбавка не применяется: за 7 дней по FBO меньше 50 заказов'
                .($sales7Days !== null ? " ({$sales7Days})" : ''),
            'non_fbo_no_nonlocal_markup' => 'Надбавка за нелокальность применяется только к FBO',
            'no_markup_for_cluster' => 'Надбавка не применяется: для этого кластера ставка Ozon равна 0%',
            'non_local_markup_applied' => 'Надбавка применяется: продажа идёт вне локального кластера',
            default => null,
        };
    }

    private function normalizeOzonClusterMarkupData(
        array $clustersSummary,
        array $salesProfile,
        array $stockProfile,
        bool $markupAllowed,
        string $markupDisabledReason = 'fbo_lt_50_orders_7d'
    ): array {
        $salesClusters = is_array($salesProfile['clusters'] ?? null) ? $salesProfile['clusters'] : $salesProfile;
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

        $summaryLookup = [];
        foreach ($clustersSummary as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }

            $clusterId = isset($cluster['cluster_id']) && $cluster['cluster_id'] !== '' ? (string) $cluster['cluster_id'] : null;
            $clusterNameKey = $this->normalizeClusterKey($cluster['cluster_name'] ?? null);

            if ($clusterId !== null) {
                $summaryLookup['id:' . $clusterId] = $cluster;
            }

            if ($clusterNameKey !== null) {
                $summaryLookup['name:' . $clusterNameKey] = $cluster;
            }
        }

        $enrichCluster = function (array $cluster) use ($pricing, $summaryLookup, $stockClusterCanonical, $markupAllowed, $markupDisabledReason): array {
            $clusterId = isset($cluster['cluster_id']) && $cluster['cluster_id'] !== '' ? (string) $cluster['cluster_id'] : null;
            $clusterName = $cluster['cluster_name'] ?? null;
            $clusterNameKey = $this->normalizeClusterKey($clusterName);

            $matched = [];
            if ($clusterId !== null && isset($summaryLookup['id:' . $clusterId])) {
                $matched = $summaryLookup['id:' . $clusterId];
            } elseif ($clusterNameKey !== null && isset($summaryLookup['name:' . $clusterNameKey])) {
                $matched = $summaryLookup['name:' . $clusterNameKey];
            }

            $resolvedName = $clusterName ?? $matched['cluster_name'] ?? null;
            $resolvedRoute = $pricing->resolveRoute(null, is_string($resolvedName) ? $resolvedName : null);
            $canonicalName = is_string($resolvedName) ? $pricing->resolveClusterName($resolvedName) : null;
            $isLocalCluster = $canonicalName !== null && in_array($canonicalName, $stockClusterCanonical, true);
            $nonLocalMarkupPercent = $pricing->resolveDestinationMarkupPercent(
                is_string($resolvedName) ? $resolvedName : null,
                $pricing->getEffectiveFrom()
            );
            $effectiveMarkupPercent = (! $markupAllowed || $isLocalCluster) ? 0.0 : $nonLocalMarkupPercent;
            $markupReason = $cluster['markup_reason']
                ?? $matched['markup_reason']
                ?? (! $markupAllowed
                    ? $markupDisabledReason
                    : ($isLocalCluster ? 'local_cluster' : ($nonLocalMarkupPercent > 0 ? 'non_local_markup_applied' : 'no_markup_for_cluster')));

            return array_merge($matched, $cluster, [
                'cluster_id' => $clusterId ?? ($matched['cluster_id'] ?? null),
                'cluster_name' => $resolvedName,
                'is_local_cluster' => $isLocalCluster,
                'route_key' => $cluster['route_key'] ?? $matched['route_key'] ?? $resolvedRoute['route_key'] ?? null,
                'route_label' => $cluster['route_label'] ?? $matched['route_label'] ?? $resolvedRoute['route_label'] ?? null,
                'non_local_markup_percent' => $nonLocalMarkupPercent,
                'effective_markup_percent' => $effectiveMarkupPercent,
                'markup_reason' => $markupReason,
            ]);
        };

        return [
            array_map($enrichCluster, $clustersSummary),
            array_map($enrichCluster, $salesClusters),
        ];
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
     * GET /api/unit-economics/freshness/{integrationId}
     *
     * Возвращает "светофор" свежести данных юнит-экономики для UI.
     * Фронт вызывает при открытии страницы и поллит каждые 2–3 секунды
     * во время sync, чтобы показать юзеру:
     *   - данные свежие (только что пересчитаны)
     *   - данные устарели (последний sync был давно)
     *   - идёт пересчёт прямо сейчас (и какой этап)
     */
    public function freshness(int $integrationId): JsonResponse
    {
        $integration = Integration::find($integrationId);
        if (! $integration) {
            return response()->json([
                'success' => false,
                'message' => 'Интеграция не найдена',
            ], 404);
        }

        // Максимальный updated_at в кэше — момент когда данные реально обновились.
        $cacheLastUpdated = \Illuminate\Support\Facades\DB::table('unit_economics_cache')
            ->where('integration_id', $integrationId)
            ->max('updated_at');
        $cacheRowsCount = UnitEconomicsCache::where('integration_id', $integrationId)->count();

        // Последний завершённый sync products/inventory + идущие сейчас.
        $latestSync = \Illuminate\Support\Facades\DB::table('sync_logs')
            ->where('integration_id', $integrationId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['sync_type', 'status', 'items_synced', 'metadata', 'started_at', 'completed_at', 'created_at']);

        $stagesBySyncType = [];
        foreach ($latestSync as $log) {
            // Берём самый свежий лог на каждый тип sync.
            if (! isset($stagesBySyncType[$log->sync_type])) {
                $stagesBySyncType[$log->sync_type] = $log;
            }
        }

        // Проверяем очереди: идёт ли сейчас пересчёт UE / локальности.
        $queueUe = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('queue', 'unit-economics')
            ->where('payload', 'like', '%"integrationId":'.$integrationId.'%')
            ->count();
        $queueLocality = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('queue', 'locality')
            ->where('payload', 'like', '%"integrationId":'.$integrationId.'%')
            ->count();

        // Собираем стадии.
        $products = $stagesBySyncType['products'] ?? null;
        $inventory = $stagesBySyncType['inventory'] ?? null;

        $productsStage = $this->buildStageInfo($products, 'products');
        $inventoryStage = $this->buildStageInfo($inventory, 'inventory');

        $ueStage = [
            'status' => $queueUe > 0 ? 'running' : ($cacheRowsCount > 0 ? 'completed' : 'pending'),
            'last_updated_at' => $cacheLastUpdated,
            'rows' => $cacheRowsCount,
        ];

        // Снапшот локальности
        $localityLast = \Illuminate\Support\Facades\DB::table('locality_metrics_daily')
            ->where('integration_id', $integrationId)
            ->max('updated_at');
        $localitySnapshotDate = \Illuminate\Support\Facades\DB::table('locality_metrics_daily')
            ->where('integration_id', $integrationId)
            ->max('snapshot_date');
        $localityStage = [
            'status' => $queueLocality > 0
                ? 'running'
                : ($localityLast ? 'completed' : 'pending'),
            'last_updated_at' => $localityLast,
            'snapshot_date' => $localitySnapshotDate,
        ];

        // Общий светофор.
        $anyRunning = in_array('running', [$productsStage['status'], $inventoryStage['status'], $ueStage['status'], $localityStage['status']], true);
        $allCompleted = $cacheRowsCount > 0
            && $productsStage['status'] === 'completed'
            && $inventoryStage['status'] === 'completed'
            && $ueStage['status'] === 'completed'
            && ! $anyRunning;

        $freshnessColor = 'gray';
        $freshnessLabel = 'Нет данных';
        if ($anyRunning) {
            $freshnessColor = 'yellow';
            $freshnessLabel = 'Обновляется…';
        } elseif ($cacheLastUpdated) {
            $ageMinutes = now()->diffInMinutes(\Carbon\Carbon::parse($cacheLastUpdated));
            if ($ageMinutes < 60) {
                $freshnessColor = 'green';
                $freshnessLabel = $this->humanAge($ageMinutes);
            } elseif ($ageMinutes < 60 * 24) {
                $freshnessColor = 'green';
                $freshnessLabel = $this->humanAge($ageMinutes);
            } elseif ($ageMinutes < 60 * 24 * 3) {
                $freshnessColor = 'yellow';
                $freshnessLabel = 'Данные старше суток — рекомендуем синхронизировать';
            } else {
                $freshnessColor = 'red';
                $freshnessLabel = 'Данные устарели, нажмите «Синхронизировать»';
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'integration_id' => $integrationId,
                'overall' => [
                    'color' => $freshnessColor,
                    'label' => $freshnessLabel,
                    'is_fresh' => $allCompleted,
                    'is_updating' => $anyRunning,
                    'last_updated_at' => $cacheLastUpdated,
                ],
                'stages' => [
                    'products' => $productsStage,
                    'inventory' => $inventoryStage,
                    'unit_economics' => $ueStage,
                    'locality' => $localityStage,
                ],
            ],
        ]);
    }

    private function buildStageInfo(?object $log, string $syncType): array
    {
        if (! $log) {
            return [
                'status' => 'idle',
                'items_synced' => 0,
                'total' => null,
                'progress_percent' => null,
                'started_at' => null,
                'completed_at' => null,
            ];
        }

        $meta = is_string($log->metadata) ? json_decode($log->metadata, true) : [];
        $total = $meta['total_from_api'] ?? null;
        $items = (int) ($log->items_synced ?? 0);
        $progress = null;
        if ($total && $total > 0) {
            $progress = min(100, (int) round(($items / $total) * 100));
        } elseif ($log->status === 'completed') {
            $progress = 100;
        }

        return [
            'status' => $log->status,              // pending | running | completed | failed
            'items_synced' => $items,
            'total' => $total,
            'progress_percent' => $progress,
            'started_at' => $log->started_at,
            'completed_at' => $log->completed_at,
        ];
    }

    private function humanAge(int $minutes): string
    {
        if ($minutes < 1) {
            return 'Только что';
        }
        if ($minutes < 60) {
            return "Обновлено {$minutes} мин назад";
        }
        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return "Обновлено {$hours} ч назад";
        }
        $days = (int) floor($hours / 24);
        return "Обновлено {$days} д назад";
    }
}
