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
     * @param  array<int, string>  $fields  Опциональный список полей для уменьшения ответа.
     */
    public function getDetailedSalesReportsByPeriod(
        string $dateFrom,
        string $dateTo,
        array $fields = []
    ): array {
        try {
            $allRows = [];
            $rrdId = 0;
            $limit = 100000;

            do {
                $payload = [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'limit' => $limit,
                    'rrdId' => $rrdId,
                    'period' => 'weekly',
                ];
                if ($fields !== []) {
                    $payload['fields'] = array_values(array_unique(array_merge(['rrdId'], $fields)));
                }

                $rows = $this->client->financePost('/api/finance/v1/sales-reports/detailed', $payload);
                if (! is_array($rows) || $rows === []) {
                    break;
                }

                $allRows = array_merge($allRows, $rows);
                $last = end($rows);
                $nextRrdId = (int) ($last['rrdId'] ?? 0);
                if (count($rows) < $limit || $nextRrdId <= $rrdId) {
                    break;
                }
                $rrdId = $nextRrdId;

                // Finance reports are limited to one request per minute. A
                // second page without this delay would turn a complete report
                // into a silently partial one.
                sleep(61);
            } while (true);

            return $allRows;
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
     * @param  string  $dateFrom  Начало периода (YYYY-MM-DD)
     * @param  string  $dateTo  Конец периода (YYYY-MM-DD)
     * @param  string  $periodicity  weekly|daily
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
     * @param  int  $weeks  Количество недель для анализа (по умолчанию 4)
     * @return array [barcode => [
     *               'storage_fee_total' => float,    // Сумма за весь период
     *               'storage_fee_last_week' => float, // За последнюю неделю
     *               'report_date_from' => string,
     *               'report_date_to' => string,
     *               'nm_id' => int,
     *               'sa_name' => string,
     *               ]]
     */
    public function getStorageFeesBySku(int $weeks = 4): array
    {
        return $this->getStorageAndAcquiringBySku($weeks)['storage_by_sku'];
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
     *   avg: float,                    // средневзвешенный эквайринг по магазину, %
     *   observed_at: string            // реальное время получения отчёта API
     * }
     */
    public function getAcquiringBySku(int $weeks = 4): array
    {
        return $this->getStorageAndAcquiringBySku($weeks)['acquiring'];
    }

    /**
     * Один запрос к Finance API для хранения и эквайринга.
     *
     * Раньше storage job забирал минутный/многочасовой лимит отчёта, а следующий
     * unit-economics sync немедленно запрашивал тот же отчёт ради эквайринга и
     * получал 429. Объединённый снимок устраняет гонку и даёт очереди точный
     * retry_after для длинного cooldown.
     *
     * @return array{
     *   status: 'success'|'rate_limited'|'unavailable',
     *   retry_after: ?int,
     *   storage_by_sku: array<string,array<string,mixed>>,
     *   acquiring: array{by_sku: array<string,float>, avg: float, observed_at: ?string}
     * }
     */
    public function getStorageAndAcquiringBySku(int $weeks = 4): array
    {
        // Только ЗАВЕРШЁННЫЕ недельные отчёты, текущая (неполная) неделя исключена —
        // как считает менеджер вручную («за три недели, исключая эту»). WB-неделя
        // Пн–Вс; берём $weeks полных недель до прошлого воскресенья включительно.
        $dateTo = now()->startOfWeek()->subDay()->format('Y-m-d');         // прошлое воскресенье
        $dateFrom = now()->startOfWeek()->subWeeks($weeks)->format('Y-m-d'); // понедельник N недель назад

        try {
            $rows = $this->getDetailedSalesReportsByPeriod($dateFrom, $dateTo, [
                'reportId',
                'dateFrom',
                'dateTo',
                'sku',
                'nmId',
                'vendorCode',
                'paidStorage',
                'retailAmount',
                'acquiringFee',
            ]);

            $responseStatus = $this->client->getLastResponseStatus();
            $retryAfter = $this->client->getLastRateLimitRetryAfter();
            if ($responseStatus === 429) {
                return $this->emptyCombinedResult('rate_limited', $retryAfter);
            }
            if ($responseStatus !== null && ($responseStatus < 200 || $responseStatus >= 300)) {
                return $this->emptyCombinedResult('unavailable');
            }

            $observedAt = now()->utc()->toIso8601String();
            $storage = $this->aggregateStorage($rows);
            $acquiring = $this->aggregateAcquiring($rows, $observedAt);

            Log::info('WB Finance storage/acquiring snapshot completed', [
                'rows' => count($rows),
                'storage_keys' => count($storage),
                'acquiring_keys' => count($acquiring['by_sku']),
                'acquiring_avg_percent' => $acquiring['avg'],
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            return [
                'status' => 'success',
                'retry_after' => null,
                'storage_by_sku' => $storage,
                'acquiring' => $acquiring,
            ];
        } catch (\Throwable $e) {
            Log::error('WB Finance storage/acquiring snapshot error', ['error' => $e->getMessage()]);

            return $this->emptyCombinedResult('unavailable');
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function aggregateStorage(array $rows): array
    {
        $result = [];
        foreach ($rows as $item) {
            $sku = $item['sku'] ?? null;
            $nmId = $item['nmId'] ?? null;
            $vendorCode = $item['vendorCode'] ?? null;
            $storageFee = (float) ($item['paidStorage'] ?? 0);
            $reportId = (int) ($item['reportId'] ?? 0);

            foreach ([$sku, $nmId, $vendorCode] as $rawKey) {
                if ($rawKey === null || $rawKey === '') {
                    continue;
                }
                $key = (string) $rawKey;
                $result[$key] ??= [
                    'storage_fee_total' => 0.0,
                    'storage_fee_last_week' => 0.0,
                    'report_date_from' => $item['dateFrom'] ?? null,
                    'report_date_to' => $item['dateTo'] ?? null,
                    'nm_id' => $nmId,
                    'sa_name' => $vendorCode,
                    'barcode' => $sku,
                    '_by_report' => [],
                ];
                $result[$key]['storage_fee_total'] += $storageFee;
                $result[$key]['_by_report'][$reportId] = ($result[$key]['_by_report'][$reportId] ?? 0) + $storageFee;
            }
        }

        foreach ($result as &$data) {
            $latestReportId = $data['_by_report'] === [] ? null : max(array_keys($data['_by_report']));
            $data['storage_fee_last_week'] = $latestReportId === null ? 0.0 : $data['_by_report'][$latestReportId];
            $data['storage_fee_total'] = round($data['storage_fee_total'], 2);
            $data['storage_fee_last_week'] = round($data['storage_fee_last_week'], 2);
            unset($data['_by_report']);
        }
        unset($data);

        return $result;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function aggregateAcquiring(array $rows, string $observedAt): array
    {
        $agg = [];
        $totalAcquiring = 0.0;
        $totalRevenue = 0.0;

        foreach ($rows as $item) {
            $acquiring = (float) ($item['acquiringFee'] ?? 0);
            $revenue = (float) ($item['retailAmount'] ?? 0);

            foreach ([$item['sku'] ?? null, $item['nmId'] ?? null, $item['vendorCode'] ?? null] as $rawKey) {
                if ($rawKey === null || $rawKey === '') {
                    continue;
                }
                $key = (string) $rawKey;
                $agg[$key] ??= ['acq' => 0.0, 'rev' => 0.0];
                $agg[$key]['acq'] += $acquiring;
                $agg[$key]['rev'] += $revenue;
            }

            $totalAcquiring += $acquiring;
            $totalRevenue += $revenue;
        }

        $bySku = [];
        foreach ($agg as $key => $values) {
            if ($values['rev'] > 0) {
                $bySku[$key] = round(max(0.0, min(10.0, ($values['acq'] / $values['rev']) * 100)), 2);
            }
        }

        return [
            'by_sku' => $bySku,
            'avg' => $totalRevenue > 0
                ? round(max(0.0, min(10.0, ($totalAcquiring / $totalRevenue) * 100)), 2)
                : 0.0,
            'observed_at' => $observedAt,
        ];
    }

    private function emptyCombinedResult(string $status, ?int $retryAfter = null): array
    {
        return [
            'status' => $status,
            'retry_after' => $retryAfter,
            'storage_by_sku' => [],
            'acquiring' => ['by_sku' => [], 'avg' => 0.0, 'observed_at' => null],
        ];
    }
}
