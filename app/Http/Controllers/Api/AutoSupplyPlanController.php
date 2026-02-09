<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoSupplyPlan\StoreAutoSupplyPlanRequest;
use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AutoSupplyPlanController extends Controller
{
    /**
     * GET /api/auto-supply-plans
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'nullable|integer',
            'status' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AutoSupplyPlan::with('integration')
            ->orderByDesc('created_at');

        if ($request->filled('integration_id')) {
            $query->where('integration_id', $request->input('integration_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = $request->input('per_page', 20);
        $plans = $query->paginate($perPage);

        return response()->json([
            'message' => 'OK',
            'data' => $plans,
        ]);
    }

    /**
     * POST /api/auto-supply-plans
     */
    public function store(StoreAutoSupplyPlanRequest $request): JsonResponse
    {
        $integration = Integration::findOrFail($request->input('integration_id'));

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => $integration->marketplace,
            'status' => AutoSupplyPlan::STATUS_PENDING,
            'mode' => $request->input('mode', 'balanced'),
            'horizon_days' => $request->input('horizon_days', 28),
            'min_cover_days' => $request->input('min_cover_days', 7),
            'target_cover_days' => $request->input('target_cover_days', 21),
            'max_cover_days' => $request->input('max_cover_days', 42),
            'safety_stock_days' => $request->input('safety_stock_days', 5),
            'turnover_limit_days' => $request->input('turnover_limit_days'),
            'budget_limit' => $request->input('budget_limit'),
            'forecast_model' => 'EWMA_0.35',
            'algorithm_version' => 'asp-1.0.0',
            'params' => [
                'target_days' => $request->input('target_cover_days', 21),
                'safety_days' => $request->input('safety_stock_days', 5),
                'lead_time_days' => $request->input('lead_time_days', 7),
                'ewma_alpha' => 0.35,
            ],
        ]);

        CalculateAutoSupplyPlanJob::dispatch($plan->id);

        return response()->json([
            'message' => 'План создан, расчёт запущен',
            'data' => $plan->load('integration'),
        ], 201);
    }

    /**
     * POST /api/auto-supply-plans/{id}/calculate
     */
    public function calculate(string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        // Удаляем старые строки
        $plan->lines()->delete();
        $plan->update([
            'status' => AutoSupplyPlan::STATUS_PENDING,
            'error_message' => null,
            'data_quality_score' => null,
            'data_quality_json' => null,
            'total_lines' => 0,
            'total_qty' => 0,
        ]);

        CalculateAutoSupplyPlanJob::dispatch($plan->id);

        return response()->json([
            'message' => 'Пересчёт запущен',
            'data' => $plan->fresh()->load('integration'),
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/lines
     */
    public function lines(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        $query = $plan->lines();

        if ($request->filled('risk_level')) {
            $query->where('risk_level', $request->input('risk_level'));
        }

        if ($request->filled('offer_id')) {
            $query->where('offer_id', $request->input('offer_id'));
        }

        $query->orderByRaw("CASE risk_level WHEN 'high' THEN 0 WHEN 'med' THEN 1 ELSE 2 END")
            ->orderByDesc('qty_rounded');

        $perPage = $request->input('per_page', 50);
        $lines = $query->paginate($perPage);

        return response()->json([
            'message' => 'OK',
            'data' => $lines,
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/simulate?offer_id=...&destination_id=...
     */
    public function simulate(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        $request->validate([
            'offer_id' => 'required|string',
            'destination_id' => 'nullable|string',
        ]);

        $query = $plan->lines()->where('offer_id', $request->input('offer_id'));

        if ($request->filled('destination_id')) {
            $query->where('destination_id', $request->input('destination_id'));
        }

        $line = $query->first();

        if (!$line) {
            return response()->json(['message' => 'Строка не найдена'], 404);
        }

        return response()->json([
            'message' => 'OK',
            'data' => [
                'line' => $line,
                'simulation' => $line->simulation_json,
                'explain' => $line->explain_json,
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::with('integration')->findOrFail($id);

        $perPage = $request->input('per_page', 50);
        $lines = $plan->lines()
            ->orderByRaw("CASE risk_level WHEN 'high' THEN 0 WHEN 'med' THEN 1 ELSE 2 END")
            ->orderByDesc('qty_rounded')
            ->paginate($perPage);

        return response()->json([
            'message' => 'OK',
            'data' => [
                'plan' => $plan,
                'lines' => $lines,
                'summary' => [
                    'total_lines' => $plan->total_lines,
                    'total_qty' => $plan->total_qty,
                    'data_quality_score' => $plan->data_quality_score,
                    'data_quality_json' => $plan->data_quality_json,
                    'risk_breakdown' => [
                        'high' => $plan->lines()->where('risk_level', 'high')->count(),
                        'med' => $plan->lines()->where('risk_level', 'med')->count(),
                        'low' => $plan->lines()->where('risk_level', 'low')->count(),
                    ],
                ],
            ],
        ]);
    }

    /**
     * DELETE /api/auto-supply-plans/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);
        $plan->lines()->delete();
        $plan->delete();

        return response()->json(['message' => 'План удалён']);
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/ozon
     *
     * Колонки: "артикул", "имя (необязательно)", "количество"
     * Группировка: SUM(qty_rounded) по offer_id
     */
    public function exportOzon(string $id): StreamedResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }

        $lines = $plan->lines()->get();

        // Группируем по offer_id
        $grouped = [];
        foreach ($lines as $line) {
            $offerId = $line->offer_id ?? $line->sku;
            if (empty($offerId) || $line->qty_rounded <= 0) {
                continue;
            }
            if (!isset($grouped[$offerId])) {
                $grouped[$offerId] = [
                    'offer_id' => $offerId,
                    'name' => $line->product_name,
                    'qty' => 0,
                ];
            }
            $grouped[$offerId]['qty'] += $line->qty_rounded;
        }

        // Убираем строки с итоговым qty < 1
        $grouped = array_filter($grouped, fn($item) => $item['qty'] >= 1);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ozon Supply');

        // Заголовки строго по шаблону Ozon
        $sheet->setCellValue('A1', 'артикул');
        $sheet->setCellValue('B1', 'имя (необязательно)');
        $sheet->setCellValue('C1', 'количество');

        $row = 2;
        foreach ($grouped as $item) {
            $sheet->setCellValue("A{$row}", $item['offer_id']);
            $sheet->setCellValue("B{$row}", $item['name'] ?? '');
            $sheet->setCellValue("C{$row}", $item['qty']);
            $row++;
        }

        $filename = "ozon_supply_plan_{$plan->id}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/wb
     *
     * Колонки: "Баркод", "Количество"
     * Группировка: SUM(qty_rounded) по barcode
     * Ошибка если barcode null или дубли
     */
    public function exportWb(string $id): StreamedResponse|JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }

        $lines = $plan->lines()->get();

        // Собираем все SKU для поиска barcode
        $skus = $lines->pluck('sku')->unique()->toArray();
        $products = Product::where('integration_id', $plan->integration_id)
            ->where('marketplace', 'wildberries')
            ->whereIn('sku', $skus)
            ->get(['sku', 'barcode'])
            ->keyBy('sku');

        $grouped = [];
        $errors = [];

        // Проверяем однозначность offer_id → barcode
        $skuToBarcodes = [];
        foreach ($lines as $line) {
            $product = $products->get($line->sku);
            $barcode = $product?->barcode ?? $line->barcode;
            if ($barcode) {
                $skuToBarcodes[$line->sku][$barcode] = true;
            }
        }

        foreach ($lines as $line) {
            if ($line->qty_rounded <= 0) {
                continue;
            }

            $product = $products->get($line->sku);
            $barcode = $product?->barcode ?? $line->barcode;

            if (empty($barcode)) {
                $errors[] = [
                    'sku' => $line->sku,
                    'product_name' => $line->product_name,
                    'error' => 'Баркод не найден',
                ];
                continue;
            }

            // Один offer_id → несколько баркодов (размеры)
            if (isset($skuToBarcodes[$line->sku]) && count($skuToBarcodes[$line->sku]) > 1) {
                $errors[] = [
                    'sku' => $line->sku,
                    'product_name' => $line->product_name,
                    'barcodes' => array_keys($skuToBarcodes[$line->sku]),
                    'error' => 'Несколько баркодов для одного SKU, нужна детализация',
                ];
                continue;
            }

            if (!isset($grouped[$barcode])) {
                $grouped[$barcode] = [
                    'barcode' => $barcode,
                    'qty' => 0,
                ];
            }
            $grouped[$barcode]['qty'] += $line->qty_rounded;
        }

        // Убираем строки с итоговым qty < 1
        $grouped = array_filter($grouped, fn($item) => $item['qty'] >= 1);

        // Сохраняем ошибки в план
        if (!empty($errors)) {
            $plan->update(['export_errors' => $errors]);
        }

        if (empty($grouped)) {
            return response()->json([
                'message' => 'Нет данных для экспорта WB',
                'errors' => $errors,
            ], 422);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('WB Supply');

        // Заголовки строго по шаблону WB
        $sheet->setCellValue('A1', 'Баркод');
        $sheet->setCellValue('B1', 'Количество');

        $row = 2;
        foreach ($grouped as $item) {
            $sheet->setCellValue("A{$row}", $item['barcode']);
            $sheet->setCellValue("B{$row}", $item['qty']);
            $row++;
        }

        $filename = "wb_supply_plan_{$plan->id}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * Стрим XLSX файла
     */
    private function streamXlsx(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
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
}
