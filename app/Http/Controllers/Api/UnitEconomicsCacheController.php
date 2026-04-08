<?php

namespace App\Http\Controllers\Api;

use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Http\Controllers\Controller;
use App\Http\Requests\UnitEconomics\CalculateRequest;
use App\Jobs\SyncUnitEconomicsJob;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\IntegrationAccessService;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomicsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
            'margin_min' => 'nullable|numeric',
            'margin_max' => 'nullable|numeric',
            'price_min' => 'nullable|numeric',
            'price_max' => 'nullable|numeric',
            'sort' => 'nullable|string|in:sku,product_name,price,net_profit,margin_percent,commission_percent,stock,total_stock,current_stock,days_of_stock',
            'sort_order' => 'nullable|string|in:asc,desc',
            'limit' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
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

        $query = UnitEconomicsCache::query()
            ->forIntegration($validated['integration_id'])
            ->forMarketplace($marketplace)
            ->forScheme($validated['fulfillment_type'])
            ->search($validated['search'] ?? null)
            ->profitable($validated['profitable'] ?? null)
            ->marginRange($validated['margin_min'] ?? null, $validated['margin_max'] ?? null)
            ->priceRange($validated['price_min'] ?? null, $validated['price_max'] ?? null);

        // Сортировка
        $sortField = $validated['sort'] ?? 'sku';
        $sortOrder = $validated['sort_order'] ?? 'asc';
        $this->applyUnitEconomicsSorting($query, $sortField, $sortOrder);

        // Пагинация
        $limit = $validated['limit'] ?? 50;
        $page = $validated['page'] ?? 1;
        $paginator = $query->with('product')->paginate($limit, ['*'], 'page', $page);

        $itemsCollection = collect($paginator->items());
        $settingsMap = collect();
        if ($itemsCollection->isNotEmpty()) {
            $settingsMap = UnitEconomicsSettings::where('integration_id', $validated['integration_id'])
                ->whereIn('sku', $itemsCollection->pluck('sku')->unique())
                ->get()
                ->keyBy('sku');
        }

        $pageContext = $this->buildWildberriesUnitEconomicsPageContext($marketplace, $itemsCollection);

        // Обогащаем данные полями из Product для совместимости с v1
        $items = $itemsCollection->map(function ($cache) use ($validated, $settingsMap, $pageContext) {
            $settings = $settingsMap->get($cache->sku);

            return $this->enrichCacheItem($cache, $validated['fulfillment_type'], $settings, $pageContext);
        })->toArray();

        // Статистика по схемам
        $schemeCounts = $this->getSchemeCounts($validated['integration_id'], $marketplace);

        // Реальная схема работы магазина (для подсветки)
        $actualScheme = $this->getActualScheme($validated['integration_id'], $marketplace);

        // default_scheme = actual_scheme (схема по умолчанию для выбора таба)
        $defaultScheme = $actualScheme ?? match ($marketplace) {
            'yandex', 'yandex_market' => 'FBY',
            default => 'FBO',
        };

        // Общая статистика
        $stats = $this->getStats($validated['integration_id'], $marketplace, $validated['fulfillment_type']);

        return response()->json([
            'data' => [
                'items' => $items,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
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
        string $sortOrder
    ): void {
        $sortOrder = strtolower($sortOrder) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortField, ['stock', 'total_stock', 'current_stock', 'days_of_stock'], true)) {
            $inventoryTotals = InventoryWarehouse::query()
                ->from('inventory_warehouses as inventory_rows')
                ->join('products as inventory_products', function ($join) {
                    $join->on('inventory_products.integration_id', '=', 'inventory_rows.integration_id')
                        ->where(function ($condition) {
                            $condition->whereColumn('inventory_rows.sku', 'inventory_products.sku')
                                ->orWhereColumn('inventory_rows.sku', 'inventory_products.barcode')
                                ->orWhereColumn('inventory_rows.sku', 'inventory_products.vendor_code');
                        });
                })
                ->selectRaw('inventory_products.id as product_id, COALESCE(SUM(inventory_rows.quantity), 0) as total_stock, COALESCE(SUM(inventory_rows.average_daily_sales), 0) as total_ads')
                ->groupBy('inventory_products.id');

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

        $query->orderBy($sortField, $sortOrder);
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

        $cache = UnitEconomicsCache::where('integration_id', $validated['integration_id'])
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

        $data = $cache->toArray();
        if ($marketplace === 'ozon') {
            unset($data['avg_delivery_time_hours'], $data['logistics_coefficient'], $data['additional_commission_percent']);
        }

        $allSchemes = UnitEconomicsCache::where('integration_id', $validated['integration_id'])
            ->where('sku', $sku)
            ->get()
            ->map(function (UnitEconomicsCache $item) use ($marketplace) {
                $row = $item->toArray();
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
            'redemption_rate' => 'nullable|numeric|min:0|max:100',
            'redemption_rate_override' => 'nullable|numeric|min:0|max:100',
            // WB-специфичные
            'spp_percent' => 'nullable|numeric|min:0|max:100',
            // ИЛ (индекс локализации) — хранится на уровне интеграции, но принимаем здесь для удобства
            'localization_index' => 'nullable|numeric|min:0.50|max:2.50',
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
        unset($validated['integration_id'], $validated['localization_index']);

        if (array_key_exists('redemption_rate', $validated) && ! array_key_exists('redemption_rate_override', $validated)) {
            $validated['redemption_rate_override'] = $validated['redemption_rate'];
        }
        unset($validated['redemption_rate']);

        // Если передан localization_index — обновляем интеграцию (это настройка магазина, не товара)
        if ($localizationIndex !== null) {
            Integration::where('id', $integrationId)->update([
                'localization_index' => $localizationIndex,
            ]);
        }

        // Обновляем или создаём настройки товара
        $settings = UnitEconomicsSettings::updateOrCreate(
            ['integration_id' => $integrationId, 'sku' => $sku],
            array_filter($validated, fn ($v) => $v !== null)
        );

        // Триггерим пересчёт кэша
        $this->cacheService->onSettingsChanged($integrationId, $sku);

        // Возвращаем обновлённые данные
        $cache = UnitEconomicsCache::where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->get()
            ->keyBy('fulfillment_type');

        // Получаем актуальный localization_index из интеграции
        $integration = Integration::find($integrationId);

        return response()->json([
            'message' => 'Settings updated and cache recalculated',
            'settings' => $settings,
            'localization_index' => (float) ($integration->localization_index ?? 1.0),
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
            'items.*.redemption_rate' => 'nullable|numeric|min:0|max:100',
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

            if (array_key_exists('redemption_rate', $item) && ! array_key_exists('redemption_rate_override', $item)) {
                $item['redemption_rate_override'] = $item['redemption_rate'];
            }
            unset($item['redemption_rate']);

            UnitEconomicsSettings::updateOrCreate(
                ['integration_id' => $integrationId, 'sku' => $sku],
                array_filter($item, fn ($v) => $v !== null)
            );

            $skus[] = $sku;
        }

        // Триггерим пересчёт кэша для всех изменённых товаров
        $this->cacheService->onBulkSettingsChanged($integrationId, $skus);

        return response()->json([
            'message' => 'Bulk settings updated',
            'updated_count' => count($skus),
        ]);
    }

    /**
     * Принудительный пересчёт кэша для интеграции
     *
     * POST /api/v2/unit-economics/recalculate/{integrationId}
     */
    public function recalculate(int $integrationId): JsonResponse
    {
        $stats = $this->cacheService->recalculateIntegration($integrationId);

        // Проверяем на ошибку
        if (isset($stats['error'])) {
            return response()->json([
                'message' => $stats['error'],
            ], 404);
        }

        return response()->json([
            'message' => 'Пересчёт завершён',
            'stats' => [
                'total' => $stats['total'],
                'success' => $stats['success'],
                'errors' => $stats['errors'],
            ],
        ]);
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

        // Получаем ВСЕ записи без пагинации
        $items = UnitEconomicsCache::query()
            ->forIntegration($integrationId)
            ->forMarketplace($marketplace)
            ->forScheme($fulfillmentType)
            ->with('product')
            ->orderBy('sku')
            ->get();

        // Загружаем настройки пользователя
        $settingsMap = collect();
        if ($items->isNotEmpty()) {
            $settingsMap = UnitEconomicsSettings::where('integration_id', $integrationId)
                ->whereIn('sku', $items->pluck('sku')->unique())
                ->get()
                ->keyBy('sku');
        }

        $pageContext = $this->buildWildberriesUnitEconomicsPageContext($marketplace, $items);

        // Обогащаем данные
        $enrichedItems = $items->map(function ($cache) use ($fulfillmentType, $settingsMap, $pageContext) {
            $settings = $settingsMap->get($cache->sku);

            return $this->enrichCacheItem($cache, $fulfillmentType, $settings, $pageContext);
        })->toArray();

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
        $filename = "unit-economics-{$marketplace}-{$fulfillmentType}-{$date}.xlsx";

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
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
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Юнит-экономика');

        // ── Определения колонок (A-Y, 25 колонок) ──
        $columns = [
            'A' => ['header' => 'Артикул', 'width' => 15, 'field' => 'sku', 'format' => '@'],
            'B' => ['header' => 'Наименование', 'width' => 40, 'field' => 'product_name', 'format' => '@'],
            'C' => ['header' => 'Цена, ₽', 'width' => 12, 'field' => 'price', 'format' => '#,##0.00'],
            'D' => ['header' => 'Себестоимость, ₽', 'width' => 14, 'field' => 'cost_price', 'format' => '#,##0.00'],
            'E' => ['header' => 'Наценка, x', 'width' => 11, 'field' => 'markup_percent', 'format' => '0.00'],
            'F' => ['header' => 'Комиссия, %', 'width' => 11, 'field' => 'commission_percent', 'format' => '0.00"%"'],
            'G' => ['header' => 'Комиссия, ₽', 'width' => 12, 'field' => 'commission_amount', 'format' => '#,##0.00'],
            'H' => ['header' => 'Логистика, ₽', 'width' => 12, 'field' => 'logistics_cost', 'format' => '#,##0.00'],
            'I' => ['header' => 'Посл. миля, ₽', 'width' => 12, 'field' => 'last_mile_cost', 'format' => '#,##0.00'],
            'J' => ['header' => 'Доставка, ₽', 'width' => 12, 'field' => 'formula_delivery', 'format' => '#,##0.00'],
            'K' => ['header' => 'Кластер поставки', 'width' => 20, 'field' => 'cluster', 'format' => '@'],
            'L' => ['header' => 'Локальность', 'width' => 14, 'field' => 'locality', 'format' => '@'],
            'M' => ['header' => 'Нелок. наценка, %', 'width' => 15, 'field' => 'non_local_markup_percent', 'format' => '0.00"%"'],
            'N' => ['header' => '% выкупа', 'width' => 10, 'field' => 'redemption_rate', 'format' => '0.00"%"'],
            'O' => ['header' => 'Эквайринг, %', 'width' => 12, 'field' => 'acquiring_percent', 'format' => '0.00"%"'],
            'P' => ['header' => 'РК, %', 'width' => 8, 'field' => 'drr_percent', 'format' => '0.00"%"'],
            'Q' => ['header' => 'Наша часть, %', 'width' => 12, 'field' => 'our_share_percent', 'format' => '0.00"%"'],
            'R' => ['header' => 'Налог, %', 'width' => 9, 'field' => 'tax_percent', 'format' => '0.00"%"'],
            'S' => ['header' => 'НДС, %', 'width' => 8, 'field' => 'vat_percent', 'format' => '0.00"%"'],
            'T' => ['header' => 'Хранение, ₽', 'width' => 12, 'field' => 'storage_cost', 'format' => '#,##0.00'],
            'U' => ['header' => 'Итого затраты, ₽', 'width' => 14, 'field' => 'total_costs', 'format' => '#,##0.00'],
            'V' => ['header' => 'Прибыль, ₽', 'width' => 12, 'field' => 'net_profit', 'format' => '#,##0.00'],
            'W' => ['header' => 'Маржа, %', 'width' => 10, 'field' => 'margin_percent', 'format' => '0.00"%"'],
            'X' => ['header' => 'ROI, %', 'width' => 10, 'field' => 'roi_percent', 'format' => '0.00"%"'],
            'Y' => ['header' => 'На р/с, ₽', 'width' => 12, 'field' => 'to_settlement_account', 'format' => '#,##0.00'],
        ];

        $lastCol = 'Y';

        // ── Ширины колонок ──
        foreach ($columns as $col => $def) {
            $sheet->getColumnDimension($col)->setWidth($def['width']);
        }

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
        $textColumns = ['A', 'B', 'K', 'L'];

        foreach ($items as $item) {
            $isEvenRow = ($currentRow - $dataStartRow) % 2 === 1;

            foreach ($columns as $col => $def) {
                $field = $def['field'];

                if ($col === 'J') {
                    // J: Доставка — Excel-формула =H+I
                    $sheet->setCellValue("J{$currentRow}", "=H{$currentRow}+I{$currentRow}");
                } elseif ($col === 'K') {
                    // K: Кластер поставки
                    $sheet->setCellValue("K{$currentRow}", $item['shipping_cluster_name'] ?? $item['route_label'] ?? '');
                } elseif ($col === 'L') {
                    // L: Локальность
                    $sheet->setCellValue("L{$currentRow}", $this->resolveLocalityLabel($item));
                } elseif (in_array($col, $textColumns)) {
                    // Текстовые поля (A, B)
                    $sheet->setCellValue("{$col}{$currentRow}", $item[$field] ?? '');
                } else {
                    // Числовые поля
                    $sheet->setCellValue("{$col}{$currentRow}", (float) ($item[$field] ?? 0));
                }
            }

            // Чередование цвета строк: белый / светло-зелёный
            if ($isEvenRow) {
                $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'f0fdf4'],
                    ],
                ]);
            }

            // Красный цвет для отрицательной прибыли (V)
            $netProfit = (float) ($item['net_profit'] ?? 0);
            if ($netProfit < 0) {
                $sheet->getStyle("V{$currentRow}")->applyFromArray([
                    'font' => ['color' => ['rgb' => 'dc2626']],
                ]);
            }

            // Красный цвет для отрицательной маржи (W)
            $marginPercent = (float) ($item['margin_percent'] ?? 0);
            if ($marginPercent < 0) {
                $sheet->getStyle("W{$currentRow}")->applyFromArray([
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

        // SUM формулы для денежных колонок
        $sumCols = ['C', 'G', 'H', 'I', 'J', 'T', 'U', 'V', 'Y'];
        foreach ($sumCols as $col) {
            if ($dataEndRow >= $dataStartRow) {
                $sheet->setCellValue("{$col}{$summaryRow}", "=SUM({$col}{$dataStartRow}:{$col}{$dataEndRow})");
            } else {
                $sheet->setCellValue("{$col}{$summaryRow}", 0);
            }
        }

        // AVERAGE формулы для процентных/относительных колонок
        $avgCols = ['E', 'F', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'W', 'X'];
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

        // ── Заморозка панелей ──
        $sheet->freezePane('A5');

        return $spreadsheet;
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

        $localityRate = $item['expected_locality_rate'] ?? null;
        if ($localityRate !== null) {
            return 'Оценка ' . round((float) $localityRate) . '%';
        }

        if ($isLocal === false) {
            return 'Не локальная';
        }

        return '';
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
            // Сначала пробуем из unit_economics (более точные данные по остаткам)
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
                    SUM(net_profit) as total_profit
                ')
                ->first();

            return [
                'total_count' => (int) ($stats->total_count ?? 0),
                'profitable_count' => (int) ($stats->profitable_count ?? 0),
                'unprofitable_count' => (int) ($stats->unprofitable_count ?? 0),
                'avg_margin' => round((float) ($stats->avg_margin ?? 0), 2),
                'total_revenue' => round((float) ($stats->total_revenue ?? 0), 2),
                'total_profit' => round((float) ($stats->total_profit ?? 0), 2),
            ];
        });
    }

    /**
     * Одна выборка складов и интеграций на страницу (WB), чтобы не бить N+1 в enrichCacheItem.
     *
     * @return array{
     *   wb_warehouses_by_key: Collection,
     *   wb_warehouses_by_product_key: Collection,
     *   integrations_by_id: Collection
     * }|null
     */
    private function buildWildberriesUnitEconomicsPageContext(string $marketplace, Collection $itemsCollection): ?array
    {
        if ($marketplace !== 'wildberries' || $itemsCollection->isEmpty()) {
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
            ->where('marketplace', 'wildberries')
            ->get([
                'id',
                'warehouse_id',
                'warehouse_name',
                'warehouse_coefficient',
                'quantity',
                'average_daily_sales',
                'last_updated',
                'sku',
                'integration_id',
            ]);

        $wbWarehousesByKey = $warehouseRows->groupBy(fn ($w) => $w->sku.'|'.$w->integration_id);
        $wbWarehousesByProductKey = $itemsCollection->mapWithKeys(function (UnitEconomicsCache $cache) use ($wbWarehousesByKey) {
            $lookupKeys = $this->resolveInventoryLookupKeys($cache, $cache->product);
            $warehouseItems = collect($lookupKeys)
                ->flatMap(fn (string $lookupKey) => $wbWarehousesByKey->get($lookupKey.'|'.$cache->integration_id, collect()))
                ->unique('id')
                ->values();

            return [($cache->product_id ?? $cache->sku).'|'.$cache->integration_id => $warehouseItems];
        });

        $integrationsById = Integration::query()
            ->whereIn('id', $integrationIds)
            ->get()
            ->keyBy('id');

        return [
            'wb_warehouses_by_key' => $wbWarehousesByKey,
            'wb_warehouses_by_product_key' => $wbWarehousesByProductKey,
            'integrations_by_id' => $integrationsById,
        ];
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

        // Получаем реальную схему товара из Product (там актуальные данные из Ozon API)
        $realScheme = $product?->fulfillment_type ?? match ($cache->marketplace) {
            'yandex', 'yandex_market' => 'FBY',
            default => 'FBO',
        };

        // Базовые данные из кэша
        $data = $cache->toArray();

        // Убираем relation чтобы не дублировать
        unset($data['product']);

        // Добавляем поля для совместимости с v1
        $data['actual_weight'] = $product ? (float) ($product->weight ?? 0) / 1000 : 0;
        $data['turnover_days'] = $product?->turnover_days ?? 30;

        // Габариты как объект
        $data['dimensions'] = [
            'length' => $cache->depth ? number_format((float) $cache->depth, 2, '.', '') : null,
            'width' => $cache->width ? number_format((float) $cache->width, 2, '.', '') : null,
            'height' => $cache->height ? number_format((float) $cache->height, 2, '.', '') : null,
            'weight' => $cache->weight ? number_format((float) $cache->weight, 2, '.', '') : null,
            'volume' => $cache->volume_liters ? number_format((float) $cache->volume_liters, 4, '.', '') : null,
        ];

        // Комиссии по схемам из ozon_data
        $data['commissions'] = $commissions;

        // Данные выкупа из ozon_data
        $data['redemption'] = $redemption;

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
            if ($redemptionRate !== null) {
                $returnBase = (float) $cache->return_logistics_cost;
                if ($returnBase <= 0) {
                    $returnBase = $deliveryCost;
                }

                $rate = max(0, min(100, (float) $redemptionRate));
                $expectedReturnCost = $returnBase * ((100 - $rate) / 100);
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
            $activeFixation = is_array($ozonData['active_fixation'] ?? null) ? $ozonData['active_fixation'] : [];
            $orderEconomicsSummary = is_array($ozonData['order_economics_summary'] ?? null) ? $ozonData['order_economics_summary'] : [];
            $data['route_key'] = $cache->route_key
                ?? $marketplaceData['route_key']
                ?? ($activeFixation['shipping_cluster_id'] ?? null)
                ?? ($ozonData['route_key'] ?? null);
            $data['route_label'] = $cache->route_label
                ?? $marketplaceData['route_label']
                ?? ($activeFixation['shipping_cluster_name'] ?? null)
                ?? ($ozonData['route_label'] ?? null);
            $data['shipping_cluster_id'] = $marketplaceData['shipping_cluster_id']
                ?? ($activeFixation['shipping_cluster_id'] ?? null);
            $data['shipping_cluster_name'] = $marketplaceData['shipping_cluster_name']
                ?? ($activeFixation['shipping_cluster_name'] ?? null);
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
            $rawNonLocalMarkupPercent = $cache->non_local_markup_percent !== null
                ? round((float) $cache->non_local_markup_percent, 2)
                : null;
            $data['raw_non_local_markup_percent'] = $rawNonLocalMarkupPercent ?? 0;
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
            $cacheDerivedLocalityRate = $data['is_local_sale'] === null ? null : ($data['is_local_sale'] ? 100.0 : 0.0);
            $data['expected_locality_rate'] = $cacheDerivedLocalityRate
                ?? (isset($marketplaceData['expected_locality_rate'])
                    ? round((float) $marketplaceData['expected_locality_rate'], 2)
                    : (isset($ozonData['expected_locality_rate'])
                        ? round((float) $ozonData['expected_locality_rate'], 2)
                        : null));
            $data['weighted_non_local_markup_percent'] = $rawNonLocalMarkupPercent;
            if ($data['weighted_non_local_markup_percent'] === null) {
                $data['weighted_non_local_markup_percent'] = isset($marketplaceData['weighted_non_local_markup_percent'])
                    ? round((float) $marketplaceData['weighted_non_local_markup_percent'], 2)
                    : (isset($ozonData['weighted_non_local_markup_percent'])
                        ? round((float) $ozonData['weighted_non_local_markup_percent'], 2)
                        : $rawNonLocalMarkupPercent);
            }
            $data['non_local_markup_percent'] = $data['weighted_non_local_markup_percent'];
            $data['logistics_markup_percent'] = $data['weighted_non_local_markup_percent'];
            $data['non_local_markup_amount'] = round((float) $cache->price * ((float) $data['non_local_markup_percent'] / 100), 2);
            $data['logistics_markup_amount'] = $data['non_local_markup_amount'];
            $data['sales_7_days'] = isset($marketplaceData['sales_7_days'])
                ? (int) $marketplaceData['sales_7_days']
                : (isset($ozonData['sales_7_days']) ? (int) $ozonData['sales_7_days'] : null);
            // Пересчитываем markup_allowed на основе актуальных sales_7_days,
            // а не используем stale значение из кеша
            $isFboScheme = strtoupper($cache->fulfillment_type ?? '') === 'FBO';
            $sellerSales7Days = $data['sales_7_days'];
            $data['markup_allowed'] = !($isFboScheme && $sellerSales7Days !== null && $sellerSales7Days < 50);
            $data['markup_rule_reason'] = null; // Сбрасываем — пересчитаем ниже
            // Определяем причину нулевой наценки
            if ($data['markup_allowed'] === false) {
                $data['markup_rule_reason'] = 'fbo_lt_50_orders_7d';
                // Обнуляем наценку для отображения — Ozon не начисляет
                $data['non_local_markup_percent'] = 0;
                $data['logistics_markup_percent'] = 0;
                $data['non_local_markup_amount'] = 0;
                $data['logistics_markup_amount'] = 0;
            } elseif (($data['expected_locality_rate'] ?? null) !== null && (float) $data['expected_locality_rate'] >= 99.99) {
                $data['markup_rule_reason'] = 'local_cluster';
            }
            $data['markup_rule_reason_label'] = $this->humanizeOzonMarkupReason(
                is_string($data['markup_rule_reason'] ?? null) ? $data['markup_rule_reason'] : null,
                $data['sales_7_days'] ?? null
            );
            $data['profit_min'] = isset($marketplaceData['profit_min'])
                ? round((float) $marketplaceData['profit_min'], 2)
                : round((float) $cache->net_profit, 2);
            $data['profit_base'] = isset($marketplaceData['profit_base'])
                ? round((float) $marketplaceData['profit_base'], 2)
                : round((float) $cache->net_profit, 2);
            $data['profit_max'] = isset($marketplaceData['profit_max'])
                ? round((float) $marketplaceData['profit_max'], 2)
                : round((float) $cache->net_profit, 2);
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
        $lengthMm = $settings?->length_mm ?? $cache->depth ?? $product?->depth;
        $widthMm = $settings?->width_mm ?? $cache->width ?? $product?->width;
        $heightMm = $settings?->height_mm ?? $cache->height ?? $product?->height;
        $weightG = $settings?->weight_g ?? $cache->weight ?? $product?->weight;

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

            // СПП (скидка постоянного покупателя) — приоритет: настройки > wb_data > 0
            $sppPercent = (float) ($settings?->spp_percent ?? $wbData['spp_percent'] ?? $cache->spp_percent ?? 0);
            $data['spp_percent'] = $sppPercent;
            $data['spp_amount'] = round($price * $sppPercent / 100, 2);

            // Цена покупателя = цена × (1 - СПП%)
            $customerPrice = $price * (1 - $sppPercent / 100);
            $data['customer_price'] = round($customerPrice, 2);

            // Наценка, x = цена / себестоимость
            $data['markup_multiplier'] = $costPrice > 0 ? round($price / $costPrice, 2) : 0;

            // КС (коэффициент склада) — средний по всем складам товара
            // Получаем ВСЕ склады товара для детализации (включая с нулевыми остатками)
            $wbProductKey = ($cache->product_id ?? $cache->sku).'|'.$cache->integration_id;
            if ($pageContext !== null && isset($pageContext['wb_warehouses_by_product_key'])) {
                $warehouses = $pageContext['wb_warehouses_by_product_key']->get($wbProductKey, collect());
            } else {
                $warehouses = InventoryWarehouse::whereIn('sku', $this->resolveInventoryLookupKeys($cache, $product))
                    ->where('integration_id', $cache->integration_id)
                    ->where('marketplace', 'wildberries')
                    ->get(['warehouse_id', 'warehouse_name', 'warehouse_coefficient', 'quantity']);
            }

            // Для расчёта среднего КС используем только склады с остатками
            $warehousesWithStock = $warehouses->filter(fn ($w) => $w->quantity > 0);
            $totalQuantity = $warehousesWithStock->sum('quantity');
            $weightedCoefSum = 0;
            $warehouseDetails = [];

            // Детализация — показываем ВСЕ склады (даже без остатков)
            foreach ($warehouses as $wh) {
                $coef = (float) ($wh->warehouse_coefficient ?? 1.0);
                $qty = (int) $wh->quantity;

                // Для расчёта среднего учитываем только склады с остатками
                if ($qty > 0) {
                    $weightedCoefSum += $coef * $qty;
                }

                // Детализация для всплывающего окна — все склады
                // Проценты 100-значные: 1.0 = 100%, 1.4 = 140%, 2.05 = 205%
                $warehouseDetails[] = [
                    'warehouse_id' => $wh->warehouse_id,
                    'warehouse_name' => $wh->warehouse_name,
                    'coefficient_raw' => round($coef, 3),
                    'coefficient' => round($coef * 100, 0),
                    'quantity' => $qty,
                    'share_percent' => $totalQuantity > 0 && $qty > 0
                        ? round(($qty / $totalQuantity) * 100, 2)
                        : 0.0,
                ];
            }

            // Средний КС (взвешенный по количеству) — только по складам с остатками
            // Если остатков нет — берём простое среднее по всем складам
            if ($totalQuantity > 0) {
                $avgWarehouseCoef = $weightedCoefSum / $totalQuantity;
            } elseif ($warehouses->count() > 0) {
                // Нет остатков — простое среднее по всем складам
                $avgWarehouseCoef = $warehouses->avg(fn ($w) => (float) ($w->warehouse_coefficient ?? 1.0));
            } else {
                $avgWarehouseCoef = 1.0;
            }
            // Проценты 100-значные: 1.0 = 100%, 1.4 = 140%
            $warehouseCoefPercent = $avgWarehouseCoef * 100;

            $data['warehouse_coef_percent'] = round($warehouseCoefPercent, 0);
            $data['warehouse_coefficient'] = round($avgWarehouseCoef, 3);
            $data['warehouse_details'] = $warehouseDetails; // Детализация по складам для tooltip
            $baseLogistics = (float) $cache->base_logistics_cost;
            // Сумма надбавки КС = базовая логистика × (коэффициент - 1)
            $data['warehouse_coef_amount'] = round($baseLogistics * ($avgWarehouseCoef - 1), 2);

            // ИЛ (индекс локализации) — из интеграции (настройка магазина)
            if ($pageContext !== null && isset($pageContext['integrations_by_id'])) {
                $integration = $pageContext['integrations_by_id']->get($cache->integration_id);
            } else {
                $integration = Integration::find($cache->integration_id);
            }
            $localizationIndex = (float) ($cache->logistics_coefficient ?? $integration?->localization_index ?? 1.0);
            $data['localization_index'] = $localizationIndex;
            // Сумма надбавки/скидки ИЛ = базовая логистика × КС × (ИЛ - 1)
            $data['localization_amount'] = round($baseLogistics * $avgWarehouseCoef * ($localizationIndex - 1), 2);

            // Базовая логистика
            $data['base_logistics'] = round($baseLogistics, 2);

            // Обратная логистика
            $data['return_logistics'] = round((float) $cache->return_logistics_cost, 2);

            // Всего затрат, %
            $commissionAmount = $price * $commissionPercent / 100;
            $totalExpenses = $commissionAmount + (float) $cache->logistics_cost +
                             $expectedReturnCost + (float) $cache->storage_cost + (float) ($data['acquiring_amount'] ?? 0);
            $data['total_expenses_percent'] = $price > 0 ? round($totalExpenses / $price * 100, 2) : 0;

            // На р/с (to_settlement_account)
            $toSettlement = $customerPrice - $commissionAmount -
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

        if (
            $cache->marketplace === 'wildberries'
            && $pageContext !== null
            && isset($pageContext['wb_warehouses_by_product_key'])
        ) {
            $warehouses = $pageContext['wb_warehouses_by_product_key']->get(
                ($cache->product_id ?? $cache->sku).'|'.$cache->integration_id,
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
        $commissionCalculator = $this->getCommissionCalculator($marketplace);

        if ($commissionCalculator) {
            return response()->json([
                'data' => [
                    'marketplace' => $marketplace,
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
        $tariffsProvider = $this->getTariffsProvider($marketplace);
        $schemes = $this->orchestrator->getSupportedSchemes($marketplace);

        if ($tariffsProvider && ! empty($schemes)) {
            $tariffs = [];
            foreach ($schemes as $scheme) {
                $tariffs[$scheme] = $tariffsProvider->getLogisticsTariffs($scheme);
            }

            return response()->json([
                'data' => [
                    'marketplace' => $marketplace,
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
            'no_markup_for_cluster' => 'Надбавка не применяется: для этого кластера ставка Ozon равна 0%',
            'non_local_markup_applied' => 'Надбавка применяется: продажа идёт вне локального кластера',
            default => null,
        };
    }
}
