<?php

namespace App\Domains\Ozon\Api;

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
     * Сначала проверяет существующие отчёты, если нет - создаёт новый
     * 
     * @param string $dateFrom Начало периода (YYYY-MM-DD)
     * @param string $dateTo Конец периода (YYYY-MM-DD)
     * @param int $maxWaitSeconds Максимальное время ожидания отчёта
     * @return array Данные о стоимости размещения по SKU
     */
    public function getPlacementCostByProducts(string $dateFrom, string $dateTo, int $maxWaitSeconds = 60): array
    {
        // Сначала проверяем существующие отчёты за последние 24 часа
        $existingReport = $this->findExistingPlacementReport('seller_placement_by_products');
        
        if ($existingReport) {
            $fileUrl = $existingReport['file'] ?? null;
            if ($fileUrl) {
                Log::info('Using existing placement report', ['report_id' => $existingReport['code'] ?? 'unknown']);
                return $this->downloadAndParsePlacementReport($fileUrl);
            }
        }

        // Если нет существующего отчёта - создаём новый
        $reportId = $this->createPlacementReportByProducts($dateFrom, $dateTo);
        
        if (!$reportId) {
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
            
            sleep(2); // Ждём 2 секунды перед следующей проверкой
        }

        // Получаем URL файла
        $fileUrl = $reportInfo['file'] ?? null;
        
        if (!$fileUrl) {
            Log::warning('Ozon placement report has no file URL', ['report_id' => $reportId]);
            return [];
        }

        // Скачиваем и парсим XLSX
        return $this->downloadAndParsePlacementReport($fileUrl);
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
                    
                    if ($hoursAgo < 2.5) {
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
     * 
     * @param string $fileUrl URL файла отчёта
     * @return array Данные по SKU
     */
    private function downloadAndParsePlacementReport(string $fileUrl): array
    {
        try {
            // Скачиваем файл во временную директорию
            $tempFile = tempnam(sys_get_temp_dir(), 'ozon_placement_') . '.xlsx';
            $content = file_get_contents($fileUrl);
            
            if (!$content) {
                Log::warning('Ozon placement report: empty file');
                return [];
            }
            
            file_put_contents($tempFile, $content);
            
            // Парсим XLSX с помощью SimpleXLSX или PhpSpreadsheet
            $result = $this->parseXlsxFile($tempFile);
            
            // Удаляем временный файл
            @unlink($tempFile);
            
            Log::info('Ozon placement report parsed', ['skus_count' => count($result)]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon downloadAndParsePlacementReport error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Парсинг XLSX файла отчёта о размещении
     */
    private function parseXlsxFile(string $filePath): array
    {
        $result = [];
        
        // Используем ZipArchive для чтения XLSX
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            Log::warning('Cannot open XLSX file');
            return [];
        }

        // Читаем shared strings
        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml) {
            $xml = simplexml_load_string($sharedStringsXml);
            foreach ($xml->si as $si) {
                $sharedStrings[] = (string)$si->t;
            }
        }

        // Читаем данные листа
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$sheetXml) {
            Log::warning('Cannot read sheet1.xml');
            return [];
        }

        $xml = simplexml_load_string($sheetXml);
        $rows = [];
        
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            $colIndex = 0;
            
            foreach ($row->c as $cell) {
                $value = '';
                $type = (string)$cell['t'];
                $ref = (string)$cell['r'];
                
                // Если есть ссылка на ячейку (A1, B1, ...), используем её
                // Иначе используем порядковый индекс
                if (!empty($ref) && preg_match('/^([A-Z]+)/', $ref, $matches)) {
                    $colIndex = $this->columnLetterToIndex($matches[1]);
                }
                
                if ($type === 's') {
                    // Shared string
                    $index = (int)$cell->v;
                    $value = $sharedStrings[$index] ?? '';
                } elseif ($type === 'str' || $type === 'inlineStr') {
                    // Inline string
                    $value = (string)$cell->v;
                } else {
                    // Number or other
                    $value = (string)$cell->v;
                }
                
                $rowData[$colIndex] = $value;
                $colIndex++; // Увеличиваем для следующей ячейки
            }
            $rows[] = $rowData;
        }

        if (count($rows) < 2) {
            return [];
        }

        // Первая строка - заголовки
        $headers = array_map('trim', $rows[0]);
        
        // Ищем индексы нужных колонок (порядок важен - более специфичные названия первыми)
        $skuIndex = $this->findColumnIndex($headers, ['Артикул', 'offer_id']); // НЕ SKU - это числовой ID Ozon
        $costIndex = $this->findColumnIndex($headers, ['Начисленная стоимость размещения', 'Начисленная стоимость']);
        $volumeIndex = $this->findColumnIndex($headers, ['Платный объем в миллилитрах', 'Платный объем']);
        $quantityIndex = $this->findColumnIndex($headers, ['Кол-во платных экземпляров', 'Кол-во платных']);
        
        Log::info('Ozon placement report columns', [
            'headers' => $headers,
            'sku_index' => $skuIndex,
            'cost_index' => $costIndex,
        ]);

        if ($skuIndex === null) {
            Log::warning('SKU column not found in placement report', ['headers' => $headers]);
            return [];
        }

        // Парсим данные
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $sku = $row[$skuIndex] ?? null;
            
            if (!$sku || empty(trim($sku))) continue;
            
            $cost = $costIndex !== null ? (float)str_replace([' ', ','], ['', '.'], $row[$costIndex] ?? '0') : 0;
            $volume = $volumeIndex !== null ? (float)str_replace([' ', ','], ['', '.'], $row[$volumeIndex] ?? '0') : 0;
            $quantity = $quantityIndex !== null ? (int)($row[$quantityIndex] ?? 0) : 0;
            
            // Агрегируем по SKU (может быть несколько строк для одного SKU)
            if (!isset($result[$sku])) {
                $result[$sku] = [
                    'placement_cost' => 0,
                    'volume_liters' => $volume,
                    'quantity' => 0,
                ];
            }
            
            $result[$sku]['placement_cost'] += $cost;
            $result[$sku]['quantity'] += $quantity;
        }

        return $result;
    }

    /**
     * Найти индекс колонки по возможным названиям
     */
    private function findColumnIndex(array $headers, array $possibleNames): ?int
    {
        foreach ($headers as $index => $header) {
            $headerLower = mb_strtolower(trim($header));
            foreach ($possibleNames as $name) {
                if (mb_strpos($headerLower, mb_strtolower($name)) !== false) {
                    return $index;
                }
            }
        }
        return null;
    }

    /**
     * Конвертировать букву колонки Excel в индекс (A=0, B=1, ..., Z=25, AA=26, ...)
     */
    private function columnLetterToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        $length = strlen($letters);
        
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        
        return $index - 1; // 0-based index
    }

    /**
     * Получить финансовые транзакции по хранению
     * POST /v3/finance/transaction/list
     * 
     * Разделяет два типа начислений:
     * - MarketplaceServiceItemStorageFee — обычная плата за хранение
     * - MarketplaceServiceItemStorageExcess — штраф за платное хранение (товар >120 дней на складе)
     * 
     * @param string $dateFrom Начало периода (YYYY-MM-DD)
     * @param string $dateTo Конец периода (YYYY-MM-DD)
     * @return array Транзакции по хранению, сгруппированные по SKU с разделением fee/penalty
     */
    public function getStorageTransactions(string $dateFrom, string $dateTo, array $productIdToSku = []): array
    {
        try {
            $result = [];
            $page = 1;
            $maxPages = 10;
            
            while ($page <= $maxPages) {
                $response = $this->client->post('/v3/finance/transaction/list', [
                    'filter' => [
                        'date' => [
                            'from' => $dateFrom . 'T00:00:00.000Z',
                            'to' => $dateTo . 'T23:59:59.000Z',
                        ],
                        'operation_type' => [
                            'MarketplaceServiceItemStorageFee',
                            'MarketplaceServiceItemStorageExcess',
                        ],
                        'posting_number' => '',
                        'transaction_type' => 'all',
                    ],
                    'page' => $page,
                    'page_size' => 1000,
                ]);

                $operations = $response['result']['operations'] ?? [];
                
                if (empty($operations)) {
                    break;
                }

                foreach ($operations as $op) {
                    $operationType = $op['operation_type'] ?? '';
                    $amount = abs($op['amount'] ?? 0);
                    
                    foreach ($op['items'] ?? [] as $item) {
                        $productId = (string)($item['sku'] ?? '');
                        
                        if (!$productId) continue;
                        
                        // Конвертируем ozon_sku в offer_id (SKU продавца)
                        $sku = $productIdToSku[$productId] ?? $productId;
                        
                        if (!isset($result[$sku])) {
                            $result[$sku] = [
                                'storage_fee' => 0,
                                'storage_penalty' => 0,
                                'total_storage_cost' => 0,
                                'transactions_count' => 0,
                                'product_name' => $item['name'] ?? '',
                                'product_id' => $productId,
                            ];
                        }
                        
                        // Разделяем обычное хранение и штраф за превышение 120 дней
                        if ($operationType === 'MarketplaceServiceItemStorageExcess') {
                            $result[$sku]['storage_penalty'] += $amount;
                        } else {
                            $result[$sku]['storage_fee'] += $amount;
                        }
                        $result[$sku]['total_storage_cost'] += $amount;
                        $result[$sku]['transactions_count']++;
                    }
                }
                
                // Если получили меньше 1000, значит это последняя страница
                if (count($operations) < 1000) {
                    break;
                }
                
                $page++;
            }

            $totalPenalty = array_sum(array_column($result, 'storage_penalty'));
            $totalFee = array_sum(array_column($result, 'storage_fee'));
            
            Log::info('Ozon storage transactions loaded', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'skus_count' => count($result),
                'pages_loaded' => $page,
                'total_penalty' => round($totalPenalty, 2),
                'total_fee' => round($totalFee, 2),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getStorageTransactions error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
