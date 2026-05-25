<?php

namespace App\Domains\Wildberries\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с отчётами реализации Wildberries
 * 
 * Отчёт реализации содержит фактические начисления за хранение (storage_fee),
 * которые WB выставляет к оплате в еженедельных отчётах.
 * 
 * @see https://dev.wildberries.ru/openapi/financial-reports-and-accounting
 */
class RealizationReportApi
{
    public function __construct(
        private WildberriesClient $client
    ) {}

    /**
     * Получить детализацию отчётов реализации через новый Finance API.
     *
     * Это будущий источник фактической сверки unit economics: реальные удержания
     * WB по комиссиям, логистике, хранению, возвратам и прочим услугам.
     *
     * Endpoint: POST /api/finance/v1/sales-reports/detailed
     * URL: https://finance-api.wildberries.ru
     *
     * @param array<int, string> $fields Опциональный список полей для уменьшения ответа.
     */
    public function getDetailedSalesReportsByPeriod(
        string $dateFrom,
        string $dateTo,
        array $fields = []
    ): array {
        $payload = [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];

        if ($fields !== []) {
            $payload['fields'] = array_values($fields);
        }

        try {
            $response = $this->client->financePost('/api/finance/v1/sales-reports/detailed', $payload);

            return is_array($response) ? $response : [];
        } catch (\Throwable $e) {
            Log::error('WB Finance detailed sales reports error', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Получить детализацию отчёта реализации за период
     * 
     * Endpoint: GET /api/v5/supplier/reportDetailByPeriod
     * URL: https://statistics-api.wildberries.ru
     * 
     * @param string $dateFrom Начало периода (YYYY-MM-DD)
     * @param string $dateTo Конец периода (YYYY-MM-DD)
     * @param string $periodicity weekly|daily
     * @return array
     */
    public function getReportDetailByPeriod(
        string $dateFrom, 
        string $dateTo, 
        string $periodicity = 'weekly'
    ): array {
        $allData = [];
        $rrdId = 0;
        $limit = 100000;
        $maxIterations = 50; // Защита от бесконечного цикла
        $iteration = 0;
        
        try {
            do {
                $response = $this->client->statisticsGet('/api/v5/supplier/reportDetailByPeriod', [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'rrdid' => $rrdId,
                    'limit' => $limit,
                    'periodicity' => $periodicity,
                ]);
                
                if (empty($response)) {
                    break;
                }
                
                $allData = array_merge($allData, $response);
                
                // Получаем последний rrd_id для следующей итерации
                $lastItem = end($response);
                $rrdId = $lastItem['rrd_id'] ?? 0;
                
                $iteration++;
                
                Log::info('WB RealizationReport: fetched chunk', [
                    'iteration' => $iteration,
                    'count' => count($response),
                    'total' => count($allData),
                    'last_rrd_id' => $rrdId,
                ]);
                
            } while (count($response) >= $limit && $iteration < $maxIterations);
            
            Log::info('WB RealizationReport: completed', [
                'total_records' => count($allData),
                'iterations' => $iteration,
            ]);
            
            return $allData;
            
        } catch (\Exception $e) {
            Log::error('WB RealizationReport error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить фактические начисления за хранение по SKU из отчёта реализации
     * 
     * ОПТИМИЗИРОВАНО: Обрабатывает данные по частям для экономии памяти.
     * Не загружает весь отчёт в память, а агрегирует данные по мере получения.
     * 
     * @param int $weeks Количество недель для анализа (по умолчанию 4)
     * @return array [barcode => [
     *   'storage_fee_total' => float,    // Сумма за весь период
     *   'storage_fee_last_week' => float, // За последнюю неделю
     *   'report_date_from' => string,
     *   'report_date_to' => string,
     *   'nm_id' => int,
     *   'sa_name' => string,
     * ]]
     */
    public function getStorageFeesBySku(int $weeks = 4): array
    {
        $dateTo = now()->format('Y-m-d');
        $dateFrom = now()->subWeeks($weeks)->format('Y-m-d');
        
        $result = [];
        $rrdId = 0;
        $limit = 50000; // Уменьшаем лимит для экономии памяти
        $maxIterations = 100;
        $iteration = 0;
        $totalProcessed = 0;
        
        try {
            do {
                $response = $this->client->statisticsGet('/api/v5/supplier/reportDetailByPeriod', [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'rrdid' => $rrdId,
                    'limit' => $limit,
                    'periodicity' => 'weekly',
                ]);
                
                if (empty($response)) {
                    break;
                }
                
                // Обрабатываем данные сразу, не накапливая в памяти
                foreach ($response as $item) {
                    $barcode = $item['barcode'] ?? null;
                    $nmId = $item['nm_id'] ?? null;
                    $saName = $item['sa_name'] ?? null;
                    
                    $key = $barcode ?: (string)$nmId ?: $saName;
                    if (!$key) continue;
                    
                    $storageFee = (float)($item['storage_fee'] ?? 0);
                    $reportId = $item['realizationreport_id'] ?? null;
                    
                    if (!isset($result[$key])) {
                        $result[$key] = [
                            'storage_fee_total' => 0,
                            'storage_fee_last_week' => 0,
                            'report_date_from' => $item['date_from'] ?? null,
                            'report_date_to' => $item['date_to'] ?? null,
                            'nm_id' => $nmId,
                            'sa_name' => $saName,
                            'barcode' => $barcode,
                            'last_report_id' => null,
                        ];
                    }
                    
                    $result[$key]['storage_fee_total'] += $storageFee;
                    
                    if ($item['date_from'] && (!$result[$key]['report_date_from'] || $item['date_from'] < $result[$key]['report_date_from'])) {
                        $result[$key]['report_date_from'] = $item['date_from'];
                    }
                    if ($item['date_to'] && (!$result[$key]['report_date_to'] || $item['date_to'] > $result[$key]['report_date_to'])) {
                        $result[$key]['report_date_to'] = $item['date_to'];
                    }
                    
                    if ($reportId && (!$result[$key]['last_report_id'] || $reportId > $result[$key]['last_report_id'])) {
                        $result[$key]['last_report_id'] = $reportId;
                        $result[$key]['storage_fee_last_week'] = $storageFee;
                    }
                }
                
                $lastItem = end($response);
                $rrdId = $lastItem['rrd_id'] ?? 0;
                $totalProcessed += count($response);
                $iteration++;
                
                // Освобождаем память
                unset($response);
                
                Log::debug('WB getStorageFeesBySku: chunk processed', [
                    'iteration' => $iteration,
                    'total_processed' => $totalProcessed,
                    'unique_skus' => count($result),
                ]);
                
            } while ($rrdId > 0 && $iteration < $maxIterations);
            
            // Округляем значения и убираем служебные поля
            foreach ($result as $key => &$data) {
                $data['storage_fee_total'] = round($data['storage_fee_total'], 2);
                $data['storage_fee_last_week'] = round($data['storage_fee_last_week'], 2);
                unset($data['last_report_id']);
            }
            
            Log::info('WB getStorageFeesBySku: completed', [
                'count' => count($result),
                'total_processed' => $totalProcessed,
                'iterations' => $iteration,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('WB getStorageFeesBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
