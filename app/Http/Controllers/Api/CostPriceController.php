<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\CostPriceParserService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CostPriceController extends Controller
{
    public function __construct(
        private CostPriceParserService $parserService
    ) {}

    /**
     * Загрузка файла себестоимости
     * POST /api/products/cost-price/upload
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $file = $request->file('file');

        $result = $this->parserService->parse($file);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Ошибка при разборе файла',
                'details' => $result['details'] ?? null,
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
        ]);

        $items = collect($request->input('items'))
            ->mapWithKeys(function (array $item): array {
                $sku = trim((string) ($item['sku'] ?? ''));

                return $sku === '' ? [] : [$sku => (float) $item['cost_price']];
            });
        $integrationId = $request->integer('integration_id') ?: null;

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

                    $this->syncFinancialCostPrice($settingsSkus->all(), $costPrice);
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
    private function syncFinancialCostPrice(array $articles, float $costPrice): void
    {
        try {
            DB::connection('financial')
                ->table('products')
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
                ->select('sku', 'name', 'cost_price');

            if ($marketplace) {
                $query->where('marketplace', $marketplace);
            }

            $products = $query->orderBy('sku')->limit(5000)->get();

            foreach ($products as $product) {
                $costPrice = (float) $product->cost_price;
                $rows[] = [
                    $product->sku,
                    $costPrice > 0 ? number_format($costPrice, 2, '.', '') : '',
                ];
            }
        }

        if (empty($rows)) {
            $rows = [
                ['АРТИКУЛ-001', ''],
                ['АРТИКУЛ-002', ''],
                ['АРТИКУЛ-003', ''],
            ];
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
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
