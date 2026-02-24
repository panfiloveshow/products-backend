<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsSettings;
use App\Services\CostPriceParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);

        $items = $request->input('items');
        $integrationId = $request->input('integration_id');

        $updated = 0;
        $notFound = [];

        foreach ($items as $item) {
            $sku = $item['sku'];
            $costPrice = $item['cost_price'];

            // Ищем товар по артикулу продавца (vendorCode/offer_id) или sku/barcode
            $query = Product::where(function ($q) use ($sku) {
                $q->where('sku', $sku)
                  ->orWhere('marketplace_id', $sku)
                  ->orWhereRaw("wb_data->>'vendorCode' = ?", [$sku])
                  ->orWhereRaw("ozon_data->>'offer_id' = ?", [$sku])
                  ->orWhereRaw("ozon_data->>'vendor_code' = ?", [$sku]);
            });

            if ($integrationId) {
                $query->where('integration_id', $integrationId);
            }

            $products = $query->get();

            if ($products->isEmpty()) {
                $notFound[] = $sku;
                continue;
            }

            foreach ($products as $product) {
                // Обновляем products.cost_price
                $product->update(['cost_price' => $costPrice]);

                // Записываем по артикулу продавца (входящий sku) — это то что хранится в unit_economics_settings
                UnitEconomicsSettings::updateOrCreate(
                    [
                        'integration_id' => $product->integration_id,
                        'sku' => $sku,
                    ],
                    ['cost_price' => $costPrice]
                );

                // Также по product.sku (штрихкод) на случай если там тоже есть записи в settings
                if ($product->sku !== $sku) {
                    UnitEconomicsSettings::where('integration_id', $product->integration_id)
                        ->where('sku', $product->sku)
                        ->update(['cost_price' => $costPrice]);
                }

                // Обновляем unit_economics таблицу (используется для расчётов и отображения в UE странице)
                UnitEconomics::where('integration_id', $product->integration_id)
                    ->where('sku', $product->sku)
                    ->update(['cost_price' => $costPrice]);
            }

            $updated += $products->count();
        }

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
     * Скачивание шаблона файла себестоимости
     * GET /api/products/cost-price/template
     */
    public function template(Request $request): \Illuminate\Http\Response
    {
        $marketplace = $request->query('marketplace', '');

        $headers = ['Артикул продавца', 'Себестоимость'];
        $rows = [
            ['АРТИКУЛ-001', '1500.00'],
            ['АРТИКУЛ-002', '2300.50'],
            ['АРТИКУЛ-003', '890.00'],
        ];

        $csv = implode(';', $headers) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(';', $row) . "\n";
        }

        $filename = $marketplace
            ? "шаблон_себестоимость_{$marketplace}.csv"
            : 'шаблон_себестоимость.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
