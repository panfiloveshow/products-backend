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

    /**
     * Фактический эквайринг по SKU из отчёта реализации.
     *
     * WB удерживает эквайринг (acquiring_fee) по каждой строке отчёта.
     * Считаем эффективную ставку: Σ acquiring_fee / Σ выручка (retail_amount) × 100.
     * Возврат (doc_type Возврат) приходит отрицательной выручкой/комиссией — суммы
     * net-ятся естественно.
     *
     * @return array{
     *   by_sku: array<string,float>,   // barcode/nm_id/sa_name => acquiring %
     *   avg: float                     // средневзвешенный эквайринг по магазину, %
     * }
     */
    public function getAcquiringBySku(int $weeks = 4): array
    {
        $dateTo = now()->subDays(1)->format('Y-m-d');
        $dateFrom = now()->subWeeks($weeks)->format('Y-m-d');

        $agg = [];               // key => ['acq' => float, 'rev' => float]
        $totalAcq = 0.0;
        $totalRev = 0.0;
        $rrdId = 0;
        $limit = 50000;
        $maxIterations = 100;
        $iteration = 0;

        try {
            do {
                // Отчёт жёстко лимитирован (429) — ретраим внутри statisticsGet.
                $response = $this->client->statisticsGet('/api/v5/supplier/reportDetailByPeriod', [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'rrdid' => $rrdId,
                    'limit' => $limit,
                ], 3);

                if (empty($response) || ! is_array($response)) {
                    break;
                }

                foreach ($response as $item) {
                    $acquiring = (float) ($item['acquiring_fee'] ?? 0);
                    $revenue = (float) ($item['retail_amount'] ?? 0);

                    // Ключи, по которым WB-товар матчится на product.sku (=barcode).
                    foreach ([$item['barcode'] ?? null, $item['nm_id'] ?? null, $item['sa_name'] ?? null] as $key) {
                        if ($key === null || $key === '') {
                            continue;
                        }
                        $key = (string) $key;
                        if (! isset($agg[$key])) {
                            $agg[$key] = ['acq' => 0.0, 'rev' => 0.0];
                        }
                        $agg[$key]['acq'] += $acquiring;
                        $agg[$key]['rev'] += $revenue;
                    }

                    $totalAcq += $acquiring;
                    $totalRev += $revenue;
                }

                $lastItem = end($response);
                $rrdId = (int) ($lastItem['rrd_id'] ?? 0);
                $iteration++;
                unset($response);
            } while ($rrdId > 0 && $iteration < $maxIterations);

            $bySku = [];
            foreach ($agg as $key => $v) {
                if ($v['rev'] > 0) {
                    // Кап 0–10%: защита от мусорных строк (корректировки, нулевая выручка).
                    $pct = max(0.0, min(10.0, ($v['acq'] / $v['rev']) * 100));
                    $bySku[$key] = round($pct, 2);
                }
            }

            $avg = $totalRev > 0 ? round(max(0.0, min(10.0, ($totalAcq / $totalRev) * 100)), 2) : 0.0;

            Log::info('WB getAcquiringBySku: completed', [
                'skus' => count($bySku),
                'avg_percent' => $avg,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            return ['by_sku' => $bySku, 'avg' => $avg];
        } catch (\Exception $e) {
            Log::error('WB getAcquiringBySku error', ['error' => $e->getMessage()]);

            return ['by_sku' => [], 'avg' => 0.0];
        }
    }
}
