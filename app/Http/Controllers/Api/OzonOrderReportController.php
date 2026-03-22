<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OzonOrderReport;
use App\Models\OzonWarehouseSale;
use App\Models\InventoryWarehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OzonOrderReportController extends Controller
{
    /**
     * Список загруженных отчётов
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $reports = OzonOrderReport::where('integration_id', $request->integration_id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'message' => 'OK',
            'data' => $reports,
        ]);
    }

    /**
     * Загрузка и парсинг отчёта Ozon «Заказы FBO»
     * Принимает CSV или XLSX файл
     */
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:51200', // до 50MB
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $integrationId = $request->integration_id;
        $file = $request->file('file');
        $filename = $file->getClientOriginalName();

        // Создаём запись отчёта
        $report = OzonOrderReport::create([
            'integration_id' => $integrationId,
            'filename' => $filename,
            'status' => 'processing',
        ]);

        try {
            // Парсим файл
            $rows = $this->parseFile($file);

            if (empty($rows)) {
                $report->update(['status' => 'error', 'error_message' => 'Файл пуст или не содержит данных']);
                return response()->json(['message' => 'Файл пуст', 'data' => $report], 422);
            }

            // Агрегируем данные по SKU + склад
            $aggregated = $this->aggregateBySkuWarehouse($rows);

            if (empty($aggregated)) {
                $report->update(['status' => 'error', 'error_message' => 'Не удалось извлечь данные о заказах']);
                return response()->json(['message' => 'Не удалось извлечь данные', 'data' => $report], 422);
            }

            // Определяем период отчёта
            $allDates = collect($rows)->pluck('report_date')->filter()->sort();
            $dateFrom = $allDates->first();
            $dateTo = $allDates->last();
            $periodDays = $dateFrom && $dateTo ? max(1, (int) $dateFrom->diffInDays($dateTo) + 1) : 14;

            Log::info('OzonReport dates debug', [
                'total_rows' => count($rows),
                'dates_found' => $allDates->count(),
                'date_from' => $dateFrom?->toDateTimeString(),
                'date_to' => $dateTo?->toDateTimeString(),
                'period_days' => $periodDays,
                'sample_dates' => $allDates->take(5)->map(fn($d) => $d->toDateTimeString())->toArray(),
                'last_dates' => $allDates->slice(-5)->map(fn($d) => $d->toDateTimeString())->values()->toArray(),
            ]);

            // Сохраняем агрегированные данные
            DB::beginTransaction();

            $totalItems = 0;
            $uniqueSkus = [];
            $uniqueWarehouses = [];

            foreach ($aggregated as $key => $data) {
                $avgDaily = $periodDays > 0 ? round($data['items_sold'] / $periodDays, 2) : 0;

                OzonWarehouseSale::create([
                    'report_id' => $report->id,
                    'integration_id' => $integrationId,
                    'sku' => $data['sku'],
                    'article' => $data['article'],
                    'product_name' => $data['product_name'],
                    'warehouse_name' => $data['warehouse_name'],
                    'shipment_cluster' => $data['shipment_cluster'],
                    'delivery_cluster' => $data['delivery_cluster'],
                    'orders_count' => $data['orders_count'],
                    'items_sold' => $data['items_sold'],
                    'revenue' => $data['revenue'],
                    'avg_daily_sales' => $avgDaily,
                    'period_days' => $periodDays,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ]);

                $totalItems += $data['items_sold'];
                $uniqueSkus[$data['sku']] = true;
                $uniqueWarehouses[$data['warehouse_name']] = true;
            }

            // Обновляем отчёт
            $report->update([
                'status' => 'ready',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'period_label' => $periodDays . ' дней',
                'total_orders' => count($rows),
                'total_items' => $totalItems,
                'unique_skus' => count($uniqueSkus),
                'unique_warehouses' => count($uniqueWarehouses),
            ]);

            // Рассчитываем 7/14/30-дневные продажи из сырых строк по датам
            $recentSales = $this->computeRecentSalesByPeriod($rows, $dateTo);

            // Пересчитываем оборачиваемость по складам
            $this->recalculateWarehouseTurnover($report, $recentSales);

            DB::commit();

            return response()->json([
                'message' => 'Отчёт загружен и обработан',
                'data' => [
                    'report' => $report->fresh(),
                    'period_days' => $periodDays,
                    'total_items' => $totalItems,
                    'unique_skus' => count($uniqueSkus),
                    'unique_warehouses' => count($uniqueWarehouses),
                    'aggregated_rows' => count($aggregated),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('OzonOrderReport upload error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $report->update(['status' => 'error', 'error_message' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка обработки: ' . $e->getMessage(), 'data' => $report], 500);
        }
    }

    /**
     * Сводка по отчёту: склады, сопоставление, топ товаров
     */
    public function reportSummary(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'report_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $report = OzonOrderReport::find($request->report_id);
        if (!$report) {
            return response()->json(['message' => 'Не найдено'], 404);
        }

        $sales = OzonWarehouseSale::where('report_id', $report->id)->get();

        // Сводка по складам
        $warehouseSummary = $sales->groupBy('warehouse_name')->map(function ($group, $whName) {
            return [
                'warehouse_name' => $whName,
                'skus' => $group->count(),
                'items_sold' => $group->sum('items_sold'),
                'orders' => $group->sum('orders_count'),
                'revenue' => round($group->sum('revenue'), 2),
                'avg_daily_sales' => round($group->sum('avg_daily_sales'), 2),
                'shipment_cluster' => $group->first()->shipment_cluster,
            ];
        })->sortByDesc('items_sold')->values();

        // Сколько записей inventory_warehouses обновлено
        $matchedCount = InventoryWarehouse::where('sales_report_id', $report->id)->count();

        // Топ-10 товаров по продажам
        $topSkus = $sales->groupBy('sku')->map(function ($group, $sku) {
            return [
                'sku' => $sku,
                'product_name' => $group->first()->product_name,
                'total_sold' => $group->sum('items_sold'),
                'warehouses' => $group->count(),
                'revenue' => round($group->sum('revenue'), 2),
            ];
        })->sortByDesc('total_sold')->take(10)->values();

        return response()->json([
            'message' => 'OK',
            'data' => [
                'report' => $report,
                'warehouses' => $warehouseSummary,
                'matched_inventory_records' => $matchedCount,
                'top_skus' => $topSkus,
            ],
        ]);
    }

    /**
     * Данные продаж по складам из последнего отчёта
     */
    public function warehouseSales(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'report_id' => 'nullable|uuid',
            'sku' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        // Берём последний готовый отчёт или конкретный
        if ($request->report_id) {
            $report = OzonOrderReport::find($request->report_id);
        } else {
            $report = OzonOrderReport::where('integration_id', $request->integration_id)
                ->where('status', 'ready')
                ->orderByDesc('created_at')
                ->first();
        }

        if (!$report) {
            return response()->json(['message' => 'Нет загруженных отчётов', 'data' => ['sales' => [], 'report' => null]]);
        }

        $query = OzonWarehouseSale::where('report_id', $report->id);

        if ($request->sku) {
            $query->where('sku', $request->sku);
        }

        $sales = $query->orderBy('warehouse_name')->orderByDesc('items_sold')->get();

        return response()->json([
            'message' => 'OK',
            'data' => [
                'report' => $report,
                'sales' => $sales,
            ],
        ]);
    }

    /**
     * Удалить отчёт
     */
    public function destroy(string $id): JsonResponse
    {
        $report = OzonOrderReport::find($id);
        if (!$report) {
            return response()->json(['message' => 'Не найдено'], 404);
        }

        // Сбрасываем real_ поля в inventory_warehouses если этот отчёт был последним
        InventoryWarehouse::where('sales_report_id', $report->id)->update([
            'real_avg_daily_sales' => null,
            'real_sales_period_days' => null,
            'real_turnover_days' => null,
            'real_days_of_stock' => null,
            'sales_report_id' => null,
        ]);

        $report->delete(); // cascade удалит ozon_warehouse_sales

        return response()->json(['message' => 'Удалено']);
    }

    /**
     * Парсинг CSV/XLSX файла
     */
    private function parseFile($file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if (in_array($extension, ['csv', 'txt'])) {
            return $this->parseCsv($path);
        }

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->parseXlsx($path);
        }

        throw new \Exception("Неподдерживаемый формат файла: {$extension}");
    }

    /**
     * Парсинг CSV
     */
    private function parseCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        if (!$handle) {
            throw new \Exception('Не удалось открыть файл');
        }

        // Определяем разделитель (точка с запятой или запятая или табуляция)
        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = "\t"; // По умолчанию табуляция (Ozon обычно использует её)
        if (substr_count($firstLine, ';') > substr_count($firstLine, "\t")) {
            $delimiter = ';';
        } elseif (substr_count($firstLine, ',') > substr_count($firstLine, "\t") && substr_count($firstLine, ',') > substr_count($firstLine, ';')) {
            $delimiter = ',';
        }

        // Читаем заголовки
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            throw new \Exception('Не удалось прочитать заголовки');
        }

        // Нормализуем заголовки (убираем BOM, trim)
        $headers = array_map(function ($h) {
            $h = trim($h);
            $h = preg_replace('/^\x{FEFF}/u', '', $h); // BOM
            return mb_strtolower($h);
        }, $headers);

        // Маппинг заголовков
        $columnMap = $this->mapColumns($headers);

        if (!isset($columnMap['sku']) && !isset($columnMap['article'])) {
            fclose($handle);
            throw new \Exception('Не найдены колонки SKU или Артикул в файле. Заголовки: ' . implode(', ', $headers));
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) < 5) continue; // Пропускаем пустые строки

            $parsed = $this->parseRow($row, $columnMap);
            if ($parsed) {
                $rows[] = $parsed;
            }
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Парсинг XLSX через простой XML парсер (без зависимостей)
     */
    private function parseXlsx(string $path): array
    {
        // Используем PhpSpreadsheet если доступен, иначе конвертируем через csv
        if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray();

            if (empty($data)) return [];

            $headers = array_map(fn($h) => mb_strtolower(trim($h ?? '')), $data[0]);
            $columnMap = $this->mapColumns($headers);

            if (!isset($columnMap['sku']) && !isset($columnMap['article'])) {
                throw new \Exception('Не найдены колонки SKU или Артикул');
            }

            $rows = [];
            for ($i = 1; $i < count($data); $i++) {
                $parsed = $this->parseRow($data[$i], $columnMap);
                if ($parsed) $rows[] = $parsed;
            }

            return $rows;
        }

        throw new \Exception('Для XLSX файлов требуется библиотека PhpSpreadsheet. Пожалуйста, загрузите файл в формате CSV.');
    }

    /**
     * Маппинг заголовков к нашим полям
     */
    private function mapColumns(array $headers): array
    {
        $map = [];

        foreach ($headers as $idx => $header) {
            $h = mb_strtolower(trim($header));

            // SKU
            if ($h === 'sku' || $h === 'sku товара') {
                $map['sku'] = $idx;
            }
            // Артикул
            if ($h === 'артикул' || $h === 'артикул товара' || $h === 'offer_id') {
                $map['article'] = $idx;
            }
            // Название товара
            if ($h === 'название товара' || $h === 'наименование товара' || $h === 'товар') {
                $map['product_name'] = $idx;
            }
            // Количество
            if ($h === 'количество' || $h === 'кол-во') {
                $map['quantity'] = $idx;
            }
            // Склад отгрузки
            if ($h === 'склад отгрузки' || $h === 'склад') {
                $map['warehouse'] = $idx;
            }
            // Кластер отгрузки
            if ($h === 'кластер отгрузки') {
                $map['shipment_cluster'] = $idx;
            }
            // Кластер доставки
            if ($h === 'кластер доставки') {
                $map['delivery_cluster'] = $idx;
            }
            // Статус
            if ($h === 'статус') {
                $map['status'] = $idx;
            }
            // Дата отгрузки
            if ($h === 'дата отгрузки') {
                $map['shipment_date'] = $idx;
            }
            // Принят в обработку
            if ($h === 'принят в обработку') {
                $map['created_date'] = $idx;
            }
            // Оплачено покупателем
            if ($h === 'оплачено покупателем') {
                $map['paid_amount'] = $idx;
            }
            // Ваша цена
            if ($h === 'ваша цена') {
                $map['price'] = $idx;
            }
            // Регион доставки
            if ($h === 'регион доставки') {
                $map['delivery_region'] = $idx;
            }
            // Город доставки
            if ($h === 'город доставки') {
                $map['delivery_city'] = $idx;
            }
        }

        return $map;
    }

    /**
     * Парсинг одной строки
     */
    private function parseRow(array $row, array $columnMap): ?array
    {
        $sku = isset($columnMap['sku']) ? trim($row[$columnMap['sku']] ?? '') : '';
        $article = isset($columnMap['article']) ? trim($row[$columnMap['article']] ?? '') : '';

        // Нужен хотя бы SKU или артикул
        if (empty($sku) && empty($article)) return null;

        $quantity = isset($columnMap['quantity']) ? (int) ($row[$columnMap['quantity']] ?? 0) : 1;
        if ($quantity <= 0) $quantity = 1;

        $warehouse = isset($columnMap['warehouse']) ? trim($row[$columnMap['warehouse']] ?? '') : '';

        // Фильтруем по статусу — берём только доставленные/выполненные
        $status = isset($columnMap['status']) ? mb_strtolower(trim($row[$columnMap['status']] ?? '')) : '';
        // Пропускаем отменённые
        if (in_array($status, ['отменён', 'отменен', 'cancelled', 'canceled'])) {
            return null;
        }

        // Парсим дату отгрузки и дату принятия в обработку
        $shipmentDateStr = isset($columnMap['shipment_date']) ? trim($row[$columnMap['shipment_date']] ?? '') : '';
        $createdDateStr = isset($columnMap['created_date']) ? trim($row[$columnMap['created_date']] ?? '') : '';

        // Для периода отчёта используем дату "принят в обработку" (если есть), иначе дату отгрузки
        $reportDateStr = $createdDateStr ?: $shipmentDateStr;
        $shipmentDate = $this->parseDateString($shipmentDateStr);
        $reportDate = $this->parseDateString($reportDateStr);

        static $dateLogCount = 0;
        if ($dateLogCount < 3) {
            Log::debug('OzonReport raw report_date', [
                'shipment_raw' => $shipmentDateStr,
                'created_raw' => $createdDateStr,
                'used' => $reportDateStr,
            ]);
            $dateLogCount++;
        }

        $paidAmount = isset($columnMap['paid_amount']) ? (float) str_replace([' ', ','], ['', '.'], $row[$columnMap['paid_amount']] ?? '0') : 0;
        $price = isset($columnMap['price']) ? (float) str_replace([' ', ','], ['', '.'], $row[$columnMap['price']] ?? '0') : 0;

        return [
            'sku' => $sku ?: $article,
            'article' => $article,
            'product_name' => isset($columnMap['product_name']) ? trim($row[$columnMap['product_name']] ?? '') : '',
            'warehouse' => $warehouse,
            'shipment_cluster' => isset($columnMap['shipment_cluster']) ? trim($row[$columnMap['shipment_cluster']] ?? '') : '',
            'delivery_cluster' => isset($columnMap['delivery_cluster']) ? trim($row[$columnMap['delivery_cluster']] ?? '') : '',
            'quantity' => $quantity,
            'revenue' => $paidAmount > 0 ? $paidAmount : $price * $quantity,
            'status' => $status,
            'shipment_date' => $shipmentDate,
            'report_date' => $reportDate,
        ];
    }

    private function parseDateString(?string $dateStr): ?\Carbon\Carbon
    {
        if (!$dateStr) return null;
        $dateStr = trim($dateStr);

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $dateStr, $m)) {
            return \Carbon\Carbon::createFromDate((int)$m[3], (int)$m[2], (int)$m[1]);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dateStr, $m)) {
            return \Carbon\Carbon::createFromDate((int)$m[1], (int)$m[2], (int)$m[3]);
        }

        try {
            return \Carbon\Carbon::parse($dateStr)->startOfDay();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Агрегация по SKU + склад
     */
    private function aggregateBySkuWarehouse(array $rows): array
    {
        $aggregated = [];

        foreach ($rows as $row) {
            $key = $row['sku'] . '||' . $row['warehouse'];

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'sku' => $row['sku'],
                    'article' => $row['article'],
                    'product_name' => $row['product_name'],
                    'warehouse_name' => $row['warehouse'],
                    'shipment_cluster' => $row['shipment_cluster'],
                    'delivery_cluster' => $row['delivery_cluster'],
                    'orders_count' => 0,
                    'items_sold' => 0,
                    'revenue' => 0,
                ];
            }

            $aggregated[$key]['orders_count']++;
            $aggregated[$key]['items_sold'] += $row['quantity'];
            $aggregated[$key]['revenue'] += $row['revenue'];

            // Обновляем кластер если пустой
            if (empty($aggregated[$key]['shipment_cluster']) && !empty($row['shipment_cluster'])) {
                $aggregated[$key]['shipment_cluster'] = $row['shipment_cluster'];
            }
            if (empty($aggregated[$key]['delivery_cluster']) && !empty($row['delivery_cluster'])) {
                $aggregated[$key]['delivery_cluster'] = $row['delivery_cluster'];
            }
            if (empty($aggregated[$key]['product_name']) && !empty($row['product_name'])) {
                $aggregated[$key]['product_name'] = $row['product_name'];
            }
        }

        return $aggregated;
    }

    /**
     * Рассчитать продажи за последние 7/14/30 дней из сырых строк отчёта по датам.
     * @return array<string, array{7d: int, 14d: int, 30d: int}> ключ = sku||warehouse
     */
    private function computeRecentSalesByPeriod(array $rows, $dateTo): array
    {
        if (!$dateTo) return [];

        $result = [];
        $from7  = $dateTo->copy()->subDays(6)->startOfDay();
        $from14 = $dateTo->copy()->subDays(13)->startOfDay();
        $from30 = $dateTo->copy()->subDays(29)->startOfDay();

        foreach ($rows as $row) {
            $key = $row['sku'] . '||' . $row['warehouse'];
            if (!isset($result[$key])) {
                $result[$key] = ['7d' => 0, '14d' => 0, '30d' => 0];
            }

            $date = $row['report_date'] ?? null;
            if (!$date) continue;

            $qty = $row['quantity'] ?? 1;
            if ($date->gte($from30)) {
                $result[$key]['30d'] += $qty;
            }
            if ($date->gte($from14)) {
                $result[$key]['14d'] += $qty;
            }
            if ($date->gte($from7)) {
                $result[$key]['7d'] += $qty;
            }
        }

        return $result;
    }

    /**
     * Пересчёт оборачиваемости по складам на основе реальных данных
     */
    private function recalculateWarehouseTurnover(OzonOrderReport $report, array $recentSales = []): void
    {
        $sales = OzonWarehouseSale::where('report_id', $report->id)->get();

        // Загружаем все inventory_warehouses для этой интеграции и строим lookup по нормализованному имени
        $allWarehouses = InventoryWarehouse::where('integration_id', $report->integration_id)->get();

        // Строим карту: normalized(sku + warehouse_name) => InventoryWarehouse
        $whLookup = [];
        foreach ($allWarehouses as $iw) {
            $normKey = $this->normalizeWarehouseName($iw->sku) . '||' . $this->normalizeWarehouseName($iw->warehouse_name);
            $whLookup[$normKey] = $iw;
        }

        $matched = 0;
        $unmatched = [];

        foreach ($sales as $sale) {
            $normSaleWh = $this->normalizeWarehouseName($sale->warehouse_name);

            // Пробуем по SKU
            $normKey = $this->normalizeWarehouseName($sale->sku) . '||' . $normSaleWh;
            $warehouse = $whLookup[$normKey] ?? null;

            // Пробуем по артикулу
            if (!$warehouse && $sale->article) {
                $normKey2 = $this->normalizeWarehouseName($sale->article) . '||' . $normSaleWh;
                $warehouse = $whLookup[$normKey2] ?? null;
            }

            // Fuzzy: ищем по частичному совпадению warehouse_name
            if (!$warehouse) {
                foreach ($allWarehouses as $iw) {
                    $normIwSku = $this->normalizeWarehouseName($iw->sku);
                    $normIwWh = $this->normalizeWarehouseName($iw->warehouse_name);
                    $normSaleSku = $this->normalizeWarehouseName($sale->sku);

                    if (($normIwSku === $normSaleSku || ($sale->article && $normIwSku === $this->normalizeWarehouseName($sale->article)))
                        && (str_contains($normIwWh, $normSaleWh) || str_contains($normSaleWh, $normIwWh))) {
                        $warehouse = $iw;
                        break;
                    }
                }
            }

            if ($warehouse) {
                $avgDaily = $sale->avg_daily_sales;
                $realTurnover = $avgDaily > 0 ? round($warehouse->quantity / $avgDaily, 1) : ($warehouse->quantity > 0 ? 999 : 0);
                $realDaysOfStock = $avgDaily > 0 ? (int) ceil($warehouse->quantity / $avgDaily) : ($warehouse->quantity > 0 ? 999 : 0);

                // Ищем 7/14/30-дневные продажи из raw rows
                $saleKey1 = $sale->sku . '||' . $sale->warehouse_name;
                $saleKey2 = ($sale->article ?? '') . '||' . $sale->warehouse_name;
                $recent = $recentSales[$saleKey1] ?? $recentSales[$saleKey2] ?? null;

                $sales7  = $recent ? $recent['7d']  : (int) round($avgDaily * 7);
                $sales14 = $recent ? $recent['14d'] : (int) round($avgDaily * 14);
                $sales30 = $recent ? $recent['30d'] : (int) round($avgDaily * 30);

                $daysOfStock = $avgDaily > 0 ? (int) ceil($warehouse->quantity / $avgDaily) : ($warehouse->quantity > 0 ? 999 : 0);

                $warehouse->update([
                    'real_avg_daily_sales' => $avgDaily,
                    'real_sales_period_days' => $sale->period_days,
                    'real_turnover_days' => $realTurnover,
                    'real_days_of_stock' => $realDaysOfStock,
                    'sales_report_id' => $report->id,
                    'average_daily_sales' => $avgDaily,
                    'effective_daily_sales' => $avgDaily,
                    'sales_7_days' => $sales7,
                    'sales_14_days' => $sales14,
                    'sales_30_days' => $sales30,
                    'days_of_stock' => $daysOfStock,
                    'turnover_days' => $realTurnover,
                ]);
                $matched++;
            } else {
                $unmatched[] = $sale->sku . ' @ ' . $sale->warehouse_name;
            }
        }

        // Для всех SKU, которые есть в отчёте, но склад не встретился — ставим real_avg=0
        // Это значит: с этого склада за период не было отгрузок
        $reportSkus = $sales->pluck('article')->filter()->unique()->toArray();
        $reportSkusBySku = $sales->pluck('sku')->filter()->unique()->toArray();

        $zeroFilled = 0;
        foreach ($allWarehouses as $iw) {
            // Пропускаем уже обновлённые
            if ($iw->sales_report_id === $report->id) continue;

            // Проверяем, есть ли этот SKU в отчёте (по артикулу или числовому SKU)
            $normIwSku = $this->normalizeWarehouseName($iw->sku);
            $skuInReport = false;
            foreach ($reportSkus as $art) {
                if ($this->normalizeWarehouseName($art) === $normIwSku) {
                    $skuInReport = true;
                    break;
                }
            }
            if (!$skuInReport) {
                foreach ($reportSkusBySku as $rSku) {
                    if ($this->normalizeWarehouseName($rSku) === $normIwSku) {
                        $skuInReport = true;
                        break;
                    }
                }
            }

            if ($skuInReport) {
                $periodDays = $sales->first()->period_days ?? 14;
                $iw->update([
                    'real_avg_daily_sales' => 0,
                    'real_sales_period_days' => $periodDays,
                    'real_turnover_days' => $iw->quantity > 0 ? 999 : 0,
                    'real_days_of_stock' => $iw->quantity > 0 ? 999 : 0,
                    'sales_report_id' => $report->id,
                    'average_daily_sales' => 0,
                    'effective_daily_sales' => 0,
                    'sales_7_days' => 0,
                    'sales_14_days' => 0,
                    'sales_30_days' => 0,
                    'days_of_stock' => $iw->quantity > 0 ? 999 : 0,
                    'turnover_days' => $iw->quantity > 0 ? 999 : 0,
                ]);
                $zeroFilled++;
            }
        }

        Log::info('Warehouse turnover recalculated from report', [
            'report_id' => $report->id,
            'sales_count' => $sales->count(),
            'matched' => $matched,
            'zero_filled' => $zeroFilled,
            'unmatched_count' => count($unmatched),
            'unmatched_sample' => array_slice($unmatched, 0, 10),
        ]);
    }

    /**
     * Нормализация названия склада для сопоставления
     */
    private function normalizeWarehouseName(string $name): string
    {
        // Приводим к нижнему регистру
        $name = mb_strtolower(trim($name));
        // Заменяем дефисы, подчёркивания и пробелы на единый разделитель
        $name = preg_replace('/[\-_\s]+/u', '_', $name);
        return $name;
    }
}
