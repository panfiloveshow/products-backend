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
            $allDates = collect($rows)->pluck('shipment_date')->filter()->sort();
            $dateFrom = $allDates->first();
            $dateTo = $allDates->last();
            $periodDays = $dateFrom && $dateTo ? max(1, (int) $dateFrom->diffInDays($dateTo) + 1) : 14;

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

            // Пересчитываем оборачиваемость по складам
            $this->recalculateWarehouseTurnover($report);

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

        // Парсим дату отгрузки
        $shipmentDate = null;
        $dateStr = isset($columnMap['shipment_date']) ? trim($row[$columnMap['shipment_date']] ?? '') : '';
        if (empty($dateStr) && isset($columnMap['created_date'])) {
            $dateStr = trim($row[$columnMap['created_date']] ?? '');
        }
        if (!empty($dateStr)) {
            try {
                $shipmentDate = \Carbon\Carbon::parse($dateStr);
            } catch (\Exception $e) {
                // Пробуем формат DD.MM.YYYY
                try {
                    $shipmentDate = \Carbon\Carbon::createFromFormat('d.m.Y', substr($dateStr, 0, 10));
                } catch (\Exception $e2) {
                    // Игнорируем
                }
            }
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
        ];
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
     * Пересчёт оборачиваемости по складам на основе реальных данных
     */
    private function recalculateWarehouseTurnover(OzonOrderReport $report): void
    {
        $sales = OzonWarehouseSale::where('report_id', $report->id)->get();

        foreach ($sales as $sale) {
            // Ищем запись склада по SKU + warehouse_name
            $warehouse = InventoryWarehouse::where('integration_id', $report->integration_id)
                ->where('sku', $sale->sku)
                ->where(function ($q) use ($sale) {
                    $q->where('warehouse_name', $sale->warehouse_name)
                      ->orWhere('warehouse_name', 'LIKE', '%' . $this->normalizeWarehouseName($sale->warehouse_name) . '%');
                })
                ->first();

            if (!$warehouse) {
                // Пробуем найти по артикулу
                if ($sale->article) {
                    $warehouse = InventoryWarehouse::where('integration_id', $report->integration_id)
                        ->where('sku', $sale->article)
                        ->where(function ($q) use ($sale) {
                            $q->where('warehouse_name', $sale->warehouse_name)
                              ->orWhere('warehouse_name', 'LIKE', '%' . $this->normalizeWarehouseName($sale->warehouse_name) . '%');
                        })
                        ->first();
                }
            }

            if ($warehouse) {
                $avgDaily = $sale->avg_daily_sales;
                $realTurnover = $avgDaily > 0 ? round($warehouse->quantity / $avgDaily, 1) : ($warehouse->quantity > 0 ? 999 : 0);
                $realDaysOfStock = $avgDaily > 0 ? (int) ceil($warehouse->quantity / $avgDaily) : ($warehouse->quantity > 0 ? 999 : 0);

                $warehouse->update([
                    'real_avg_daily_sales' => $avgDaily,
                    'real_sales_period_days' => $sale->period_days,
                    'real_turnover_days' => $realTurnover,
                    'real_days_of_stock' => $realDaysOfStock,
                    'sales_report_id' => $report->id,
                ]);
            }
        }

        Log::info('Warehouse turnover recalculated from report', [
            'report_id' => $report->id,
            'sales_count' => $sales->count(),
        ]);
    }

    /**
     * Нормализация названия склада для сопоставления
     */
    private function normalizeWarehouseName(string $name): string
    {
        // Убираем суффиксы типа _РФЦ, _МПСЦ и т.д.
        $name = preg_replace('/[_\s]+(РФЦ|МПСЦ|ФФЦ|СЦ)$/ui', '', $name);
        // Убираем лишние пробелы
        $name = trim($name);
        return $name;
    }
}
