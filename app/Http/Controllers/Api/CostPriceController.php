<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\CostPriceParserService;
use App\Services\UnitEconomics\WildberriesSourceFreshnessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CostPriceController extends Controller
{
    private WildberriesSourceFreshnessService $sourceFreshness;

    public function __construct(
        private CostPriceParserService $parserService,
        ?WildberriesSourceFreshnessService $sourceFreshness = null,
    ) {
        $this->sourceFreshness = $sourceFreshness ?? app(WildberriesSourceFreshnessService::class);
    }

    /**
     * Загрузка файла себестоимости
     * POST /api/products/cost-price/upload
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:xlsx,xls,csv,txt',
        ]);

        $file = $request->file('file');

        $result = $this->parserService->parse($file);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Ошибка при разборе файла',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }

    /**
     * Список себестоимостей по integration_id
     * GET /api/products/cost-price
     */
    public function index(Request $request): JsonResponse
    {
        $integrationId = $request->query('integration_id');
        $perPage = min((int) ($request->query('per_page', 200)), 1000);
        $page = max(1, (int) ($request->query('page', 1)));

        $query = UnitEconomicsSettings::where('cost_price', '>', 0)
            ->select('sku', 'cost_price', 'integration_id');

        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        $items = $query->forPage($page, $perPage)->get()->map(fn($s) => [
            'sku' => $s->sku,
            'cost_price' => (float) $s->cost_price,
        ])->values()->toArray();

        return response()->json([
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Экспорт юнит-экономики по nmID для внешних сервисов (репрайсер).
     * Джойнит себестоимость (unit_economics_settings по sku=баркод) с товаром WB,
     * чтобы отдать себестоимость, комиссию, налог и рассчитанный СПП
     * с ключом nmID, а не баркодом.
     * GET /api/products/unit-economics/export?integration_id=X
     */
    public function unitEconomicsExport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|integer|min:1|exists:integrations,id',
        ]);
        $integrationId = (int) $validated['integration_id'];
        $generatedAt = now()->utc();
        $sourceEvidence = $this->sourceFreshness->freshEvidence($integrationId, $generatedAt);

        $items = $this->unitEconomicsRows($integrationId)
            ->map(fn ($row): array => $this->unitEconomicsItem($row, $generatedAt, $sourceEvidence))
            ->filter(fn (array $item): bool => $item['nm_id'] > 0)
            ->groupBy('nm_id')
            ->map(fn ($variants): array => $this->conservativeUnitEconomicsItem($variants))
            ->sortBy('nm_id')
            ->values();

        return response()->json([
            'schema_version' => 2,
            'source' => 'sellico-products-unit-economics',
            'generated_at' => $generatedAt->toRfc3339String(),
            'integration_id' => $integrationId,
            'complete' => true,
            'items' => $items,
        ]);
    }

    /**
     * Строгая проверка готовности реальной юнит-экономики для денежных действий.
     * Любая неполнота, fallback-источник или устаревший расчёт возвращается как
     * блокирующее состояние; endpoint никогда не подставляет бизнес-значения.
     * POST /api/products/unit-economics/readiness
     */
    public function unitEconomicsReadiness(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|integer|min:1|exists:integrations,id',
            'wb_product_ids' => 'required|array|min:1|max:100',
            'wb_product_ids.*' => 'required|integer|min:1|distinct',
        ]);
        $integrationId = (int) $validated['integration_id'];
        $requested = collect($validated['wb_product_ids'])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $checkedAt = now()->utc();
        $sourceEvidence = $this->sourceFreshness->freshEvidence($integrationId, $checkedAt);

        $items = $this->unitEconomicsRows($integrationId)
            ->map(fn ($row): array => $this->unitEconomicsItem($row, $checkedAt, $sourceEvidence))
            ->filter(fn (array $item): bool => $requested->contains($item['nm_id']))
            ->groupBy('nm_id')
            ->map(fn ($variants): array => $this->conservativeUnitEconomicsItem($variants))
            ->keyBy('nm_id');

        $missing = [];
        $unprofitable = [];
        $stale = [];
        $readinessItems = [];
        foreach ($requested as $nmId) {
            $item = $items->get($nmId);
            if ($item === null) {
                $missing[] = $nmId;
                $readinessItems[] = [
                    'nm_id' => $nmId,
                    'status' => 'missing',
                    'reasons' => ['unit_economics_not_found'],
                    'observed_at' => null,
                    'max_allowed_drr_percent' => null,
                ];
                continue;
            }

            $reasons = $item['readiness_reasons'];
            $isStale = in_array('stale_calculation', $reasons, true);
            $isUnprofitable = in_array('unprofitable', $reasons, true);
            if ($isStale) {
                $stale[] = $nmId;
            }
            if ($isUnprofitable) {
                $unprofitable[] = $nmId;
            }
            if (! $item['ready'] && ! $isStale && ! $isUnprofitable) {
                $missing[] = $nmId;
            }

            $readinessItems[] = [
                'nm_id' => $nmId,
                'status' => $item['ready'] ? 'ready' : ($isStale ? 'stale' : ($isUnprofitable ? 'unprofitable' : 'incomplete')),
                'reasons' => $reasons,
                'observed_at' => $item['calculated_at'],
                'max_allowed_drr_percent' => $item['max_allowed_drr'],
            ];
        }

        return response()->json([
            'schema_version' => 1,
            'source' => 'sellico-products-unit-economics',
            'integration_id' => (string) $integrationId,
            'checked_at' => $checkedAt->toRfc3339String(),
            'complete' => true,
            'checked_product_ids' => $requested,
            'missing_economics_product_ids' => array_values(array_unique($missing)),
            'unprofitable_product_ids' => array_values(array_unique($unprofitable)),
            'stale_product_ids' => array_values(array_unique($stale)),
            'items' => $readinessItems,
        ]);
    }

    private function unitEconomicsRows(int $integrationId)
    {
        return Product::query()
            ->where('products.integration_id', $integrationId)
            ->where('products.marketplace', 'wildberries')
            ->leftJoin('unit_economics_settings as settings', function ($join) {
                $join->on('settings.integration_id', '=', 'products.integration_id')
                    ->on('settings.sku', '=', 'products.sku');
            })
            ->leftJoin('unit_economics_settings as vendor_settings', function ($join) {
                $join->on('vendor_settings.integration_id', '=', 'products.integration_id')
                    ->on('vendor_settings.sku', '=', 'products.vendor_code');
            })
            ->leftJoin('unit_economics as calculated', function ($join) {
                $join->on('calculated.integration_id', '=', 'products.integration_id')
                    ->on('calculated.sku', '=', 'products.sku')
                    ->where('calculated.is_actual_scheme', '=', true);
            })
            ->get([
                'products.marketplace_id',
                'products.wb_data',
                DB::raw('COALESCE(NULLIF(settings.cost_price, 0), NULLIF(vendor_settings.cost_price, 0)) AS cost_price'),
                DB::raw('COALESCE(settings.tax_percent, vendor_settings.tax_percent) AS tax_percent'),
                'calculated.price',
                'calculated.customer_price',
                'calculated.spp_percent',
                'calculated.commission_percent',
                'calculated.effective_logistics',
                'calculated.net_profit',
                'calculated.net_profit_per_unit',
                'calculated.margin_percent',
                'calculated.drr_percent',
                'calculated.drr_amount',
                'calculated.sales_count',
                'calculated.tariff_source',
                'calculated.redemption_source',
                'calculated.redemption_observed_at',
                'calculated.dimensions_observed_at',
                'calculated.acquiring_observed_at',
                'calculated.marketplace_data',
                'calculated.updated_at as calculated_at',
            ]);
    }

    /**
     * Fold all SKU/size variants of one WB nmID into a fail-closed economics
     * record. Profitability fields use the least profitable real variant and
     * readiness is true only when every variant is ready.
     */
    private function conservativeUnitEconomicsItem($variants): array
    {
        $variants = collect($variants)->values();
        $base = $variants
            ->sortByDesc(fn (array $item): string => (string) ($item['calculated_at'] ?? ''))
            ->first();

        foreach (['price', 'customer_price', 'net_profit', 'margin_percent', 'margin_before_ads', 'max_allowed_drr'] as $field) {
            $values = $variants->pluck($field)->filter(fn ($value): bool => $value !== null);
            $base[$field] = $values->isEmpty() ? null : $values->min();
        }
        foreach (['cost_price', 'logistics_cost', 'other_costs', 'commission_percent', 'tax_percent'] as $field) {
            $values = $variants->pluck($field)->filter(fn ($value): bool => $value !== null);
            $base[$field] = $values->isEmpty() ? null : $values->max();
        }

        $timestamps = $variants->pluck('calculated_at')->filter()->sort()->values();
        $base['calculated_at'] = $timestamps->first();
        $base['ready'] = $variants->every(fn (array $item): bool => $item['ready'] === true);
        $base['readiness_reasons'] = $variants
            ->flatMap(fn (array $item): array => $item['readiness_reasons'])
            ->unique()
            ->values()
            ->all();
        $base['variant_count'] = $variants->count();

        return $base;
    }

    /** @param array{commission:?\Illuminate\Support\Carbon,tariff:?\Illuminate\Support\Carbon} $sourceEvidence */
    private function unitEconomicsItem($row, $checkedAt, array $sourceEvidence): array
    {
        $wbData = is_array($row->wb_data) ? $row->wb_data : (json_decode((string) $row->wb_data, true) ?: []);
        $marketplaceData = is_array($row->marketplace_data)
            ? $row->marketplace_data
            : (json_decode((string) $row->marketplace_data, true) ?: []);
        $nmId = (int) ($wbData['nmID'] ?? $wbData['nmId'] ?? 0);
        if ($nmId <= 0 && $row->marketplace_id) {
            $nmId = (int) strtok((string) $row->marketplace_id, ':');
        }

        $number = static fn ($value): ?float => $value === null ? null : (float) $value;
        $price = $number($row->price);
        $costPrice = $number($row->cost_price);
        $commissionPercent = $number($row->commission_percent);
        $taxPercent = $number($row->tax_percent);
        $logisticsCost = $number($row->effective_logistics);
        $netProfit = $number($row->net_profit_per_unit ?? $row->net_profit);
        $marginPercent = $number($row->margin_percent);
        $drrPercent = $number($row->drr_percent);
        // A missing current advertising DRR is not synthetic zero: keep it null
        // in the response. Margin is already calculated without advertising in
        // that case, so it is itself the real pre-ads break-even ceiling.
        $maxAllowedDrr = $marginPercent !== null
            ? round(max(0, min(100, $marginPercent + ($drrPercent ?? 0))), 2)
            : null;
        $marginBeforeAds = ($price !== null && $maxAllowedDrr !== null)
            ? round($price * $maxAllowedDrr / 100, 2)
            : null;

        $otherCosts = null;
        if ($price !== null && $costPrice !== null && $logisticsCost !== null && $commissionPercent !== null && $taxPercent !== null && $marginBeforeAds !== null) {
            $commissionAmount = $price * $commissionPercent / 100;
            $taxAmount = $price * $taxPercent / 100;
            $otherCosts = round(max(0, $price - $costPrice - $logisticsCost - $commissionAmount - $taxAmount - $marginBeforeAds), 2);
        }

        $commissionSource = trim((string) ($marketplaceData['commission_source'] ?? ''));
        $tariffSource = trim((string) ($row->tariff_source ?? $marketplaceData['tariff_source'] ?? ''));
        $redemptionSource = trim((string) ($row->redemption_source ?? $marketplaceData['redemption_source'] ?? ''));
        $dimensionsSource = trim((string) ($marketplaceData['dimensions_source'] ?? ''));
        $acquiringSource = trim((string) ($marketplaceData['acquiring_source'] ?? ''));
        $calculatedAt = $row->calculated_at ? \Illuminate\Support\Carbon::parse($row->calculated_at)->utc() : null;
        $sourceObservedAt = [
            'redemption' => $this->sourceFreshness->observedAt($row->redemption_observed_at),
            'dimensions' => $this->sourceFreshness->observedAt($row->dimensions_observed_at),
            'acquiring' => $this->sourceFreshness->observedAt($row->acquiring_observed_at),
        ];

        $reasons = [];
        foreach ([
            'price' => $price,
            'cost_price' => $costPrice,
            'commission' => $commissionPercent,
            'tax' => $taxPercent,
            'logistics' => $logisticsCost,
            'profit' => $netProfit,
        ] as $field => $value) {
            if ($value === null || (in_array($field, ['price', 'cost_price'], true) && $value <= 0)) {
                $reasons[] = 'missing_' . $field;
            }
        }
        if ($maxAllowedDrr === null) {
            $reasons[] = 'missing_max_allowed_drr';
        } elseif ($maxAllowedDrr <= 0) {
            $reasons[] = 'unprofitable';
        }
        if ($calculatedAt === null || $calculatedAt->lt($checkedAt->copy()->subHours(72))) {
            $reasons[] = 'stale_calculation';
        }
        if ($netProfit !== null && $netProfit <= 0) {
            $reasons[] = 'unprofitable';
        }
        foreach ([
            'commission' => $commissionSource,
            'tariff' => $tariffSource,
            'redemption' => $redemptionSource,
            'dimensions' => $dimensionsSource,
            'acquiring' => $acquiringSource,
        ] as $sourceName => $source) {
            $normalized = strtolower($source);
            if ($normalized === '' || str_contains($normalized, 'default') || str_contains($normalized, 'fallback')) {
                $reasons[] = 'untrusted_' . $sourceName . '_source';
            }
        }
        $trustedWbSources = [
            'redemption' => ['wb_sales_funnel', 'api_orders_sku_sales', 'manual'],
            'dimensions' => ['wb_storage_api_volume', 'wb_product_characteristics'],
            'acquiring' => ['wb_realization_report_sku', 'wb_realization_report_store_avg'],
        ];
        foreach ([
            'redemption' => $redemptionSource,
            'dimensions' => $dimensionsSource,
            'acquiring' => $acquiringSource,
        ] as $sourceName => $source) {
            $normalized = strtolower($source);
            if (! in_array($normalized, $trustedWbSources[$sourceName], true)) {
                $reasons[] = 'untrusted_' . $sourceName . '_source';
                continue;
            }
            // Explicit user input is allowed by the real-data policy. Automated
            // marketplace observations, however, must carry their own fresh time.
            if ($normalized !== 'manual' && ! $this->sourceFreshness->isFresh($sourceObservedAt[$sourceName], $checkedAt)) {
                $reasons[] = 'stale_' . $sourceName . '_source';
            }
        }
        if (str_starts_with(strtolower($commissionSource), 'wb_commission_snapshot_') && $sourceEvidence['commission'] === null) {
            $reasons[] = 'stale_commission_source';
        }
        if (in_array(strtolower($tariffSource), ['wb_tariffs_api', 'wildberries_tariff_snapshots'], true) && $sourceEvidence['tariff'] === null) {
            $reasons[] = 'stale_tariff_source';
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'nm_id' => $nmId,
            'price' => $price,
            'customer_price' => $number($row->customer_price),
            'cost_price' => $costPrice,
            'logistics_cost' => $logisticsCost,
            'other_costs' => $otherCosts,
            'commission_percent' => $commissionPercent,
            'tax_percent' => $taxPercent,
            'spp_percent' => $number($row->spp_percent),
            'net_profit' => $netProfit,
            'margin_percent' => $marginPercent,
            'drr_percent' => $drrPercent,
            'margin_before_ads' => $marginBeforeAds,
            'max_allowed_drr' => $maxAllowedDrr,
            'calculated_at' => $calculatedAt?->toRfc3339String(),
            'ready' => $reasons === [],
            'readiness_reasons' => $reasons,
            'sources' => [
                'cost' => 'unit_economics_settings',
                'price' => 'unit_economics',
                'commission' => $commissionSource ?: null,
                'logistics' => $tariffSource ?: null,
                'tax' => 'unit_economics_settings',
                'redemption' => $redemptionSource ?: null,
                'dimensions' => $dimensionsSource ?: null,
                'acquiring' => $acquiringSource ?: null,
            ],
            'source_observed_at' => [
                'commission' => $sourceEvidence['commission']?->toRfc3339String(),
                'tariff' => $sourceEvidence['tariff']?->toRfc3339String(),
                'redemption' => $sourceObservedAt['redemption']?->toRfc3339String(),
                'dimensions' => $sourceObservedAt['dimensions']?->toRfc3339String(),
                'acquiring' => $sourceObservedAt['acquiring']?->toRfc3339String(),
            ],
        ];
    }

    /**
     * Массовое обновление себестоимости по SKU
     * POST /api/products/cost-price/bulk
     */
    public function bulk(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.cost_price' => 'required|numeric|min:0',
            'integration_id' => 'nullable|integer',
            'marketplace_id' => 'nullable|integer',
        ]);

        $items = collect($request->input('items'))
            ->mapWithKeys(function (array $item): array {
                $sku = trim((string) ($item['sku'] ?? ''));

                return $sku === '' ? [] : [$sku => (float) $item['cost_price']];
            });
        // Фронт исторически шлёт marketplace_id (это id интеграции) — принимаем оба.
        $integrationId = $request->integer('integration_id') ?: $request->integer('marketplace_id') ?: null;

        $updated = 0;
        $notFound = [];
        $skus = $items->keys()->values();

        if ($skus->isEmpty()) {
            return response()->json([
                'success' => false,
                'error' => 'Нет строк с артикулом',
            ], 422);
        }

        $productsByInputSku = $this->findProductsForCostPriceBulk($skus->all(), $integrationId);

        DB::transaction(function () use ($items, $productsByInputSku, &$updated, &$notFound) {
            foreach ($items as $inputSku => $costPrice) {
                $products = $productsByInputSku[$inputSku] ?? collect();

                if ($products->isEmpty()) {
                    $notFound[] = $inputSku;
                    continue;
                }

                foreach ($products->unique('id') as $product) {
                    $product->forceFill(['cost_price' => $costPrice])->save();

                    $settingsSkus = collect([
                        $inputSku,
                        $product->sku,
                        $product->vendor_code,
                        $product->barcode,
                    ])
                        ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                        ->map(fn (string $value): string => trim($value))
                        ->unique()
                        ->values();

                    foreach ($settingsSkus as $settingsSku) {
                        UnitEconomicsSettings::updateOrCreate(
                            [
                                'integration_id' => $product->integration_id,
                                'sku' => $settingsSku,
                            ],
                            ['cost_price' => $costPrice]
                        );
                    }

                    UnitEconomics::where('integration_id', $product->integration_id)
                        ->where('sku', $product->sku)
                        ->update(['cost_price' => $costPrice]);

                    UnitEconomicsCache::where('integration_id', $product->integration_id)
                        ->where(function ($query) use ($product) {
                            $query->where('product_id', $product->id)
                                ->orWhere('sku', $product->sku);
                        })
                        ->update(['cost_price' => $costPrice]);

                    $this->syncFinancialCostPrice(
                        $settingsSkus->all(),
                        $costPrice,
                        (int) $product->integration_id
                    );
                }

                $updated += $products->unique('id')->count();
            }
        });

        return response()->json([
            'success' => true,
            'data' => [
                'updated' => $updated,
                'not_found' => $notFound,
            ],
            'message' => "Обновлено товаров: {$updated}",
        ]);
    }

    /**
     * @param  list<string>  $skus
     * @return array<string, \Illuminate\Support\Collection<int, Product>>
     */
    private function findProductsForCostPriceBulk(array $skus, ?int $integrationId): array
    {
        $skus = collect($skus)
            ->map(fn (string $sku): string => trim($sku))
            ->filter()
            ->unique()
            ->values();
        $result = $skus->mapWithKeys(fn (string $sku): array => [$sku => collect()])->all();

        foreach ($skus->chunk(500) as $chunk) {
            $products = Product::query()
                ->when($integrationId, fn (Builder $query) => $query->where('integration_id', $integrationId))
                ->where(function (Builder $query) use ($chunk) {
                    $values = $chunk->all();
                    $query->whereIn('sku', $values)
                        ->orWhereIn('marketplace_id', $values)
                        ->orWhereIn('vendor_code', $values)
                        ->orWhereIn('barcode', $values);
                })
                ->get();

            foreach ($products as $product) {
                foreach ($this->productCostPriceLookupKeys($product) as $key) {
                    if ($chunk->contains($key)) {
                        $result[$key]->push($product);
                    }
                }
            }
        }

        $missing = collect($result)
            ->filter(fn ($products): bool => $products->isEmpty())
            ->keys()
            ->values();

        // Редкий fallback для старых записей, где артикул продавца остался только в JSON.
        $jsonExpressions = $this->jsonLookupExpressions();
        if ($jsonExpressions === []) {
            return $result;
        }

        foreach ($missing->chunk(100) as $chunk) {
            $products = Product::query()
                ->when($integrationId, fn (Builder $query) => $query->where('integration_id', $integrationId))
                ->where(function (Builder $query) use ($chunk, $jsonExpressions) {
                    foreach ($jsonExpressions as $expression) {
                        $query->orWhereIn($expression, $chunk->all());
                    }
                })
                ->get();

            foreach ($products as $product) {
                foreach ($this->productCostPriceLookupKeys($product) as $key) {
                    if (isset($result[$key]) && $chunk->contains($key)) {
                        $result[$key]->push($product);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function productCostPriceLookupKeys(Product $product): array
    {
        $wbData = is_array($product->wb_data) ? $product->wb_data : [];
        $ozonData = is_array($product->ozon_data) ? $product->ozon_data : [];
        $yandexData = is_array($product->yandex_data) ? $product->yandex_data : [];

        return collect([
            $product->sku,
            $product->marketplace_id,
            $product->vendor_code,
            $product->barcode,
            $wbData['vendorCode'] ?? null,
            $ozonData['offer_id'] ?? null,
            $ozonData['vendor_code'] ?? null,
            $yandexData['offerId'] ?? null,
            $yandexData['shopSku'] ?? null,
        ])
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<Expression>
     */
    private function jsonLookupExpressions(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return [
                DB::raw("wb_data->>'vendorCode'"),
                DB::raw("ozon_data->>'offer_id'"),
                DB::raw("ozon_data->>'vendor_code'"),
                DB::raw("yandex_data->>'offerId'"),
                DB::raw("yandex_data->>'shopSku'"),
            ];
        }

        if ($driver === 'mysql') {
            return [
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(wb_data, '$.vendorCode'))"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(ozon_data, '$.offer_id'))"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(ozon_data, '$.vendor_code'))"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(yandex_data, '$.offerId'))"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(yandex_data, '$.shopSku'))"),
            ];
        }

        return [];
    }

    /**
     * @param  list<string>  $articles
     */
    private function syncFinancialCostPrice(array $articles, float $costPrice, int $integrationId): void
    {
        try {
            if (! Schema::connection('financial')->hasColumn('products', 'integration_id')) {
                Log::warning('Financial dashboard cost_price sync skipped: tenant key is absent', [
                    'integration_id' => $integrationId,
                ]);

                return;
            }

            DB::connection('financial')
                ->table('products')
                ->where('integration_id', $integrationId)
                ->whereIn('article', $articles)
                ->update(['cost_price' => $costPrice]);
        } catch (\Throwable $e) {
            Log::warning('Financial dashboard cost_price sync skipped', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Скачивание шаблона файла себестоимости
     * GET /api/products/cost-price/template
     */
    public function template(Request $request): \Illuminate\Http\Response
    {
        $marketplace   = $request->query('marketplace', '');
        $integrationId = $request->query('marketplace_id') ?? $request->query('integration_id');

        $headers = ['Артикул продавца', 'Себестоимость'];
        $rows    = [];

        if ($integrationId) {
            $query = Product::where('integration_id', $integrationId)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->select('sku', 'vendor_code', 'name', 'cost_price', 'wb_data');

            if ($marketplace) {
                $query->where('marketplace', $marketplace);
            }

            $products = $query->orderBy('vendor_code')->orderBy('sku')->limit(5000)->get();

            foreach ($products as $product) {
                $costPrice = (float) $product->cost_price;
                $rows[] = [
                    $this->costPriceTemplateArticle($product),
                    $costPrice > 0 ? number_format($costPrice, 2, '.', '') : '',
                ];
            }
        }

        $escapeCsv = static function (string $value): string {
            $needsQuotes = str_contains($value, ';')
                || str_contains($value, '"')
                || str_contains($value, "\n")
                || str_contains($value, "\r");

            return $needsQuotes ? '"' . str_replace('"', '""', $value) . '"' : $value;
        };
        $csvLines = [implode(';', array_map($escapeCsv, $headers))];
        foreach ($rows as $row) {
            $csvLines[] = implode(';', array_map(fn ($value) => $escapeCsv((string) $value), $row));
        }
        $csv = "\xEF\xBB\xBF" . implode("\r\n", $csvLines) . "\r\n";

        $filename = $marketplace
            ? "шаблон_себестоимость_{$marketplace}.csv"
            : 'шаблон_себестоимость.csv';
        $asciiFilename = $marketplace
            ? "cost-price-template-{$marketplace}.csv"
            : 'cost-price-template.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function costPriceTemplateArticle(Product $product): string
    {
        $wbData = is_array($product->wb_data) ? $product->wb_data : [];

        foreach ([
            $product->vendor_code,
            $wbData['vendorCode'] ?? null,
            $product->sku,
        ] as $value) {
            $article = trim((string) ($value ?? ''));
            if ($article !== '') {
                return $article;
            }
        }

        return '';
    }
}
