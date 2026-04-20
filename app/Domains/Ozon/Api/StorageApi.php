<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * API для работы с хранением и тарифами Ozon
 */
class StorageApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить стоимость хранения
     * Использует /v2/analytics/stock_on_warehouses для получения данных об остатках
     */
    public function getStorageCost(): array
    {
        try {
            // Используем endpoint для получения остатков на складах
            $response = $this->client->post('/v2/analytics/stock_on_warehouses', [
                'limit' => 1000,
                'offset' => 0,
                'warehouse_type' => 'ALL',
            ]);

            return $response['result'] ?? [];
        } catch (\Exception $e) {
            Log::warning('Ozon getStorageCost error', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Получить стоимость хранения по SKU
     * Использует /v2/analytics/stock_on_warehouses
     */
    public function getStorageCostBySku(): array
    {
        try {
            // Используем endpoint для получения остатков на складах
            $response = $this->client->post('/v2/analytics/stock_on_warehouses', [
                'limit' => 1000,
                'offset' => 0,
                'warehouse_type' => 'ALL',
            ]);

            $result = [];
            foreach ($response['result']['rows'] ?? [] as $row) {
                $sku = $row['item_code'] ?? $row['sku'] ?? null;
                if (!$sku) continue;

                $result[$sku] = [
                    'storage_cost' => 0, // Не доступно в этом API
                    'days_stored' => 0, // Не доступно в этом API
                    'volume' => 0, // Не доступно в этом API
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::warning('Ozon getStorageCostBySku error', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Получить тарифы на товары
     */
    public function getProductTariffs(array $productIds = []): array
    {
        if (empty($productIds)) {
            return [];
        }

        try {
            $response = $this->client->post('/v1/product/info/prices', [
                'product_id' => $productIds,
            ]);

            $result = [];
            foreach ($response['result']['items'] ?? [] as $item) {
                $productId = $item['product_id'] ?? null;
                if (!$productId) continue;

                $result[$productId] = [
                    'price' => (float)($item['price']['price'] ?? 0),
                    'old_price' => (float)($item['price']['old_price'] ?? 0),
                    'min_price' => (float)($item['price']['min_price'] ?? 0),
                    'commissions' => $item['commissions'] ?? [],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getProductTariffs error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить актуальные расходы по SKU
     */
    public function getActualCostsBySku(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $dateFrom = $dateFrom ?? now()->subDays(30)->format('Y-m-d');
        $dateTo = $dateTo ?? now()->format('Y-m-d');

        try {
            $response = $this->client->post('/v1/finance/realization', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            $result = [];
            foreach ($response['result']['rows'] ?? [] as $row) {
                $sku = $row['offer_id'] ?? null;
                if (!$sku) continue;

                $result[$sku] = [
                    'sale_commission' => (float)($row['sale_commission'] ?? 0),
                    'return_commission' => (float)($row['return_commission'] ?? 0),
                    'logistics_cost' => (float)($row['delivery_charge'] ?? 0),
                    'return_logistics' => (float)($row['return_delivery_charge'] ?? 0),
                    'acquiring_fee' => (float)($row['acquiring_fee'] ?? 0),
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getActualCostsBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Создать отчёт о стоимости размещения (хранения) по товарам
     * POST /v1/report/placement/by-products/create
     * 
     * @param string $dateFrom Начало периода (YYYY-MM-DD)
     * @param string $dateTo Конец периода (YYYY-MM-DD)
     * @return string|null UUID отчёта для последующего скачивания
     */
    public function createPlacementReportByProducts(string $dateFrom, string $dateTo): ?string
    {
        try {
            $response = $this->client->post('/v1/report/placement/by-products/create', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            // API возвращает code на верхнем уровне
            $reportId = $response['code'] ?? $response['result']['code'] ?? null;
            
            Log::info('Ozon placement report by products created', [
                'report_id' => $reportId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            return $reportId;
        } catch (\Exception $e) {
            Log::error('Ozon createPlacementReportByProducts error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Создать отчёт о стоимости размещения (хранения) по поставкам
     * POST /v1/report/placement/by-supplies/create
     * 
     * @param string $dateFrom Начало периода (YYYY-MM-DD)
     * @param string $dateTo Конец периода (YYYY-MM-DD)
     * @return string|null UUID отчёта для последующего скачивания
     */
    public function createPlacementReportBySupplies(string $dateFrom, string $dateTo): ?string
    {
        try {
            $response = $this->client->post('/v1/report/placement/by-supplies/create', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            $reportId = $response['result']['code'] ?? null;
            
            Log::info('Ozon placement report by supplies created', [
                'report_id' => $reportId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            return $reportId;
        } catch (\Exception $e) {
            Log::error('Ozon createPlacementReportBySupplies error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Получить информацию об отчёте
     * POST /v1/report/info
     * 
     * @param string $reportId UUID отчёта
     * @return array Информация об отчёте (status, file и т.д.)
     */
    public function getReportInfo(string $reportId): array
    {
        try {
            $response = $this->client->post('/v1/report/info', [
                'code' => $reportId,
            ]);

            return $response['result'] ?? [];
        } catch (\Exception $e) {
            Log::error('Ozon getReportInfo error', [
                'report_id' => $reportId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Получить список отчётов
     * POST /v1/report/list
     * 
     * @param string|null $reportType Тип отчёта (placement_by_products, placement_by_supplies и др.)
     * @param int $page Номер страницы
     * @param int $pageSize Размер страницы
     * @return array Список отчётов
     */
    public function getReportList(?string $reportType = null, int $page = 1, int $pageSize = 100): array
    {
        try {
            $params = [
                'page' => $page,
                'page_size' => $pageSize,
            ];
            
            if ($reportType) {
                $params['report_type'] = $reportType;
            }

            $response = $this->client->post('/v1/report/list', $params);

            return $response['result']['reports'] ?? [];
        } catch (\Exception $e) {
            Log::error('Ozon getReportList error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить стоимость размещения по товарам за период
     * Всегда создаёт новый отчёт за указанный период (не использует кэш)
     * 
     * @param string $dateFrom Начало периода (YYYY-MM-DD)
     * @param string $dateTo Конец периода (YYYY-MM-DD)
     * @param int $maxWaitSeconds Максимальное время ожидания отчёта
     * @return array Данные о стоимости размещения по SKU ['sku' => ['placement_cost' => float, ...]]
     */
    public function getPlacementCostByProducts(string $dateFrom, string $dateTo, int $maxWaitSeconds = 60): array
    {
        $cacheKey = $this->placementProductsCacheKey($dateFrom, $dateTo);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            Log::info('Ozon placement report data loaded from cache', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'skus_count' => count($cached),
            ]);

            return $cached;
        }

        $existingReport = $this->findExistingPlacementReport('seller_placement_by_products');
        if ($existingReport !== null && ! empty($existingReport['code'])) {
            // URL из /v1/report/list живёт ~3 часа (X-Amz-Expires=10800). Всегда
            // запрашиваем свежую подписанную ссылку через /v1/report/info —
            // это не считается как новый отчёт и обходит дневной лимит 5/день.
            $reportInfo = $this->getReportInfo((string) $existingReport['code']);
            $fileUrl = $reportInfo['file'] ?? ($existingReport['file'] ?? null);

            if ($fileUrl) {
                $data = $this->downloadAndParsePlacementReport((string) $fileUrl);
                if ($data !== []) {
                    Cache::put($cacheKey, $data, now()->addHours(2));

                    return $data;
                }
                // Если парсинг вернул пусто (ссылка протухла / XLSX битый) —
                // не возвращаем пустоту, а пробуем создать новый отчёт ниже.
            }
        }

        $reportId = $this->createPlacementReportByProducts($dateFrom, $dateTo);
        
        if (!$reportId || is_numeric($reportId)) {
            Log::warning('Ozon placement report creation failed or returned error', [
                'report_id' => $reportId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);
            return [];
        }

        // Ждём готовности отчёта
        $startTime = time();
        $reportInfo = [];
        
        while (time() - $startTime < $maxWaitSeconds) {
            $reportInfo = $this->getReportInfo($reportId);
            $status = $reportInfo['status'] ?? '';
            
            if ($status === 'success') {
                break;
            }
            
            if ($status === 'failed') {
                Log::error('Ozon placement report failed', ['report_id' => $reportId]);
                return [];
            }
            
            sleep(3);
        }

        $fileUrl = $reportInfo['file'] ?? null;
        
        if (!$fileUrl) {
            Log::warning('Ozon placement report has no file URL', ['report_id' => $reportId]);
            return [];
        }

        $data = $this->downloadAndParsePlacementReport($fileUrl);
        if ($data !== []) {
            Cache::put($cacheKey, $data, now()->addHours(2));
        }

        return $data;
    }

    private function placementProductsCacheKey(string $dateFrom, string $dateTo): string
    {
        return sprintf(
            'ozon:placement:products:%s:%s:%s',
            $this->client->getClientCacheKey(),
            $dateFrom,
            $dateTo
        );
    }

    /**
     * Найти существующий отчёт о размещении за последние 24 часа
     */
    private function findExistingPlacementReport(string $reportType): ?array
    {
        try {
            $reports = $this->getReportList($reportType, 1, 10);
            
            foreach ($reports as $report) {
                $status = $report['status'] ?? '';
                $createdAt = $report['created_at'] ?? null;
                
                // Проверяем что отчёт успешный и создан менее 2.5 часов назад
                // (ссылка на скачивание истекает через 3 часа - X-Amz-Expires=10800)
                if ($status === 'success' && $createdAt) {
                    $createdTime = strtotime($createdAt);
                    $hoursAgo = (time() - $createdTime) / 3600;
                    
                    if ($hoursAgo < 48) {
                        Log::info('Found existing placement report', [
                            'report_id' => $report['code'] ?? 'unknown',
                            'created_at' => $createdAt,
                            'hours_ago' => round($hoursAgo, 1),
                        ]);
                        return $report;
                    }
                }
            }
            
            return null;
        } catch (\Exception $e) {
            Log::warning('Error finding existing placement report', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Скачать и распарсить отчёт о стоимости размещения (XLSX формат)
     * Использует PhpSpreadsheet для корректного парсинга
     * 
     * Структура XLSX:
     * C1=Дата, C2=SKU, C3=Артикул, ..., C12=Начисленная стоимость размещения
     * 
     * @param string $fileUrl URL файла отчёта
     * @return array Данные по SKU ['sku' => ['placement_cost' => float, ...]]
     */
    private function downloadAndParsePlacementReport(string $fileUrl): array
    {
        try {
            // SSRF guard: URL приходит от Ozon API, но безопаснее явно
            // разрешить только https://*.ozon.ru / ozon.ru — иначе ошибка.
            if (! $this->isAllowedOzonReportUrl($fileUrl)) {
                Log::warning('Ozon placement report: URL не прошёл allow-list', [
                    'host' => parse_url($fileUrl, PHP_URL_HOST),
                ]);
                return [];
            }

            $tempFile = '/tmp/ozon_placement_' . md5($fileUrl) . '.xlsx';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'ignore_errors' => true,
                    'follow_location' => 0, // не следовать редиректам — защита от SSRF-rebind
                    'max_redirects' => 0,
                ],
            ]);
            $content = file_get_contents($fileUrl, false, $context);

            if (!$content) {
                Log::warning('Ozon placement report: empty file');
                return [];
            }

            file_put_contents($tempFile, $content);
            
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();
            $maxRow = $sheet->getHighestRow();
            $maxColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
            
            [$headerRow, $headers] = $this->detectPlacementReportHeaders($sheet, $maxColumn);
            $skuColumn = $this->resolvePlacementReportColumn($headers, [
                fn (string $header): bool => str_contains($header, 'артикул'),
                fn (string $header): bool => str_contains($header, 'offer'),
                fn (string $header): bool => $header === 'sku' || str_contains($header, 'sku'),
            ], 3);
            $costColumn = $this->resolvePlacementReportColumn($headers, [
                fn (string $header): bool => str_contains($header, 'начис') && str_contains($header, 'размещ'),
                fn (string $header): bool => str_contains($header, 'стоим') && str_contains($header, 'размещ'),
                fn (string $header): bool => str_contains($header, 'списан') || str_contains($header, 'списано'),
                fn (string $header): bool => str_contains($header, 'placement') && str_contains($header, 'cost'),
                fn (string $header): bool => str_contains($header, 'storage') && str_contains($header, 'cost'),
            ], 12);

            Log::info('Ozon placement report columns resolved', [
                'header_row' => $headerRow,
                'sku_column' => $skuColumn,
                'sku_header' => $headers[$skuColumn] ?? null,
                'cost_column' => $costColumn,
                'cost_header' => $headers[$costColumn] ?? null,
                'rows' => max(0, $maxRow - $headerRow),
            ]);

            $result = [];
            $totalCost = 0;
            
            for ($r = $headerRow + 1; $r <= $maxRow; $r++) {
                $sku = $sheet->getCellByColumnAndRow($skuColumn, $r)->getValue();
                $cost = $this->parsePlacementMoney($sheet->getCellByColumnAndRow($costColumn, $r)->getCalculatedValue());
                
                if (!$sku || empty(trim((string)$sku))) continue;
                
                $sku = trim((string)$sku);
                
                if (!isset($result[$sku])) {
                    $result[$sku] = ['placement_cost' => 0];
                }
                
                $result[$sku]['placement_cost'] += $cost;
                $totalCost += $cost;
            }
            
            @unlink($tempFile);
            
            Log::info('Ozon placement report parsed', [
                'skus_count' => count($result),
                'total_cost' => round($totalCost, 2),
                'rows' => $maxRow - $headerRow,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon downloadAndParsePlacementReport error', ['error' => $e->getMessage()]);
            @unlink($tempFile ?? '');
            return [];
        }
    }

    /**
     * @return array{0:int, 1:array<int,string>}
     */
    private function detectPlacementReportHeaders($sheet, int $maxColumn): array
    {
        $fallbackHeaders = [];
        for ($row = 1; $row <= min(15, $sheet->getHighestRow()); $row++) {
            $headers = [];
            for ($col = 1; $col <= $maxColumn; $col++) {
                $headers[$col] = $this->normalizePlacementHeader(
                    (string) $sheet->getCellByColumnAndRow($col, $row)->getValue()
                );
            }

            $nonEmptyHeaders = array_filter($headers, fn (string $header): bool => $header !== '');
            if ($fallbackHeaders === [] && $nonEmptyHeaders !== []) {
                $fallbackHeaders = $headers;
            }

            $hasSku = $this->resolvePlacementReportColumn($headers, [
                fn (string $header): bool => str_contains($header, 'артикул') || str_contains($header, 'offer') || str_contains($header, 'sku'),
            ], null) !== null;
            $hasCost = $this->resolvePlacementReportColumn($headers, [
                fn (string $header): bool => (str_contains($header, 'начис') && str_contains($header, 'размещ'))
                    || (str_contains($header, 'стоим') && str_contains($header, 'размещ'))
                    || str_contains($header, 'списан')
                    || (str_contains($header, 'placement') && str_contains($header, 'cost'))
                    || (str_contains($header, 'storage') && str_contains($header, 'cost')),
            ], null) !== null;

            if ($hasSku && $hasCost) {
                return [$row, $headers];
            }
        }

        return [1, $fallbackHeaders];
    }

    private function resolvePlacementReportColumn(array $headers, array $predicates, ?int $fallback): ?int
    {
        foreach ($predicates as $predicate) {
            foreach ($headers as $column => $header) {
                if ($header !== '' && $predicate($header)) {
                    return (int) $column;
                }
            }
        }

        return $fallback;
    }

    private function normalizePlacementHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = str_replace(["\xc2\xa0", "\n", "\r", "\t"], ' ', $header);
        $header = preg_replace('/\s+/u', ' ', $header) ?? $header;

        return trim($header);
    }

    private function parsePlacementMoney(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return 0.0;
        }

        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        $value = preg_replace('/[^\d,\.\-]/u', '', $value) ?? '';
        if ($value === '' || $value === '-') {
            return 0.0;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
        }
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Получить общую сумму начислений за хранение из cash-flow-statement
     * POST /v1/finance/cash-flow-statement/list
     * 
     * Суммирует MarketplaceServiceStorageItem из services.items за все недели периода.
     * Это корректный источник данных о хранении (совпадает с ЛК Ozon).
     * 
     * @param string $dateFrom Начало периода (YYYY-MM-DD)
     * @param string $dateTo Конец периода (YYYY-MM-DD)
     * @return array ['total' => float, 'weeks' => int]
     */
    public function getStorageTotalFromCashFlow(string $dateFrom, string $dateTo): array
    {
        try {
            $total = 0;
            $weeks = 0;
            $page = 1;
            
            while ($page <= 10) {
                $response = $this->client->post('/v1/finance/cash-flow-statement/list', [
                    'date' => [
                        'from' => $dateFrom . 'T00:00:00.000Z',
                        'to' => $dateTo . 'T23:59:59.000Z',
                    ],
                    'with_details' => true,
                    'page' => $page,
                    'page_size' => 50,
                ]);

                $details = $response['result']['details'] ?? [];
                
                if (empty($details)) {
                    break;
                }

                foreach ($details as $detail) {
                    $services = $detail['services']['items'] ?? [];
                    foreach ($services as $service) {
                        if (stripos($service['name'] ?? '', 'Storage') !== false) {
                            $total += abs($service['price'] ?? 0);
                            $weeks++;
                        }
                    }
                }
                
                $pageCount = $response['result']['page_count'] ?? 1;
                if ($page >= $pageCount) {
                    break;
                }
                
                $page++;
            }

            Log::info('Ozon storage total from cash-flow', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'total' => round($total, 2),
                'weeks' => $weeks,
            ]);

            return [
                'total' => round($total, 2),
                'weeks' => $weeks,
            ];
        } catch (\Exception $e) {
            Log::error('Ozon getStorageTotalFromCashFlow error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Разрешаем скачивать отчёты только с *.ozon.ru / ozon.ru по https.
     * Закрывает SSRF-вектор при компрометации Ozon API (например, MITM
     * или подмена response): злоумышленник не сможет заставить бэкенд
     * скачать файл с произвольного URL.
     */
    private function isAllowedOzonReportUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        return $host === 'ozon.ru'
            || str_ends_with($host, '.ozon.ru')
            || str_ends_with($host, '.ozon.cloud');
    }
}
