<?php

namespace App\Services\Ozon;

use App\Models\OzonAdStat;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OzonPerformanceApiService
{
    private const BASE_URL = 'https://api-performance.ozon.ru';

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function checkCredentials(array $credentials): array
    {
        $missing = $this->missingCredentialsResponse($credentials);
        if ($missing !== null) {
            return $missing;
        }

        try {
            $token = $this->requestAccessToken($credentials);

            return [
                'success' => $token['success'],
                'status' => $token['success'] ? 'ok' : 'auth_failed',
                'http_status' => $token['http_status'],
                'message' => $token['success']
                    ? 'Ozon Performance API доступен'
                    : $token['message'],
                'token_received' => $token['access_token'] !== '',
                'token_type' => $token['token_type'],
                'expires_in' => $token['expires_in'],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'request_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Диагностический сырой дамп per-SKU путей Ozon Performance API.
     *
     * НЕ для прода. Используется artisan-командой ozon:debug-cpc, чтобы понять,
     * что именно отдают эндпоинты статистики кампания/товар и какой из них
     * реально содержит товарные (SKU) строки. Возвращает сырые HTTP-ответы
     * (статус, content-type, начало body, структуру JSON) без какой-либо
     * нормализации/маппинга — чтобы увидеть форму данных как есть.
     *
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function debugCampaignProductRaw(
        array $credentials,
        string $dateFrom,
        string $dateTo,
        int $campaignSample = 2
    ): array {
        $missing = $this->missingCredentialsResponse($credentials);
        if ($missing !== null) {
            return ['success' => false, 'stage' => 'credentials', 'detail' => $missing];
        }

        $token = $this->requestAccessToken($credentials);
        if (! $token['success']) {
            return ['success' => false, 'stage' => 'auth', 'detail' => $token];
        }
        $accessToken = $token['access_token'];

        $campaigns = $this->fetchCampaigns($accessToken);
        $campaignList = $campaigns['list'];
        $sample = array_slice($campaignList, 0, max(1, $campaignSample));
        $sampleIds = array_values(array_filter(array_map(
            static fn (array $c): string => (string) ($c['id'] ?? ''),
            $sample
        )));

        $result = [
            'success' => true,
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'base_url' => self::BASE_URL,
            'auth' => [
                'http_status' => $token['http_status'],
                'token_received' => $accessToken !== '',
            ],
            'campaigns' => [
                'total' => $campaigns['total'],
                'loaded' => count($campaignList),
                'states' => $this->countBy($campaignList, 'state'),
                'types' => $this->countBy($campaignList, 'advObjectType'),
                'sample' => array_map([$this, 'compactCampaign'], $sample),
            ],
            'sync_campaign_product' => [],
            'async_statistics' => null,
        ];

        // 1) Синхронные эндпоинты campaign/product (CSV и JSON) по сэмплу кампаний.
        foreach ($sampleIds as $campaignId) {
            $queryString = $this->campaignProductStatsQueryString($dateFrom, $dateTo, [$campaignId]);

            $csvResponse = Http::timeout(30)
                ->withToken($accessToken)
                ->accept('*/*')
                ->get(self::BASE_URL . '/api/client/statistics/campaign/product?' . $queryString);
            $csvBody = (string) $csvResponse->body();

            $jsonResponse = $this->authorized($accessToken)
                ->get(self::BASE_URL . '/api/client/statistics/campaign/product/json?' . $queryString);
            $jsonPayload = $jsonResponse->json();
            $jsonRows = is_array($jsonPayload) ? $this->extractRows($jsonPayload) : [];

            $result['sync_campaign_product'][] = [
                'campaign_id' => $campaignId,
                'query_string' => $queryString,
                'csv' => [
                    'endpoint' => '/api/client/statistics/campaign/product',
                    'http_status' => $csvResponse->status(),
                    'content_type' => $csvResponse->header('content-type'),
                    'body_length' => strlen($csvBody),
                    'looks_like_product_header' => $this->debugCsvHasProductHeader($csvBody),
                    'first_lines' => $this->debugRawLines($csvBody, 30),
                ],
                'json' => [
                    'endpoint' => '/api/client/statistics/campaign/product/json',
                    'http_status' => $jsonResponse->status(),
                    'content_type' => $jsonResponse->header('content-type'),
                    'top_level_keys' => is_array($jsonPayload) ? array_keys($jsonPayload) : null,
                    'extracted_rows_count' => count($jsonRows),
                    'first_row_keys' => isset($jsonRows[0]) && is_array($jsonRows[0])
                        ? array_keys($jsonRows[0])
                        : [],
                    'first_row_has_sku' => isset($jsonRows[0]) && is_array($jsonRows[0])
                        ? $this->hasCampaignProductIdentifier($jsonRows[0])
                        : false,
                    'first_row_sample' => $jsonRows[0] ?? null,
                    'raw_excerpt' => mb_substr((string) $jsonResponse->body(), 0, 2000),
                ],
            ];
        }

        // 2) Асинхронный отчёт по товарной кампании (per-SKU): POST /api/client/statistics → UUID → poll.
        $result['async_statistics'] = $this->debugAsyncCampaignStatistics(
            $accessToken,
            $sampleIds,
            $dateFrom,
            $dateTo
        );

        return $result;
    }

    /**
     * Пробует асинхронную генерацию статистики по товарной кампании и дампит сырой ответ.
     *
     * @param array<int, string> $campaignIds
     * @return array<string, mixed>
     */
    private function debugAsyncCampaignStatistics(
        string $accessToken,
        array $campaignIds,
        string $dateFrom,
        string $dateTo
    ): array {
        if ($campaignIds === []) {
            return ['attempted' => false, 'reason' => 'нет кампаний в сэмпле'];
        }

        $requestBody = [
            'campaigns' => array_values($campaignIds),
            'from' => $this->toRfc3339Start($dateFrom),
            'to' => $this->toRfc3339End($dateTo),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'groupBy' => 'NO',
        ];

        $generate = $this->authorized($accessToken)
            ->post(self::BASE_URL . '/api/client/statistics', $requestBody);
        $generatePayload = $generate->json();
        $uuid = (string) ($generate->json('UUID') ?? $generate->json('uuid') ?? '');

        $out = [
            'attempted' => true,
            'generate' => [
                'endpoint' => 'POST /api/client/statistics',
                'request_body' => $requestBody,
                'http_status' => $generate->status(),
                'content_type' => $generate->header('content-type'),
                'top_level_keys' => is_array($generatePayload) ? array_keys($generatePayload) : null,
                'uuid' => $uuid !== '' ? $uuid : null,
                'raw_excerpt' => mb_substr((string) $generate->body(), 0, 1500),
            ],
            'poll' => [],
            'download' => null,
        ];

        if (! $generate->successful() || $uuid === '') {
            return $out;
        }

        // Поллинг до 12 попыток с шагом ~5с (~1 мин). Для дебага этого достаточно.
        $link = '';
        $state = '';
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $statusResponse = $this->authorized($accessToken)
                ->get(self::BASE_URL . '/api/client/statistics/' . rawurlencode($uuid));
            $statusPayload = $statusResponse->json();
            $state = (string) ($statusPayload['state'] ?? '');
            $link = $this->absolutePerformanceUrl((string) ($statusPayload['link'] ?? ''));

            $out['poll'][] = [
                'attempt' => $attempt,
                'http_status' => $statusResponse->status(),
                'state' => $state ?: null,
                'has_link' => $link !== '',
                'kind' => $statusPayload['kind'] ?? null,
            ];

            if ($state === 'OK' && $link !== '') {
                break;
            }
            if (in_array($state, ['ERROR', 'CANCELLED'], true)) {
                break;
            }

            usleep(5_000_000);
        }

        if ($state !== 'OK' || $link === '') {
            return $out;
        }

        $download = Http::timeout(60)
            ->withToken($accessToken)
            ->accept('*/*')
            ->get($link);
        $downloadBody = (string) $download->body();

        $out['download'] = [
            'http_status' => $download->status(),
            'content_type' => $download->header('content-type'),
            'body_length' => strlen($downloadBody),
            'looks_like_zip' => str_starts_with($downloadBody, "PK\x03\x04"),
            'looks_like_product_header' => $this->debugCsvHasProductHeader($downloadBody),
            'first_lines' => $this->debugRawLines($downloadBody, 30),
        ];

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function debugRawLines(string $body, int $limit): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];

        return array_slice(array_map(
            static fn (string $line): string => mb_substr($line, 0, 500),
            $lines
        ), 0, $limit);
    }

    private function debugCsvHasProductHeader(string $body): bool
    {
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        foreach (array_slice($lines, 0, 5) as $line) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', trim($line)) ?? '';
            if ($line === '') {
                continue;
            }
            $cells = array_map(
                static fn ($c): string => trim((string) $c),
                str_getcsv($line, ';', '"', '\\')
            );
            if ($this->looksLikeCampaignProductCsvHeader($cells)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function advertisingSummary(
        array $credentials,
        string $dateFrom,
        string $dateTo,
        int $campaignLimit = 50
    ): array {
        $missing = $this->missingCredentialsResponse($credentials);
        if ($missing !== null) {
            return $missing;
        }

        try {
            $token = $this->requestAccessToken($credentials);
            if (! $token['success']) {
                return [
                    'success' => false,
                    'status' => 'auth_failed',
                    'message' => $token['message'],
                    'http_status' => $token['http_status'],
                ];
            }

            $campaigns = $this->fetchCampaigns($token['access_token']);
            $campaignList = array_slice($campaigns['list'], 0, max(1, min(100, $campaignLimit)));
            $campaignIds = array_values(array_filter(array_map(
                static fn (array $campaign): string => (string) ($campaign['id'] ?? ''),
                $campaignList
            )));

            $stats = $this->fetchProductCampaignStats($token['access_token'], $dateFrom, $dateTo, $campaignIds);
            $limits = $this->fetchBidLimits($token['access_token']);

            return [
                'success' => true,
                'status' => 'ok',
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
                'campaigns' => [
                    'total' => $campaigns['total'],
                    'loaded' => count($campaignList),
                    'states' => $this->countBy($campaigns['list'], 'state'),
                    'types' => $this->countBy($campaigns['list'], 'advObjectType'),
                    'placements' => $this->countPlacements($campaigns['list']),
                    'sample' => array_map([$this, 'compactCampaign'], array_slice($campaignList, 0, 10)),
                ],
                'statistics' => [
                    'row_count' => count($stats['rows']),
                    'totals' => $stats['totals'],
                    'derived' => $stats['derived'],
                    'top_by_spend' => $stats['top_by_spend'],
                    'field_keys' => $stats['field_keys'],
                    'source' => $stats['source'],
                    'source_error' => $stats['source_error'],
                ],
                'bid_limits' => [
                    'http_status' => $limits['http_status'],
                    'groups_count' => count($limits['limits']),
                    'sample' => array_slice($limits['limits'], 0, 5),
                ],
                'usage_for_products' => [
                    'available' => true,
                    'method' => 'POST /api/client/statistic/products/generate',
                    'note' => 'Товарная детализация доступна отдельным асинхронным отчётом Ozon: SKU, артикул, название, категория, заказы, выручка, расход, ДРР.',
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'request_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function campaignObjects(array $credentials, string $campaignId): array
    {
        $missing = $this->missingCredentialsResponse($credentials);
        if ($missing !== null) {
            return $missing;
        }

        try {
            $token = $this->requestAccessToken($credentials);
            if (! $token['success']) {
                return [
                    'success' => false,
                    'status' => 'auth_failed',
                    'message' => $token['message'],
                    'http_status' => $token['http_status'],
                ];
            }

            $response = $this->authorized($token['access_token'])
                ->get(self::BASE_URL . "/api/client/campaign/{$campaignId}/objects");
            $payload = $response->json();
            $objects = $this->extractList($payload);

            return [
                'success' => $response->successful(),
                'status' => $response->successful() ? 'ok' : 'request_failed',
                'http_status' => $response->status(),
                'campaign_id' => $campaignId,
                'count' => count($objects),
                'objects' => $objects,
                'field_keys' => isset($objects[0]) && is_array($objects[0])
                    ? array_keys($objects[0])
                    : [],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'request_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function requestProductStatisticsReport(array $credentials, string $dateFrom, string $dateTo): array
    {
        $missing = $this->missingCredentialsResponse($credentials);
        if ($missing !== null) {
            return $missing;
        }

        try {
            $token = $this->requestAccessToken($credentials);
            if (! $token['success']) {
                return [
                    'success' => false,
                    'status' => 'auth_failed',
                    'message' => $token['message'],
                    'http_status' => $token['http_status'],
                ];
            }

            // Бэкофф на 429 (rate limit Ozon): транзиентный лимит не должен ронять всю рекламную
            // цепочку (фронт получал 422 и не загружал рекламу совсем).
            $response = $this->authorized($token['access_token'])
                ->post(self::BASE_URL . '/api/client/statistic/products/generate', [
                    'from' => $this->toRfc3339Start($dateFrom),
                    'to' => $this->toRfc3339End($dateTo),
                ]);
            for ($attempt = 1; $attempt <= 2 && $response->status() === 429; $attempt++) {
                sleep(5 * $attempt);
                $response = $this->authorized($token['access_token'])
                    ->post(self::BASE_URL . '/api/client/statistic/products/generate', [
                        'from' => $this->toRfc3339Start($dateFrom),
                        'to' => $this->toRfc3339End($dateTo),
                    ]);
            }

            return [
                'success' => $response->successful(),
                'status' => $response->successful() ? 'queued' : 'request_failed',
                'http_status' => $response->status(),
                'message' => $response->successful()
                    ? 'Отчёт по товарам запрошен в Ozon Performance API'
                    : $this->errorMessage($response->json()),
                'uuid' => $response->json('UUID') ?? $response->json('uuid'),
                'vendor' => $response->json('vendor'),
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
                'expected_fields' => [
                    'SKU',
                    'Артикул',
                    'Название',
                    'Категория',
                    'Заказы',
                    'Выручка',
                    'Расход',
                    'ДРР',
                    'В корзину',
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'request_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function reportStatus(array $credentials, string $uuid): array
    {
        $missing = $this->missingCredentialsResponse($credentials);
        if ($missing !== null) {
            return $missing;
        }

        try {
            $token = $this->requestAccessToken($credentials);
            if (! $token['success']) {
                return [
                    'success' => false,
                    'status' => 'auth_failed',
                    'message' => $token['message'],
                    'http_status' => $token['http_status'],
                ];
            }

            $response = $this->authorized($token['access_token'])
                ->get(self::BASE_URL . '/api/client/statistics/' . rawurlencode($uuid));
            $payload = $response->json();
            $link = (string) ($payload['link'] ?? '');
            $downloadUrl = $this->absolutePerformanceUrl($link);

            return [
                'success' => $response->successful(),
                'status' => $response->successful()
                    ? strtolower((string) ($payload['state'] ?? 'ok'))
                    : 'request_failed',
                'http_status' => $response->status(),
                'uuid' => $payload['UUID'] ?? $uuid,
                'state' => $payload['state'] ?? null,
                'kind' => $payload['kind'] ?? null,
                'created_at' => $payload['createdAt'] ?? null,
                'updated_at' => $payload['updatedAt'] ?? null,
                'has_download_link' => $downloadUrl !== '',
                'link' => $downloadUrl,
                'request' => $payload['request'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'request_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function productReportPreview(array $credentials, string $uuid, int $limit = 50): array
    {
        $missing = $this->missingCredentialsResponse($credentials);
        if ($missing !== null) {
            return $missing;
        }

        try {
            $token = $this->requestAccessToken($credentials);
            if (! $token['success']) {
                return [
                    'success' => false,
                    'status' => 'auth_failed',
                    'message' => $token['message'],
                    'http_status' => $token['http_status'],
                ];
            }

            $download = $this->downloadReadyProductReport($token['access_token'], $uuid);
            if (! ($download['success'] ?? false)) {
                return $download;
            }

            $parsed = $this->parseProductReportCsvPreview((string) $download['body'], $limit);

            return [
                'success' => true,
                'status' => 'ok',
                'uuid' => $uuid,
                'state' => 'OK',
                'content_type' => $download['content_type'] ?? null,
                'header' => $parsed['header'],
                'rows' => $parsed['rows'],
                'rows_count' => count($parsed['rows']),
                'truncated' => $parsed['truncated'],
                'field_mapping_hint' => [
                    'sku' => 'SKU',
                    'offer_id' => 'Артикул',
                    'product_name' => 'Название товара',
                    'category' => 'Категория товара',
                    'ad_enabled' => 'Продвижение',
                    'price' => 'Цена товара, ₽',
                    'orders' => 'Заказы',
                    'revenue' => 'Продажи',
                    'ad_spend' => 'Расход',
                    'drr' => 'ДРР',
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'request_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function productAdvertisingImpact(
        array $credentials,
        string $uuid,
        int $limit = 5000,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $integrationId = null,
        bool $forceRefresh = false
    ): array
    {
        $missing = $this->missingCredentialsResponse($credentials);
        if ($missing !== null) {
            return $missing;
        }

        try {
            $token = $this->requestAccessToken($credentials);
            if (! $token['success']) {
                return [
                    'success' => false,
                    'status' => 'auth_failed',
                    'message' => $token['message'],
                    'http_status' => $token['http_status'],
                ];
            }

            $download = $this->downloadReadyProductReport($token['access_token'], $uuid);
            if (! ($download['success'] ?? false)) {
                return $download;
            }

            $parsed = $this->parseProductReportCsvPreview((string) $download['body'], $limit);
            $productReportSkuMap = $this->buildProductReportSkuMap($parsed['rows']);
            if ($integrationId !== null && $integrationId > 0) {
                $productReportSkuMap = $this->mergeProductSkuMaps(
                    $productReportSkuMap,
                    $this->buildLocalProductSkuMap($integrationId)
                );
            }
            $campaignStats = null;
            $campaignReportRows = [];

            if ($dateFrom !== null && $dateTo !== null) {
                $campaigns = $this->fetchCampaigns($token['access_token']);
                // Только товарные (SKU) кампании: per-SKU отчёт возможен лишь для них. Нетоварные
                // типы (REF_VK, SEARCH_PROMO и пр.) Ozon отбивает 400 на генерации товарного отчёта.
                $productCampaigns = array_values(array_filter(
                    $campaigns['list'],
                    fn (array $campaign): bool => $this->isProductCampaign($campaign)
                ));
                $campaignIds = array_values(array_filter(array_map(
                    static fn (array $campaign): string => (string) ($campaign['id'] ?? ''),
                    $productCampaigns !== [] ? $productCampaigns : $campaigns['list']
                )));

                // Async-отчёты Ozon (генерация + поллинг) длятся минуты и превышают таймауты
                // php-fpm/nginx, поэтому в проде (известная интеграция) собираем их ПРОГРЕССИВНО:
                // каждый HTTP-запрос делает короткий шаг (создать отчёты / проверить готовность /
                // скачать готовые) и быстро отвечает; пока не готово — source='pending'.
                // Для CLI/тестов (integrationId<=0) остаётся ограниченный синхронный путь.
                if ($integrationId !== null && $integrationId > 0) {
                    $campaignStats = $this->resolveCampaignStatsProgressive(
                        $integrationId,
                        $token['access_token'],
                        $dateFrom,
                        $dateTo,
                        $campaignIds,
                        $forceRefresh
                    );
                } else {
                    $campaignStats = $this->fetchProductCampaignStats(
                        $token['access_token'],
                        $dateFrom,
                        $dateTo,
                        $campaignIds,
                        true
                    );
                }
                $campaignReportRows = $this->campaignStatsRowsToProductReportRows(
                    $campaignStats['rows'],
                    $productReportSkuMap
                );
            }

            $impact = $this->buildProductAdvertisingImpact(array_merge($parsed['rows'], $campaignReportRows));
            $mappedCampaignProductRows = count(array_filter(
                $campaignReportRows,
                static fn (array $row): bool => (string) ($row['_mapping_status'] ?? '') !== 'unmapped_ozon_sku'
            ));

            return [
                'success' => true,
                'status' => 'ok',
                'uuid' => $uuid,
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
                'integration_id' => $integrationId,
                'content_type' => $download['content_type'] ?? null,
                'rows_count' => count($parsed['rows']),
                'campaign_rows_count' => $campaignStats !== null ? count($campaignStats['rows']) : 0,
                'campaign_product_rows_count' => count($campaignReportRows),
                'truncated' => $parsed['truncated'],
                'coverage' => [
                    'sources' => array_values(array_filter([
                        'product_report_payment_for_order',
                        $campaignStats !== null ? 'campaign_product_stats_cpc' : null,
                    ])),
                    'product_report_rows' => count($parsed['rows']),
                    'campaign_stat_rows' => $campaignStats !== null ? count($campaignStats['rows']) : 0,
                    'campaign_product_rows' => count($campaignReportRows),
                    'mapped_campaign_product_rows' => $mappedCampaignProductRows,
                    'unmapped_campaign_product_rows' => max(0, count($campaignReportRows) - $mappedCampaignProductRows),
                    'campaign_stats_source' => $campaignStats['source'] ?? null,
                    'campaign_stats_from_cache' => (bool) ($campaignStats['from_cache'] ?? false),
                    'campaign_stats_from_storage' => (bool) ($campaignStats['from_storage'] ?? false),
                    'campaign_stats_fetched_at' => $campaignStats['fetched_at'] ?? null,
                    'campaign_stats_pending' => (bool) ($campaignStats['pending'] ?? false),
                    'campaign_stats_source_error' => $campaignStats['source_error'] ?? null,
                    'campaign_stat_field_keys' => $campaignStats['field_keys'] ?? [],
                    'campaign_sample_rows' => $campaignStats['sample_rows'] ?? [],
                    'unmapped_campaign_keys' => $this->unmappedCampaignKeys($campaignReportRows),
                    'local_product_aliases' => count($productReportSkuMap['by_sku'] ?? []),
                    'local_product_aliases_count' => count($productReportSkuMap['by_sku'] ?? []),
                    'local_product_alias_examples' => array_slice(array_keys($productReportSkuMap['by_sku'] ?? []), 0, 5),
                    'campaign_totals' => $campaignStats['totals'] ?? null,
                    'note' => $campaignStats !== null
                        ? 'Сводка объединяет товарный UUID-отчёт и статистику товарных PPC-кампаний Ozon за тот же период. CPC-строки сопоставляются с товарами по Ozon SKU и артикулу продавца.'
                        : 'Сводка построена только по UUID-отчёту Ozon. Для кликов/CTR/CPC передайте date_from и date_to.',
                ],
                'summary' => $impact['summary'],
                'products' => $impact['products'],
                'by_offer_id' => $impact['by_offer_id'],
                'by_ozon_sku' => $impact['by_ozon_sku'],
                'usage_for_unit_economics' => [
                    'primary_match_key' => 'offer_id',
                    'fallback_match_key' => 'ozon_sku',
                    'aliases_match_key' => 'aliases',
                    'profit_after_ads_formula' => 'net_profit_after_ads = net_profit - ad_spend_per_order_or_drr_amount',
                    'recommended_fields' => [
                        'ad_spend',
                        'ad_revenue',
                        'ad_orders',
                        'ad_drr_percent',
                        'ad_spend_per_order',
                        'ad_enabled',
                    ],
                ],
                'usage_for_auto_supply' => [
                    'ads_driven_demand' => 'Снижать уверенность прогноза, если значимая часть заказов пришла из рекламы.',
                    'high_ad_cost' => 'Ограничивать поставку или требовать проверки, если ДРР высокий.',
                    'ad_active' => 'Если реклама выключена, не переносить рекламный всплеск в будущий спрос.',
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'request_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>|null
     */
    private function missingCredentialsResponse(array $credentials): ?array
    {
        $clientId = trim((string) ($credentials['performance_api_key'] ?? ''));
        $clientSecret = trim((string) ($credentials['performance_client_secret'] ?? ''));

        if ($clientId !== '' && $clientSecret !== '') {
            return null;
        }

        return [
            'success' => false,
            'status' => 'missing_credentials',
            'message' => 'Не переданы ключи Ozon Performance API',
            'has_performance_api_key' => $clientId !== '',
            'has_performance_client_secret' => $clientSecret !== '',
        ];
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function requestAccessToken(array $credentials): array
    {
        $response = Http::timeout(12)
            ->acceptJson()
            ->asJson()
            ->post(self::BASE_URL . '/api/client/token', [
                'client_id' => trim((string) ($credentials['performance_api_key'] ?? '')),
                'client_secret' => trim((string) ($credentials['performance_client_secret'] ?? '')),
                'grant_type' => 'client_credentials',
            ]);

        $accessToken = (string) ($response->json('access_token') ?? '');

        return [
            'success' => $response->successful() && $accessToken !== '',
            'http_status' => $response->status(),
            'message' => $response->successful() && $accessToken !== ''
                ? 'Ozon Performance API доступен'
                : $this->errorMessage($response->json()),
            'access_token' => $accessToken,
            'token_type' => $response->json('token_type'),
            'expires_in' => $response->json('expires_in'),
        ];
    }

    private function authorized(string $accessToken): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(25)
            ->withToken($accessToken)
            ->acceptJson()
            ->asJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadReadyProductReport(string $accessToken, string $uuid): array
    {
        $statusResponse = $this->authorized($accessToken)
            ->get(self::BASE_URL . '/api/client/statistics/' . rawurlencode($uuid));
        $statusPayload = $statusResponse->json();
        $state = (string) ($statusPayload['state'] ?? '');
        $link = $this->absolutePerformanceUrl((string) ($statusPayload['link'] ?? ''));

        if (! $statusResponse->successful() || $state !== 'OK' || $link === '') {
            return [
                'success' => false,
                'status' => strtolower($state ?: 'not_ready'),
                'http_status' => $statusResponse->status(),
                'message' => 'Товарный отчёт Ozon ещё не готов',
                'uuid' => $uuid,
                'state' => $state ?: null,
                'has_download_link' => $link !== '',
            ];
        }

        $download = Http::timeout(30)
            ->withToken($accessToken)
            ->accept('*/*')
            ->get($link);

        if (! $download->successful()) {
            return [
                'success' => false,
                'status' => 'download_failed',
                'http_status' => $download->status(),
                'message' => $this->errorMessage($download->json()),
            ];
        }

        return [
            'success' => true,
            'status' => 'ok',
            'body' => (string) $download->body(),
            'content_type' => $download->header('content-type'),
        ];
    }

    /**
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    /**
     * Товарная ли кампания. Per-SKU статистика (клики/CTR/ДРР по товарам) доступна только для
     * SKU-кампаний; нетоварные типы (REF_VK, баннеры и т.п.) ломают генерацию товарного отчёта (400).
     *
     * @param array<string, mixed> $campaign
     */
    private function isProductCampaign(array $campaign): bool
    {
        $type = mb_strtoupper((string) (
            $campaign['advObjectType']
            ?? $campaign['adv_object_type']
            ?? $campaign['type']
            ?? ''
        ));

        // SKU-кампании (CPC, оплата за клик) + CPO-кампании (оплата за заказ: ALL_SKU_PROMO/SEARCH_PROMO).
        // Нетоварные типы (REF_VK и т.п.) исключаем — они ломают генерацию товарного отчёта (400).
        return ($type !== '' && str_contains($type, 'SKU')) || $this->isCpoCampaign($campaign);
    }

    /**
     * CPO-кампания («Оплата за заказ» — платишь % от продаж). Per-SKU расход доступен тем же
     * async-отчётом, что и CPC, но в отдельных колонках («Тип страницы»/«Условие показа»/«Средняя ставка»).
     *
     * @param array<string, mixed> $campaign
     */
    private function isCpoCampaign(array $campaign): bool
    {
        $pay = mb_strtoupper((string) ($campaign['PaymentType'] ?? $campaign['paymentType'] ?? $campaign['payment_type'] ?? ''));

        return $pay === 'CPO';
    }

    private function fetchCampaigns(string $accessToken): array
    {
        $response = $this->authorized($accessToken)
            ->get(self::BASE_URL . '/api/client/campaign');
        $payload = $response->json();
        $list = $this->extractList($payload);

        return [
            'list' => $list,
            'total' => (int) ($payload['total'] ?? count($list)),
        ];
    }

    /**
     * @param array<int, string> $campaignIds
     * @return array<string, mixed>
     */
    private function fetchProductCampaignStats(
        string $accessToken,
        string $dateFrom,
        string $dateTo,
        array $campaignIds,
        bool $productDetailOnly = false
    ): array {
        $rows = [];
        $source = 'fallback';
        $sourceErrors = [];

        // PRIMARY: асинхронный товарный отчёт Ozon (POST /api/client/statistics → ZIP с CSV по кампаниям).
        // Именно он отдаёт клики/показы/CTR/расход/ДРР на уровне SKU. Синхронный campaign/product на
        // многих аккаунтах возвращает агрегаты кампаний без товарного SKU — это и был источник FALLBACK.
        if ($campaignIds !== []) {
            $async = $this->fetchProductStatsViaAsyncReport($accessToken, $dateFrom, $dateTo, $campaignIds);
            if ($async['rows'] !== []) {
                $rows = $async['rows'];
                $source = 'async_report';
            }
            if ($async['errors'] !== []) {
                $sourceErrors = array_merge($sourceErrors, $async['errors']);
            }
        }

        // FALLBACK: устаревший синхронный путь campaign/product (CSV → JSON). Оставлен на случай
        // аккаунтов/типов кампаний, где он всё ещё отдаёт товарную детализацию.
        if ($rows === []) {
            $chunks = count($campaignIds) > 0 ? array_chunk($campaignIds, 20) : [[]];

            foreach ($chunks as $chunk) {
                $queryString = $this->campaignProductStatsQueryString($dateFrom, $dateTo, $chunk);

                $csvResponse = Http::timeout(30)
                    ->withToken($accessToken)
                    ->accept('*/*')
                    ->get(self::BASE_URL . '/api/client/statistics/campaign/product?' . $queryString);

                if ($csvResponse->successful()) {
                    $csvRows = $this->parseCampaignProductCsvRows((string) $csvResponse->body());
                    if ($csvRows !== []) {
                        $rows = array_merge($rows, $csvRows);
                        $source = 'csv';
                        continue;
                    }

                    $sourceErrors[] = 'CSV ответ не содержит товарных строк или заголовок не распознан';
                } else {
                    $sourceErrors[] = 'CSV HTTP ' . $csvResponse->status();
                }

                $response = $this->authorized($accessToken)
                    ->get(self::BASE_URL . '/api/client/statistics/campaign/product/json?' . $queryString);

                $payload = $response->json();
                if (! $response->successful() || ! is_array($payload)) {
                    $sourceErrors[] = 'JSON HTTP ' . $response->status();
                    continue;
                }

                $jsonRows = $this->extractRows($payload);
                $productRows = $productDetailOnly
                    ? array_values(array_filter($jsonRows, fn (array $row): bool => $this->hasCampaignProductIdentifier($row)))
                    : $jsonRows;

                if ($productDetailOnly && $jsonRows !== [] && $productRows === []) {
                    $sourceErrors[] = 'JSON вернул агрегаты кампаний без товарного SKU';
                }

                if ($productRows !== []) {
                    $rows = array_merge($rows, $productRows);
                    if ($source !== 'csv') {
                        $source = 'json';
                    }
                }
            }
        }

        $totals = $this->sumStatisticRows($rows);

        return [
            'rows' => $rows,
            'totals' => $totals,
            'derived' => [
                'ctr_percent' => $totals['views'] > 0
                    ? round($totals['clicks'] / $totals['views'] * 100, 2)
                    : 0.0,
                'average_cpc' => $totals['clicks'] > 0
                    ? round($totals['money_spent'] / $totals['clicks'], 2)
                    : 0.0,
                'drr_percent' => $totals['orders_money'] > 0
                    ? round($totals['money_spent'] / $totals['orders_money'] * 100, 2)
                    : 0.0,
            ],
            'top_by_spend' => $this->topBySpend($rows),
            'field_keys' => isset($rows[0]) && is_array($rows[0]) ? array_keys($rows[0]) : [],
            'source' => $source,
            // Ошибку показываем только когда товарных строк не получили вовсе. Частичные сбои
            // отдельных чанков при успешном результате — некритичный шум, в диагностику не выносим.
            'source_error' => ($rows === [] && $sourceErrors !== [])
                ? implode('; ', array_unique($sourceErrors))
                : null,
            'sample_rows' => $this->sampleCampaignRows($rows),
        ];
    }

    /**
     * Прогрессивный сбор per-SKU CPC без блокирующего ожидания в HTTP-запросе.
     *
     * Async-отчёты Ozon готовятся минуты — синхронно ждать нельзя (таймаут php-fpm/nginx → пустой ответ).
     * Поэтому состояние держим в кэше и каждый HTTP-запрос делает короткий шаг:
     *   1) нет прогресса     → создаём отчёты по чанкам (быстрые POST), сохраняем UUID, отдаём 'pending';
     *   2) прогресс есть      → проверяем готовность UUID, скачиваем готовые, копим строки;
     *   3) всё готово/устарело→ собираем итог, кэшируем на 30 мин, отдаём 'async_report'.
     * Фронт повторяет запрос, пока source='pending'.
     *
     * @param array<int, string> $campaignIds
     * @return array<string, mixed>
     */
    private function resolveCampaignStatsProgressive(
        int $integrationId,
        string $accessToken,
        string $dateFrom,
        string $dateTo,
        array $campaignIds,
        bool $forceRefresh = false
    ): array {
        $progKey = "ozon_perf_cpc_prog:{$integrationId}:{$dateFrom}:{$dateTo}";

        // Принудительное обновление: сбрасываем активный прогресс, чтобы перегенерить с нуля.
        // Сохранённую в БД строку НЕ удаляем — перезапишем по готовности (старое видно, пока новое не готово).
        if ($forceRefresh) {
            Cache::forget($progKey);
        }

        $prog = Cache::get($progKey);

        // Нет активной перегенерации и не форс — мгновенно отдаём сохранённое из БД.
        // Переживает рестарты и не сбрасывается после обновления страницы (в отличие от Cache TTL).
        if (! is_array($prog) && ! $forceRefresh) {
            $stored = $this->readStoredCampaignStats($integrationId, $dateFrom, $dateTo);
            if ($stored !== null) {
                return $stored;
            }
        }

        // Шаг 1: прогресса нет — создаём отчёты по всем чанкам (быстрые POST) и сразу отвечаем 'pending'.
        if (! is_array($prog)) {
            $uuids = [];
            $errors = [];
            foreach (array_chunk(array_values(array_filter($campaignIds)), 10) as $chunk) {
                if ($chunk === []) {
                    continue;
                }
                try {
                    $uuid = $this->generateCampaignStatsReport($accessToken, $chunk, $dateFrom, $dateTo);
                    if ($uuid !== '') {
                        $uuids[$uuid] = false;
                    } else {
                        $errors[] = 'Async: отчёт не создан (нет UUID)';
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Async: ' . $e->getMessage();
                }
            }
            $prog = ['uuids' => $uuids, 'rows_by_uuid' => [], 'errors' => $errors, 'created' => time()];
            Cache::put($progKey, $prog, now()->addMinutes(15));

            // Если ни один отчёт не создан — не зависаем в pending, отдаём пустой результат.
            if ($uuids === []) {
                Cache::forget($progKey);

                return $this->assembleCampaignStats([], $errors, 'fallback');
            }

            return $this->assembleCampaignStats([], $errors, 'pending') + ['pending' => true];
        }

        // Шаг 2: проверяем неготовые UUID, скачиваем готовые. Без длинного ожидания.
        foreach ($prog['uuids'] as $uuid => $done) {
            if ($done) {
                continue;
            }
            try {
                $payload = Http::connectTimeout(15)
                    ->timeout(30)
                    ->withToken($accessToken)
                    ->acceptJson()
                    ->get(self::BASE_URL . '/api/client/statistics/' . rawurlencode((string) $uuid))
                    ->json();
            } catch (\Throwable $e) {
                $prog['errors'][] = 'Async: статус ' . $e->getMessage();
                continue;
            }
            $state = strtoupper((string) (is_array($payload) ? ($payload['state'] ?? '') : ''));
            $link = $this->absolutePerformanceUrl((string) (is_array($payload) ? ($payload['link'] ?? '') : ''));
            if ($state === 'OK' && $link !== '') {
                try {
                    // Идемпотентно: пишем строки отчёта ПО uuid (перезапись, не append) — повторная
                    // обработка того же готового отчёта (гонка двух запросов) не задваивает клики/расход.
                    $prog['rows_by_uuid'][(string) $uuid] = $this->downloadCampaignStatsReportRows($accessToken, $link);
                } catch (\Throwable $e) {
                    $prog['errors'][] = 'Async: ' . $e->getMessage();
                }
                $prog['uuids'][$uuid] = true;
            } elseif (in_array($state, ['ERROR', 'CANCELLED'], true)) {
                $prog['uuids'][$uuid] = true;
                $prog['errors'][] = 'Async: отчёт ' . $uuid . ' состояние ' . $state;
            }
        }

        // Накопленные строки из всех скачанных отчётов (плоский список).
        $accumulated = array_merge([], ...array_values($prog['rows_by_uuid'] ?? []));

        $allDone = ! in_array(false, $prog['uuids'], true);
        $stale = (time() - (int) ($prog['created'] ?? time())) > 300; // защита от вечного pending

        // Шаг 3: готово (или устарело) — собираем итог и СОХРАНЯЕМ В БД (персистентно, с меткой времени).
        if ($allDone || $stale) {
            $stats = $this->assembleCampaignStats(
                $accumulated,
                $prog['errors'],
                $accumulated !== [] ? 'async_report' : 'fallback'
            );
            if ($accumulated !== []) {
                $stats['from_storage'] = false;
                $stats['fetched_at'] = $this->writeStoredCampaignStats($integrationId, $dateFrom, $dateTo, $stats);
            }
            Cache::forget($progKey);

            return $stats;
        }

        Cache::put($progKey, $prog, now()->addMinutes(15));

        return $this->assembleCampaignStats($accumulated, $prog['errors'], 'pending') + ['pending' => true];
    }

    /**
     * Читает сохранённую per-SKU рекламную статистику из БД.
     * Возвращает payload с from_storage=true и fetched_at (ISO), либо null если записи нет/она пуста.
     *
     * @return array<string, mixed>|null
     */
    private function readStoredCampaignStats(int $integrationId, string $dateFrom, string $dateTo): ?array
    {
        try {
            $row = OzonAdStat::query()
                ->where('integration_id', $integrationId)
                ->where('date_from', $dateFrom)
                ->where('date_to', $dateTo)
                ->first();
        } catch (\Throwable $e) {
            return null; // таблицы может не быть до миграции — мягкая деградация
        }

        if ($row === null) {
            return null;
        }

        $payload = is_array($row->payload) ? $row->payload : [];
        if (($payload['rows'] ?? []) === []) {
            return null;
        }

        $payload['from_storage'] = true;
        $payload['fetched_at'] = optional($row->fetched_at)->toIso8601String();

        return $payload;
    }

    /**
     * Сохраняет (upsert) per-SKU рекламную статистику в БД, возвращает метку времени (ISO).
     *
     * @param array<string, mixed> $stats
     */
    private function writeStoredCampaignStats(int $integrationId, string $dateFrom, string $dateTo, array $stats): ?string
    {
        try {
            $now = now();
            OzonAdStat::query()->updateOrCreate(
                ['integration_id' => $integrationId, 'date_from' => $dateFrom, 'date_to' => $dateTo],
                ['status' => 'ready', 'payload' => $stats, 'fetched_at' => $now]
            );

            return $now->toIso8601String();
        } catch (\Throwable $e) {
            return null; // не валим HTTP-запрос, если запись в БД не удалась
        }
    }

    /**
     * Собирает итоговую структуру статистики кампаний из накопленных товарных строк.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $errors
     * @return array<string, mixed>
     */
    private function assembleCampaignStats(array $rows, array $errors, string $source): array
    {
        $totals = $this->sumStatisticRows($rows);

        return [
            'rows' => $rows,
            'totals' => $totals,
            'derived' => [
                'ctr_percent' => $totals['views'] > 0
                    ? round($totals['clicks'] / $totals['views'] * 100, 2)
                    : 0.0,
                'average_cpc' => $totals['clicks'] > 0
                    ? round($totals['money_spent'] / $totals['clicks'], 2)
                    : 0.0,
                'drr_percent' => $totals['orders_money'] > 0
                    ? round($totals['money_spent'] / $totals['orders_money'] * 100, 2)
                    : 0.0,
            ],
            'top_by_spend' => $this->topBySpend($rows),
            'field_keys' => isset($rows[0]) && is_array($rows[0]) ? array_keys($rows[0]) : [],
            'source' => $source,
            // Ошибку показываем только при полном отсутствии строк (см. ту же логику в fetchProductCampaignStats).
            'source_error' => ($rows === [] && $errors !== [])
                ? implode('; ', array_unique($errors))
                : null,
            'sample_rows' => $this->sampleCampaignRows($rows),
        ];
    }

    /**
     * Асинхронный товарный отчёт Ozon Performance API.
     *
     * POST /api/client/statistics (groupBy=NO) → poll GET /api/client/statistics/{uuid} →
     * скачать отчёт по link (ZIP с одним CSV на кампанию, либо одиночный CSV) → разобрать
     * товарные строки. Это единственный путь, который отдаёт per-SKU клики/показы/CTR/расход/ДРР.
     *
     * @param array<int, string> $campaignIds
     * @return array{rows: array<int, array<string, string>>, errors: array<int, string>}
     */
    private function fetchProductStatsViaAsyncReport(
        string $accessToken,
        string $dateFrom,
        string $dateTo,
        array $campaignIds,
        int $budgetSeconds = 85
    ): array {
        $rows = [];
        $errors = [];

        // Ozon Performance ограничивает число кампаний в одном запросе статистики (≈10) — больше даёт
        // HTTP 400. Чанкуем по 10; повторные вызовы защищены бэкоффом на 429 в generateCampaignStatsReport.
        $chunks = array_chunk(array_values(array_filter($campaignIds)), 10);

        // Фаза 1: создаём отчёты по всем чанкам (быстрые POST) и собираем UUID.
        // Отчёты формируются на стороне Ozon параллельно, поэтому ждём их одним общим бюджетом,
        // а не суммой пер-чанк ожиданий — это держит время запроса в рамках таймаутов nginx/php-fpm.
        $pending = [];
        foreach ($chunks as $chunk) {
            if ($chunk === []) {
                continue;
            }
            try {
                $uuid = $this->generateCampaignStatsReport($accessToken, $chunk, $dateFrom, $dateTo);
                if ($uuid === '') {
                    $errors[] = 'Async: отчёт не создан (нет UUID)';
                    continue;
                }
                $pending[$uuid] = true;
            } catch (\Throwable $e) {
                $errors[] = 'Async: ' . $e->getMessage();
            }
        }

        // Фаза 2: общий бюджет ожидания готовности всех отчётов.
        $links = [];
        $start = time();
        while ($pending !== [] && (time() - $start) < $budgetSeconds) {
            foreach (array_keys($pending) as $uuid) {
                try {
                    $payload = Http::connectTimeout(15)
                        ->timeout(30)
                        ->withToken($accessToken)
                        ->acceptJson()
                        ->get(self::BASE_URL . '/api/client/statistics/' . rawurlencode($uuid))
                        ->json();
                } catch (\Throwable $e) {
                    $errors[] = 'Async: статус ' . $e->getMessage();
                    continue;
                }

                $state = strtoupper((string) (is_array($payload) ? ($payload['state'] ?? '') : ''));
                $link = $this->absolutePerformanceUrl((string) (is_array($payload) ? ($payload['link'] ?? '') : ''));

                if ($state === 'OK' && $link !== '') {
                    $links[] = $link;
                    unset($pending[$uuid]);
                } elseif (in_array($state, ['ERROR', 'CANCELLED'], true)) {
                    unset($pending[$uuid]);
                    $errors[] = 'Async: отчёт ' . $uuid . ' состояние ' . $state;
                }
            }

            if ($pending !== [] && (time() - $start) < $budgetSeconds) {
                sleep(3);
            }
        }
        if ($pending !== []) {
            $errors[] = 'Async: ' . count($pending) . ' отчёт(ов) не готовы за ' . $budgetSeconds . 'с';
        }

        // Фаза 3: скачиваем готовые отчёты и разбираем товарные строки.
        foreach ($links as $link) {
            try {
                $rows = array_merge($rows, $this->downloadCampaignStatsReportRows($accessToken, $link));
            } catch (\Throwable $e) {
                $errors[] = 'Async: ' . $e->getMessage();
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param array<int, string> $campaignIds
     */
    private function generateCampaignStatsReport(
        string $accessToken,
        array $campaignIds,
        string $dateFrom,
        string $dateTo
    ): string {
        $body = [
            'campaigns' => array_values($campaignIds),
            'from' => $this->toRfc3339Start($dateFrom),
            'to' => $this->toRfc3339End($dateTo),
            'groupBy' => 'NO',
        ];

        // Лёгкий бэкофф на 429 (rate limit Ozon Performance): одна короткая повторная попытка.
        // Длинных ожиданий тут быть не должно — генерация вызывается в HTTP-запросе (прогрессивный сбор).
        $lastStatus = 0;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = Http::connectTimeout(15)
                ->timeout(40)
                ->withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->post(self::BASE_URL . '/api/client/statistics', $body);

            if ($response->successful()) {
                return (string) ($response->json('UUID') ?? $response->json('uuid') ?? '');
            }

            $lastStatus = $response->status();
            if ($lastStatus === 429 && $attempt < 2) {
                sleep(3);
                continue;
            }

            break;
        }

        throw new \RuntimeException('POST /api/client/statistics HTTP ' . $lastStatus);
    }

    /**
     * Поллинг готовности отчёта. Возвращает абсолютный download-link или '' если не готов/ошибка.
     */
    private function awaitCampaignStatsReport(
        string $accessToken,
        string $uuid,
        int $maxAttempts = 15,
        int $sleepSeconds = 4
    ): string {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $payload = Http::connectTimeout(15)
                ->timeout(30)
                ->withToken($accessToken)
                ->acceptJson()
                ->get(self::BASE_URL . '/api/client/statistics/' . rawurlencode($uuid))
                ->json();

            $state = strtoupper((string) (is_array($payload) ? ($payload['state'] ?? '') : ''));
            $link = $this->absolutePerformanceUrl((string) (is_array($payload) ? ($payload['link'] ?? '') : ''));

            if ($state === 'OK' && $link !== '') {
                return $link;
            }
            if (in_array($state, ['ERROR', 'CANCELLED'], true)) {
                return '';
            }
            if ($attempt < $maxAttempts) {
                sleep($sleepSeconds);
            }
        }

        return '';
    }

    /**
     * Скачивает отчёт (ZIP из CSV по кампаниям либо одиночный CSV) и разбирает товарные строки.
     *
     * @return array<int, array<string, string>>
     */
    private function downloadCampaignStatsReportRows(string $accessToken, string $link): array
    {
        $response = Http::connectTimeout(15)
            ->timeout(60)
            ->withToken($accessToken)
            ->accept('*/*')
            ->get($link);

        if (! $response->successful()) {
            throw new \RuntimeException('Скачивание отчёта HTTP ' . $response->status());
        }

        $body = (string) $response->body();
        $contentType = strtolower((string) $response->header('content-type'));

        $csvParts = (str_starts_with($body, "PK\x03\x04") || str_contains($contentType, 'zip'))
            ? $this->extractCsvFromZip($body)
            : [$body];

        $rows = [];
        foreach ($csvParts as $csv) {
            $rows = array_merge($rows, $this->parseCampaignProductCsvRows($csv));
        }

        return $rows;
    }

    /**
     * @return array<int, string> Содержимое каждой CSV-записи внутри ZIP
     */
    private function extractCsvFromZip(string $zipBinary): array
    {
        $parts = [];
        $tmp = tempnam(sys_get_temp_dir(), 'ozon-cpc-');
        if ($tmp === false) {
            return $parts;
        }

        try {
            file_put_contents($tmp, $zipBinary);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (! str_ends_with(strtolower($name), '.csv')) {
                        continue;
                    }
                    $content = $zip->getFromIndex($i);
                    if ($content !== false && $content !== '') {
                        $parts[] = $content;
                    }
                }
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }

        return $parts;
    }

    /**
     * Ozon Performance ожидает повторяющиеся campaignIds в query string:
     * campaignIds=1&campaignIds=2. Нельзя отдавать массивом campaignIds[0].
     */
    private function campaignProductStatsQueryString(string $dateFrom, string $dateTo, array $campaignIds): string
    {
        $parts = [
            'dateFrom=' . rawurlencode($dateFrom),
            'dateTo=' . rawurlencode($dateTo),
        ];

        foreach ($campaignIds as $campaignId) {
            $campaignId = trim((string) $campaignId);
            if ($campaignId === '') {
                continue;
            }

            $parts[] = 'campaignIds=' . rawurlencode($campaignId);
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasCampaignProductIdentifier(array $row): bool
    {
        return $this->firstString($row, [
            'SKU',
            'sku',
            'Артикул',
            'offerId',
            'offer_id',
            'vendorCode',
            'vendor_code',
            'productSku',
            'product_sku',
            'product.sku',
            'productId',
            'product_id',
            'product.id',
            'objectId',
            'object_id',
            'advObjectId',
            'adv_object_id',
        ]) !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sampleCampaignRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $sample = [];
            foreach ([
                'SKU',
                'sku',
                'Артикул',
                'offerId',
                'productSku',
                'productId',
                'product.id',
                'objectId',
                'advObjectId',
                'id',
                'Название товара',
                'title',
                'name',
                'productName',
                'moneySpent',
                'Расход',
                'views',
                'Показы',
                'clicks',
                'Клики',
                'orders',
                'Заказы',
                'ordersMoney',
                'Выручка',
            ] as $key) {
                if (array_key_exists($key, $row)) {
                    $sample[$key] = is_scalar($row[$key]) || $row[$key] === null
                        ? $row[$key]
                        : '[object]';
                }
            }

            return $sample;
        }, array_slice($rows, 0, 3));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function unmappedCampaignKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if ((string) ($row['_mapping_status'] ?? '') !== 'unmapped_ozon_sku') {
                continue;
            }

            $key = $this->firstString($row, [
                '_mapping_key',
                '_raw_campaign_sku',
                '_raw_campaign_product_id',
                'SKU',
                'Артикул',
                'Название товара',
            ]);

            if ($key !== '') {
                $keys[$key] = true;
            }

            if (count($keys) >= 10) {
                break;
            }
        }

        return array_keys($keys);
    }

    /**
     * @return array{http_status: int, limits: array<int, mixed>}
     */
    private function fetchBidLimits(string $accessToken): array
    {
        $response = $this->authorized($accessToken)
            ->get(self::BASE_URL . '/api/client/limits/list');
        $payload = $response->json();

        return [
            'http_status' => $response->status(),
            'limits' => is_array($payload['limits'] ?? null) ? $payload['limits'] : [],
        ];
    }

    /**
     * @param mixed $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractList(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        foreach (['list', 'campaigns', 'objects', 'items', 'rows'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        return array_is_list($payload) ? array_values(array_filter($payload, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractRows(array $payload): array
    {
        foreach (['rows', 'list', 'items', 'campaigns'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->flattenStatisticRows(array_values(array_filter($payload[$key], 'is_array')));
            }
        }

        return array_is_list($payload) ? $this->flattenStatisticRows(array_values(array_filter($payload, 'is_array'))) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function flattenStatisticRows(array $items, array $context = []): array
    {
        $rows = [];

        foreach ($items as $item) {
            $scalar = [];
            $nested = [];

            foreach ($item as $key => $value) {
                if (is_array($value)) {
                    $nested[$key] = $value;
                } else {
                    $scalar[$key] = $value;
                }
            }

            $flatNested = [];
            foreach ($nested as $key => $value) {
                $flatNested = array_merge($flatNested, $this->flattenScalarPayload($value, (string) $key));
            }

            $row = array_merge($context, $scalar, $flatNested);
            if ($this->looksLikeStatisticRow($row)) {
                $rows[] = $row;
            }

            foreach (['rows', 'items', 'products', 'statistics', 'stats', 'children'] as $key) {
                if (! isset($nested[$key]) || ! is_array($nested[$key])) {
                    continue;
                }

                $nestedItems = array_is_list($nested[$key])
                    ? array_values(array_filter($nested[$key], 'is_array'))
                    : [$nested[$key]];
                $rows = array_merge($rows, $this->flattenStatisticRows($nestedItems, $row));
            }
        }

        return $rows;
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function flattenScalarPayload(mixed $payload, string $prefix): array
    {
        if (! is_array($payload)) {
            return [$prefix => $payload];
        }

        $flat = [];
        foreach ($payload as $key => $value) {
            $path = $prefix . '.' . (string) $key;
            if (is_array($value)) {
                $flat = array_merge($flat, $this->flattenScalarPayload($value, $path));
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function looksLikeStatisticRow(array $row): bool
    {
        foreach ([
            'SKU', 'sku', 'offerId', 'offer_id', 'productId', 'product_id',
            'productSku', 'product_sku', 'objectId', 'object_id', 'advObjectId', 'adv_object_id',
            'product.id', 'product.sku',
            'moneySpent', 'Расход', 'views', 'Показы', 'clicks', 'Клики',
            'orders', 'Заказы', 'ordersMoney', 'Выручка',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, float|int>
     */
    private function sumStatisticRows(array $rows): array
    {
        $totals = [
            'money_spent' => 0.0,
            'views' => 0,
            'clicks' => 0,
            'to_cart' => 0,
            'orders' => 0,
            'orders_money' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals['money_spent'] += $this->firstNumber($row, ['moneySpent', 'spent', 'expense', 'Расход', 'Расход, ₽', 'Расход, Р', 'Расход, Р, с НДС']);
            $totals['views'] += (int) $this->firstNumber($row, ['views', 'impressions', 'Показы', 'Показы, шт']);
            $totals['clicks'] += (int) $this->firstNumber($row, ['clicks', 'Клики', 'Клики, шт']);
            $totals['to_cart'] += (int) $this->firstNumber($row, ['toCart', 'to_cart', 'cart', 'В корзину', 'В корзину, шт']);
            $totals['orders'] += (int) $this->firstNumber($row, ['orders', 'ordersCount', 'Заказы', 'Заказы, шт']);
            $totals['orders_money'] += $this->firstNumber($row, ['ordersMoney', 'orders_money', 'revenue', 'sales', 'Выручка', 'Выручка, ₽', 'Выручка, Р', 'Продажи', 'Продажи, ₽', 'Продажи, Р']);
        }

        $totals['money_spent'] = round($totals['money_spent'], 2);
        $totals['orders_money'] = round($totals['orders_money'], 2);

        return $totals;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function topBySpend(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => $this->number($b['moneySpent'] ?? 0) <=> $this->number($a['moneySpent'] ?? 0));

        return array_map(function (array $row): array {
            return [
                'id' => $row['id'] ?? null,
                'title' => $row['title'] ?? null,
                'status' => $row['status'] ?? null,
                'placement' => $row['placement'] ?? null,
                'spent' => $this->number($row['moneySpent'] ?? 0),
                'views' => (int) $this->number($row['views'] ?? 0),
                'clicks' => (int) $this->number($row['clicks'] ?? 0),
                'orders' => (int) $this->number($row['orders'] ?? 0),
                'sales' => $this->number($row['ordersMoney'] ?? 0),
                'drr' => $this->number($row['drr'] ?? 0),
            ];
        }, array_slice($rows, 0, 10));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, int>
     */
    private function countBy(array $items, string $key): array
    {
        $counts = [];
        foreach ($items as $item) {
            $value = (string) ($item[$key] ?? 'unknown');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $campaigns
     * @return array<string, int>
     */
    private function countPlacements(array $campaigns): array
    {
        $counts = [];
        foreach ($campaigns as $campaign) {
            $placements = $campaign['ProductAdvPlacements'] ?? $campaign['placement'] ?? [];
            if (! is_array($placements)) {
                $placements = [$placements];
            }
            if ($placements === []) {
                $placements = ['unknown'];
            }
            foreach ($placements as $placement) {
                $key = is_scalar($placement) ? (string) $placement : json_encode($placement, JSON_UNESCAPED_UNICODE);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    private function compactCampaign(array $campaign): array
    {
        return [
            'id' => isset($campaign['id']) ? (string) $campaign['id'] : null,
            'title' => $campaign['title'] ?? null,
            'state' => $campaign['state'] ?? null,
            'type' => $campaign['advObjectType'] ?? null,
            'placement' => $campaign['ProductAdvPlacements'] ?? $campaign['placement'] ?? null,
            'budget' => $campaign['budget'] ?? null,
            'daily_budget' => $campaign['dailyBudget'] ?? null,
        ];
    }

    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], (string) $value);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function toRfc3339Start(string $date): string
    {
        return Str::of($date)->contains('T')
            ? $date
            : $date . 'T00:00:00Z';
    }

    private function toRfc3339End(string $date): string
    {
        return Str::of($date)->contains('T')
            ? $date
            : $date . 'T23:59:59Z';
    }

    private function absolutePerformanceUrl(string $link): string
    {
        $link = trim($link);
        if ($link === '') {
            return '';
        }

        if (Str::startsWith($link, ['http://', 'https://'])) {
            return $link;
        }

        return self::BASE_URL . '/' . ltrim($link, '/');
    }

    /**
     * @return array{header: array<int, string>, rows: array<int, array<string, string>>, truncated: bool}
     */
    private function parseProductReportCsvPreview(string $csv, int $limit): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $csv) ?: [];
        $header = [];
        $rows = [];
        $limit = max(1, min(5000, $limit));

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            $cells = str_getcsv($line, ';', '"', '\\');
            $cells = array_map(static fn ($cell): string => trim((string) $cell), $cells);

            if ($header === []) {
                if (in_array('SKU', $cells, true) && in_array('Артикул', $cells, true)) {
                    $header = $cells;
                }
                continue;
            }

            $row = [];
            foreach ($header as $index => $name) {
                $row[$name] = (string) ($cells[$index] ?? '');
            }
            $rows[] = $row;

            if (count($rows) >= $limit) {
                break;
            }
        }

        $dataLineCount = max(0, count(array_filter($lines, static fn ($line): bool => trim((string) $line) !== '')) - 2);

        return [
            'header' => $header,
            'rows' => $rows,
            'truncated' => $dataLineCount > count($rows),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCampaignProductCsvRows(string $csv): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $csv) ?: [];
        $header = [];
        $rows = [];

        // ID кампании указан в строке-заголовке отчёта: "Кампания по продвижению товаров № 23001632, период…".
        $campaignId = preg_match('/№\s*(\d+)/u', $csv, $m) ? $m[1] : '';

        // CPO («Оплата за заказ») vs CPC («Оплата за клик») определяем по характерным колонкам отчёта.
        $paymentType = (mb_stripos($csv, 'Условие показа') !== false
            || mb_stripos($csv, 'Тип страницы') !== false
            || mb_stripos($csv, 'Средняя ставка') !== false)
            ? 'cpo'
            : 'cpc';

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            $cells = str_getcsv($line, ';', '"', '\\');
            $cells = array_map(static fn ($cell): string => trim((string) $cell), $cells);

            if ($header === []) {
                if ($this->looksLikeCampaignProductCsvHeader($cells)) {
                    $header = $this->normalizeCampaignProductCsvHeader($cells);
                }
                continue;
            }

            $row = [];
            foreach ($header as $index => $name) {
                $row[$name] = (string) ($cells[$index] ?? '');
            }

            $sku = $this->firstString($row, [
                'SKU',
                'sku',
                'Артикул',
                'productSku',
                'product_sku',
                'productId',
                'product_id',
                'objectId',
                'advObjectId',
            ]);
            if ($sku === '' || in_array(mb_strtolower($sku), ['всего', 'итого', 'bcero', 'корректировка'], true)) {
                continue;
            }

            $row['_source'] = 'campaign_product_stats_cpc_csv';
            $row['_campaign_id'] = $campaignId;
            $row['_payment_type'] = $paymentType;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, string> $cells
     */
    private function looksLikeCampaignProductCsvHeader(array $cells): bool
    {
        $normalized = array_map(
            static fn (string $cell): string => mb_strtolower(trim($cell)),
            $cells
        );

        $hasSku = in_array('sku', $normalized, true) || in_array('артикул', $normalized, true);
        $hasAdMetric = false;

        foreach ($normalized as $cell) {
            if (
                str_contains($cell, 'показы')
                || str_contains($cell, 'клики')
                || str_contains($cell, 'расход')
                || str_contains($cell, 'ctr')
            ) {
                $hasAdMetric = true;
                break;
            }
        }

        return $hasSku && $hasAdMetric;
    }

    /**
     * @param array<int, string> $cells
     * @return array<int, string>
     */
    private function normalizeCampaignProductCsvHeader(array $cells): array
    {
        return array_map(static function (string $cell): string {
            $name = trim($cell);
            $lower = mb_strtolower($name);

            return match (true) {
                $lower === 'sku' => 'SKU',
                str_contains($lower, 'название') => 'Название товара',
                str_contains($lower, 'категор') => 'Категория товара',
                str_contains($lower, 'цена товара') => 'Цена товара, ₽',
                str_contains($lower, 'показы') => 'Показы (Оплата за клик)',
                str_contains($lower, 'клики') => 'Клики (Оплата за клик)',
                str_contains($lower, 'ctr') => 'CTR (Оплата за клик)',
                str_contains($lower, 'корзин') => 'В корзину (Оплата за клик)',
                str_contains($lower, 'ср. цена клика') || str_contains($lower, 'средняя стоимость клика') => 'Ср. цена клика (Оплата за клик)',
                str_contains($lower, 'расход') => 'Расход (Оплата за клик)',
                str_contains($lower, 'заказы модели') => 'Заказы модели (Оплата за клик)',
                str_contains($lower, 'выручка с заказов модели') || str_contains($lower, 'продажи с заказов модели') => 'Выручка с заказов модели (Оплата за клик)',
                str_contains($lower, 'заказы') => 'Заказы (Оплата за клик)',
                str_contains($lower, 'выручка') || str_contains($lower, 'продажи') => 'Продажи (Оплата за клик)',
                str_contains($lower, 'дата добавления') => 'Дата добавления',
                default => $name,
            };
        }, $cells);
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array<string, mixed>
     */
    private function buildProductAdvertisingImpact(array $rows): array
    {
        $products = [];

        foreach ($rows as $row) {
            $ozonSku = trim((string) ($row['SKU'] ?? ''));
            $offerId = trim((string) ($row['Артикул'] ?? ''));
            $key = $offerId !== '' ? $offerId : $ozonSku;
            if ($key === '') {
                continue;
            }

            if (! isset($products[$key])) {
                $products[$key] = [
                    'offer_id' => $offerId !== '' ? $offerId : null,
                    'ozon_sku' => $ozonSku !== '' ? $ozonSku : null,
                    'aliases' => array_values(array_filter([$offerId, $ozonSku])),
                    'product_name' => $row['Название товара'] ?? null,
                    'category' => $row['Категория товара'] ?? null,
                    'ad_enabled' => $this->isEnabledRu($row['Продвижение'] ?? null),
                    'price' => $this->number($row['Цена товара, ₽'] ?? 0),
                    'ad_rate_percent' => $this->number($row['Ставка, %'] ?? 0),
                    'ad_rate_amount' => $this->number($row['Ставка, ₽'] ?? 0),
                    'impressions' => 0,
                    'clicks' => 0,
                    'to_cart' => 0,
                    'ctr_percent' => 0.0,
                    'average_cpc' => 0.0,
                    'ad_spend' => 0.0,
                    'ad_revenue' => 0.0,
                    'ad_orders' => 0,
                    'ad_drr_percent' => 0.0,
                    'ad_spend_per_order' => 0.0,
                    'cart_conversion_percent' => 0.0,
                    'ad_cpo_spend' => 0.0,
                    'ad_cpo_revenue' => 0.0,
                    'ad_cpo_orders' => 0,
                    'ad_cpo_drr_percent' => 0.0,
                    'ordered_amount' => 0.0,
                    'ad_ordered_amount' => 0.0,
                    'ad_total_drr_percent' => 0.0,
                    'ad_campaigns' => [],
                    'payment_models' => [],
                    'signals' => [],
                    'signals_ru' => [],
                    'source' => 'ozon_performance_product_report',
                    'sources' => [],
                    'mapping_status' => $row['_mapping_status'] ?? null,
                    'mapping_key' => $row['_mapping_key'] ?? null,
                    'mapping_source' => $row['_mapping_source'] ?? null,
                    'raw_campaign_sku' => $row['_raw_campaign_sku'] ?? null,
                    'raw_campaign_product_id' => $row['_raw_campaign_product_id'] ?? null,
                ];
            }

            foreach (array_filter([
                $offerId,
                $ozonSku,
                $row['_mapping_key'] ?? null,
                $row['_raw_campaign_sku'] ?? null,
                $row['_raw_campaign_product_id'] ?? null,
            ]) as $alias) {
                if (! in_array($alias, $products[$key]['aliases'], true)) {
                    $products[$key]['aliases'][] = $alias;
                }
            }

            foreach ([
                'mapping_status' => '_mapping_status',
                'mapping_key' => '_mapping_key',
                'mapping_source' => '_mapping_source',
                'raw_campaign_sku' => '_raw_campaign_sku',
                'raw_campaign_product_id' => '_raw_campaign_product_id',
            ] as $target => $sourceKey) {
                if (! empty($row[$sourceKey])) {
                    $products[$key][$target] = $row[$sourceKey];
                }
            }

            $source = trim((string) ($row['_source'] ?? 'ozon_performance_product_report'));
            if ($source !== '' && ! in_array($source, $products[$key]['sources'], true)) {
                $products[$key]['sources'][] = $source;
            }

            $products[$key]['impressions'] += (int) $this->sumColumnsContaining($row, ['Показы']);
            $products[$key]['clicks'] += (int) $this->sumColumnsContaining($row, ['Клики']);
            $products[$key]['to_cart'] += (int) $this->sumColumnsContaining($row, ['В корзину']);
            $products[$key]['ad_spend'] += $this->sumColumnsContaining($row, ['Расход']);
            $products[$key]['ad_revenue'] += $this->sumColumnsContaining($row, ['Продажи', 'Выручка']);
            $products[$key]['ad_orders'] += (int) $this->sumColumnsContaining($row, ['Заказы']);
            // «Заказано на сумму» — товарный итог (одинаков по кампаниям), поэтому MAX, а не сумма.
            $products[$key]['ordered_amount'] = max(
                (float) $products[$key]['ordered_amount'],
                $this->sumColumnsContaining($row, ['Заказано на сумму'])
            );

            // Разбивка по кампаниям (ID кампании) — как в отчёте «Аналитика продвижения».
            $campaignId = trim((string) ($row['_campaign_id'] ?? ''));
            if ($campaignId !== '') {
                if (! isset($products[$key]['ad_campaigns'][$campaignId])) {
                    $products[$key]['ad_campaigns'][$campaignId] = [
                        'campaign_id' => $campaignId,
                        'impressions' => 0,
                        'clicks' => 0,
                        'to_cart' => 0,
                        'spend' => 0.0,
                        'revenue' => 0.0,
                        'orders' => 0,
                    ];
                }
                $products[$key]['ad_campaigns'][$campaignId]['impressions'] += (int) $this->sumColumnsContaining($row, ['Показы']);
                $products[$key]['ad_campaigns'][$campaignId]['clicks'] += (int) $this->sumColumnsContaining($row, ['Клики']);
                $products[$key]['ad_campaigns'][$campaignId]['to_cart'] += (int) $this->sumColumnsContaining($row, ['В корзину']);
                $products[$key]['ad_campaigns'][$campaignId]['spend'] += $this->sumColumnsContaining($row, ['Расход']);
                $products[$key]['ad_campaigns'][$campaignId]['revenue'] += $this->sumColumnsContaining($row, ['Продажи', 'Выручка']);
                $products[$key]['ad_campaigns'][$campaignId]['orders'] += (int) $this->sumColumnsContaining($row, ['Заказы']);
            }

            $products[$key]['payment_models'] = $this->mergePaymentModelBreakdown(
                is_array($products[$key]['payment_models']) ? $products[$key]['payment_models'] : [],
                $this->extractPaymentModelBreakdown($row)
            );
            $products[$key]['ad_enabled'] = (bool) $products[$key]['ad_enabled'] || $this->isEnabledRu($row['Продвижение'] ?? null);
        }

        $summary = [
            'products_count' => count($products),
            'ad_enabled_count' => 0,
            'total_ad_spend' => 0.0,
            'total_ad_revenue' => 0.0,
            'total_ad_orders' => 0,
            'total_impressions' => 0,
            'total_clicks' => 0,
            'total_to_cart' => 0,
            'average_cpc' => 0.0,
            'ctr_percent' => 0.0,
            'high_drr_count' => 0,
            'ads_driven_count' => 0,
        ];

        foreach ($products as $key => $product) {
            $spend = round((float) $product['ad_spend'], 2);
            $revenue = round((float) $product['ad_revenue'], 2);
            $orders = (int) $product['ad_orders'];
            $impressions = (int) $product['impressions'];
            $clicks = (int) $product['clicks'];
            $toCart = (int) $product['to_cart'];
            $drr = $revenue > 0 ? round($spend / $revenue * 100, 2) : 0.0;
            $spendPerOrder = $orders > 0 ? round($spend / $orders, 2) : 0.0;
            $ctr = $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0;
            $cpc = $clicks > 0 ? round($spend / $clicks, 2) : 0.0;
            $cartConversion = $clicks > 0 ? round($toCart / $clicks * 100, 2) : 0.0;
            $signals = [];

            if ($spend > 0 || $orders > 0 || $revenue > 0) {
                $signals[] = 'ads_driven_demand';
            }
            if ($drr >= 15.0) {
                $signals[] = 'high_ad_cost';
            }
            if (! (bool) $product['ad_enabled'] && ($spend > 0 || $orders > 0)) {
                $signals[] = 'ad_disabled_after_sales';
            }
            if ((bool) $product['ad_enabled'] && $orders === 0 && $spend > 0) {
                $signals[] = 'ad_spend_without_orders';
            }

            $products[$key]['ad_spend'] = $spend;
            $products[$key]['ad_revenue'] = $revenue;
            $products[$key]['ad_orders'] = $orders;
            $products[$key]['ad_drr_percent'] = $drr;
            $products[$key]['ad_spend_per_order'] = $spendPerOrder;
            $products[$key]['ctr_percent'] = $ctr;
            $products[$key]['average_cpc'] = $cpc;
            $products[$key]['cart_conversion_percent'] = $cartConversion;
            // Общий ДРР = весь рекламный расход (CPC+CPO) / «Заказано на сумму» (вся выручка товара) × 100.
            $orderedAmount = round((float) ($product['ordered_amount'] ?? 0), 2);
            $products[$key]['ad_ordered_amount'] = $orderedAmount;
            $products[$key]['ad_total_drr_percent'] = $orderedAmount > 0 ? round($spend / $orderedAmount * 100, 2) : 0.0;
            $products[$key]['ad_campaigns'] = array_values(array_map(static function (array $c): array {
                $sp = round((float) ($c['spend'] ?? 0), 2);
                $rev = round((float) ($c['revenue'] ?? 0), 2);
                $cl = (int) ($c['clicks'] ?? 0);
                $imp = (int) ($c['impressions'] ?? 0);
                $ord = (int) ($c['orders'] ?? 0);
                $cart = (int) ($c['to_cart'] ?? 0);

                return [
                    'campaign_id' => (string) ($c['campaign_id'] ?? ''),
                    'impressions' => $imp,
                    'clicks' => $cl,
                    'to_cart' => $cart,
                    'spend' => $sp,
                    'revenue' => $rev,
                    'orders' => $ord,
                    'ctr_percent' => $imp > 0 ? round($cl / $imp * 100, 2) : 0.0,
                    'average_cpc' => $cl > 0 ? round($sp / $cl, 2) : 0.0,
                    'drr_percent' => $rev > 0 ? round($sp / $rev * 100, 2) : 0.0,
                    'spend_per_order' => $ord > 0 ? round($sp / $ord, 2) : 0.0,
                    'cart_conversion_percent' => $cl > 0 ? round($cart / $cl * 100, 2) : 0.0,
                ];
            }, is_array($product['ad_campaigns'] ?? null) ? $product['ad_campaigns'] : []));
            $products[$key]['payment_models'] = array_values(array_map(
                fn (array $model): array => [
                    'name' => $model['name'],
                    'spend' => round((float) ($model['spend'] ?? 0), 2),
                    'revenue' => round((float) ($model['revenue'] ?? 0), 2),
                    'orders' => (int) ($model['orders'] ?? 0),
                    'impressions' => (int) ($model['impressions'] ?? 0),
                    'clicks' => (int) ($model['clicks'] ?? 0),
                    'to_cart' => (int) ($model['to_cart'] ?? 0),
                ],
                is_array($product['payment_models']) ? $product['payment_models'] : []
            ));

            // Явные поля CPO («Оплата за заказ») из платёжной модели — для отдельного показа в юнитке.
            $cpoModel = null;
            foreach ($products[$key]['payment_models'] as $pm) {
                if (mb_stripos((string) ($pm['name'] ?? ''), 'Оплата за заказ') !== false) {
                    $cpoModel = $pm;
                    break;
                }
            }
            $cpoSpend = $cpoModel ? round((float) ($cpoModel['spend'] ?? 0), 2) : 0.0;
            $cpoRevenue = $cpoModel ? round((float) ($cpoModel['revenue'] ?? 0), 2) : 0.0;
            $cpoOrders = $cpoModel ? (int) ($cpoModel['orders'] ?? 0) : 0;
            $products[$key]['ad_cpo_spend'] = $cpoSpend;
            $products[$key]['ad_cpo_revenue'] = $cpoRevenue;
            $products[$key]['ad_cpo_orders'] = $cpoOrders;
            $products[$key]['ad_cpo_drr_percent'] = $cpoRevenue > 0 ? round($cpoSpend / $cpoRevenue * 100, 2) : 0.0;

            $products[$key]['source'] = count($products[$key]['sources'] ?? []) > 1
                ? 'ozon_performance_mixed'
                : (string) (($products[$key]['sources'] ?? [])[0] ?? $product['source']);
            $products[$key]['signals'] = $signals;
            $products[$key]['signals_ru'] = array_map([$this, 'advertisingSignalLabel'], $signals);

            $summary['ad_enabled_count'] += (bool) $product['ad_enabled'] ? 1 : 0;
            $summary['total_ad_spend'] += $spend;
            $summary['total_ad_revenue'] += $revenue;
            $summary['total_ad_orders'] += $orders;
            $summary['total_impressions'] += $impressions;
            $summary['total_clicks'] += $clicks;
            $summary['total_to_cart'] += $toCart;
            $summary['high_drr_count'] += in_array('high_ad_cost', $signals, true) ? 1 : 0;
            $summary['ads_driven_count'] += in_array('ads_driven_demand', $signals, true) ? 1 : 0;
        }

        $summary['total_ad_spend'] = round((float) $summary['total_ad_spend'], 2);
        $summary['total_ad_revenue'] = round((float) $summary['total_ad_revenue'], 2);
        $summary['average_drr_percent'] = $summary['total_ad_revenue'] > 0
            ? round($summary['total_ad_spend'] / $summary['total_ad_revenue'] * 100, 2)
            : 0.0;
        $summary['average_cpc'] = $summary['total_clicks'] > 0
            ? round($summary['total_ad_spend'] / $summary['total_clicks'], 2)
            : 0.0;
        $summary['ctr_percent'] = $summary['total_impressions'] > 0
            ? round($summary['total_clicks'] / $summary['total_impressions'] * 100, 2)
            : 0.0;
        $summary['cart_conversion_percent'] = $summary['total_clicks'] > 0
            ? round($summary['total_to_cart'] / $summary['total_clicks'] * 100, 2)
            : 0.0;

        $products = array_values($products);
        $products = array_map(static function (array $product): array {
            $product['aliases'] = array_values(array_unique(array_filter(array_map(
                static fn ($alias): string => trim((string) $alias),
                is_array($product['aliases'] ?? null) ? $product['aliases'] : []
            ))));

            return $product;
        }, $products);

        return [
            'summary' => $summary,
            'products' => $products,
            'by_offer_id' => $this->indexProductsBy($products, 'offer_id'),
            'by_ozon_sku' => $this->indexProductsBy($products, 'ozon_sku'),
        ];
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array<string, mixed>
     */
    private function buildProductReportSkuMap(array $rows): array
    {
        $bySku = [];
        $metaBySku = [];
        $nameToOfferIds = [];

        foreach ($rows as $row) {
            $ozonSku = trim((string) ($row['SKU'] ?? ''));
            $offerId = trim((string) ($row['Артикул'] ?? ''));
            $productName = trim((string) ($row['Название товара'] ?? ''));
            if ($ozonSku === '') {
                continue;
            }

            if ($offerId !== '') {
                $bySku[$ozonSku] = $offerId;
            }

            $metaBySku[$ozonSku] = [
                'offer_id' => $offerId,
                'product_name' => $productName,
                'category' => trim((string) ($row['Категория товара'] ?? '')),
            ];

            $nameKey = $this->normalizeProductName($productName);
            if ($nameKey !== '' && $offerId !== '') {
                $nameToOfferIds[$nameKey][$offerId] = true;
            }
        }

        return [
            'by_sku' => $bySku,
            'meta_by_sku' => $metaBySku,
            'by_name' => $this->uniqueNameMap($nameToOfferIds),
            'name_counts' => $this->nameCounts($nameToOfferIds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLocalProductSkuMap(int $integrationId): array
    {
        $bySku = [];
        $metaBySku = [];
        $nameToOfferIds = [];

        Product::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', 'ozon')
            ->select(['sku', 'vendor_code', 'name', 'category', 'marketplace_id', 'ozon_data'])
            ->chunk(500, function ($products) use (&$bySku, &$metaBySku, &$nameToOfferIds): void {
                foreach ($products as $product) {
                    $offerId = trim((string) ($product->sku ?? ''));
                    if ($offerId === '') {
                        continue;
                    }

                    $ozonData = is_array($product->ozon_data) ? $product->ozon_data : [];
                    $productName = trim((string) ($product->name ?? ''));
                    $category = trim((string) ($product->category ?? ''));
                    $aliases = [
                        $offerId,
                        $product->vendor_code ?? null,
                        $product->marketplace_id ?? null,
                        $ozonData['offer_id'] ?? null,
                        $ozonData['product_id'] ?? null,
                        $ozonData['sku'] ?? null,
                        $ozonData['fbo_sku'] ?? null,
                        $ozonData['fbs_sku'] ?? null,
                    ];

                    foreach ($aliases as $alias) {
                        $alias = trim((string) $alias);
                        if ($alias === '') {
                            continue;
                        }

                        $bySku[$alias] = $offerId;
                        $metaBySku[$alias] = [
                            'offer_id' => $offerId,
                            'product_name' => $productName,
                            'category' => $category,
                        ];
                    }

                    $nameKey = $this->normalizeProductName($productName);
                    if ($nameKey !== '') {
                        $nameToOfferIds[$nameKey][$offerId] = true;
                    }
                }
            }, 'sku');

        return [
            'by_sku' => $bySku,
            'meta_by_sku' => $metaBySku,
            'by_name' => $this->uniqueNameMap($nameToOfferIds),
            'name_counts' => $this->nameCounts($nameToOfferIds),
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeProductSkuMaps(array $base, array $override): array
    {
        $bySku = is_array($base['by_sku'] ?? null) ? $base['by_sku'] : [];
        foreach (is_array($override['by_sku'] ?? null) ? $override['by_sku'] : [] as $key => $value) {
            $bySku[$key] = $value;
        }

        $metaBySku = is_array($base['meta_by_sku'] ?? null) ? $base['meta_by_sku'] : [];
        foreach (is_array($override['meta_by_sku'] ?? null) ? $override['meta_by_sku'] : [] as $key => $value) {
            $metaBySku[$key] = $value;
        }

        $nameCounts = [];
        foreach ([$base['name_counts'] ?? [], $override['name_counts'] ?? []] as $counts) {
            if (! is_array($counts)) {
                continue;
            }
            foreach ($counts as $name => $count) {
                $nameCounts[(string) $name] = (int) ($nameCounts[(string) $name] ?? 0) + (int) $count;
            }
        }

        $byName = array_merge(
            is_array($base['by_name'] ?? null) ? $base['by_name'] : [],
            is_array($override['by_name'] ?? null) ? $override['by_name'] : []
        );
        foreach ($byName as $name => $offerId) {
            if ((int) ($nameCounts[$name] ?? 0) !== 1) {
                unset($byName[$name]);
            }
        }

        return [
            'by_sku' => $bySku,
            'meta_by_sku' => $metaBySku,
            'by_name' => $byName,
            'name_counts' => $nameCounts,
        ];
    }

    /**
     * @param array<string, array<string, bool>> $nameToOfferIds
     * @return array<string, string>
     */
    private function uniqueNameMap(array $nameToOfferIds): array
    {
        $map = [];
        foreach ($nameToOfferIds as $name => $offerIds) {
            $ids = array_keys($offerIds);
            if (count($ids) === 1) {
                $map[(string) $name] = (string) $ids[0];
            }
        }

        return $map;
    }

    /**
     * @param array<string, array<string, bool>> $nameToOfferIds
     * @return array<string, int>
     */
    private function nameCounts(array $nameToOfferIds): array
    {
        $counts = [];
        foreach ($nameToOfferIds as $name => $offerIds) {
            $counts[(string) $name] = count($offerIds);
        }

        return $counts;
    }

    private function normalizeProductName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    /**
     * @param array<string, mixed> $productReportSkuMap
     * @return array{offer_id: string, status: string, mapping_key: string, lookup_key: string, source: string}
     */
    private function resolveCampaignProductMapping(
        string $ozonSku,
        string $offerId,
        string $productName,
        array $productReportSkuMap
    ): array {
        $bySku = is_array($productReportSkuMap['by_sku'] ?? null) ? $productReportSkuMap['by_sku'] : [];
        $byName = is_array($productReportSkuMap['by_name'] ?? null) ? $productReportSkuMap['by_name'] : [];

        if ($offerId !== '') {
            $mapped = trim((string) ($bySku[$offerId] ?? ''));
            $nameKey = $this->normalizeProductName($productName);
            if ($mapped === '' && $nameKey !== '' && isset($byName[$nameKey])) {
                return [
                    'offer_id' => (string) $byName[$nameKey],
                    'status' => 'mapped_by_unique_product_name',
                    'mapping_key' => $productName,
                    'lookup_key' => $offerId,
                    'source' => 'unique_product_name',
                ];
            }

            return [
                'offer_id' => $mapped !== '' ? $mapped : $offerId,
                'status' => $mapped !== '' ? 'mapped_by_local_offer_alias' : 'direct_offer_id',
                'mapping_key' => $offerId,
                'lookup_key' => $offerId,
                'source' => $mapped !== '' ? 'local_alias' : 'campaign_offer_id',
            ];
        }

        if ($ozonSku !== '') {
            $mapped = trim((string) ($bySku[$ozonSku] ?? ''));
            if ($mapped !== '') {
                return [
                    'offer_id' => $mapped,
                    'status' => 'mapped_by_product_sku_alias',
                    'mapping_key' => $ozonSku,
                    'lookup_key' => $ozonSku,
                    'source' => 'sku_alias',
                ];
            }
        }

        $nameKey = $this->normalizeProductName($productName);
        if ($nameKey !== '' && isset($byName[$nameKey])) {
            return [
                'offer_id' => (string) $byName[$nameKey],
                'status' => 'mapped_by_unique_product_name',
                'mapping_key' => $productName,
                'lookup_key' => '',
                'source' => 'unique_product_name',
            ];
        }

        return [
            'offer_id' => '',
            'status' => 'unmapped_ozon_sku',
            'mapping_key' => $ozonSku !== '' ? $ozonSku : $productName,
            'lookup_key' => $ozonSku,
            'source' => 'unmapped',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $productReportSkuMap
     * @return array<int, array<string, mixed>>
     */
    private function campaignStatsRowsToProductReportRows(array $rows, array $productReportSkuMap = []): array
    {
        $converted = [];

        foreach ($rows as $row) {
            $ozonSku = $this->firstString($row, [
                'SKU',
                'sku',
                'productSku',
                'product_sku',
                'product.sku',
                'productId',
                'product_id',
                'product.id',
                'ozon_sku',
                'objectId',
                'object_id',
                'advObjectId',
                'adv_object_id',
                'id',
            ]);
            $rawCampaignProductId = $this->firstString($row, [
                'productId',
                'product_id',
                'product.id',
                'objectId',
                'object_id',
                'advObjectId',
                'adv_object_id',
                'id',
            ]);
            $offerId = $this->firstString($row, [
                'Артикул',
                'offerId',
                'offer_id',
                'externalId',
                'external_id',
                'article',
                'vendorCode',
                'vendor_code',
                'product.offerId',
                'product.offer_id',
            ]);
            $productName = $this->firstString($row, ['Название товара', 'title', 'name', 'productName', 'product_name', 'product.title', 'product.name']);
            $mapping = $this->resolveCampaignProductMapping($ozonSku, $offerId, $productName, $productReportSkuMap);
            $mappedOfferId = $mapping['offer_id'];
            $metaKey = $mapping['lookup_key'] !== '' ? $mapping['lookup_key'] : $ozonSku;
            $meta = is_array(($productReportSkuMap['meta_by_sku'] ?? [])[$metaKey] ?? null)
                ? ($productReportSkuMap['meta_by_sku'][$metaKey] ?? [])
                : [];
            $key = $mappedOfferId !== '' ? $mappedOfferId : $ozonSku;
            if ($key === '') {
                continue;
            }

            $spend = $this->firstNumber($row, [
                'moneySpent',
                'spent',
                'expense',
                'Расход',
                'Расход, ₽',
                'Расход, Р',
                'Расход, Р, с НДС',
                'Расход (Оплата за клик)',
            ]);
            $revenue = $this->firstNumber($row, [
                'ordersMoney',
                'orders_money',
                'revenue',
                'sales',
                'Выручка',
                'Выручка, ₽',
                'Выручка, Р',
                'Продажи',
                'Продажи, ₽',
                'Продажи, Р',
                'Продажи (Оплата за клик)',
                'Выручка (Оплата за клик)',
            ]);
            $orders = $this->firstNumber($row, ['orders', 'ordersCount', 'Заказы', 'Заказы, шт', 'Заказы (Оплата за клик)']);
            $impressions = $this->firstNumber($row, ['views', 'impressions', 'Показы', 'Показы, шт', 'Показы (Оплата за клик)']);
            $clicks = $this->firstNumber($row, ['clicks', 'Клики', 'Клики, шт', 'Клики (Оплата за клик)']);
            $toCart = $this->firstNumber($row, ['toCart', 'to_cart', 'cart', 'В корзину', 'В корзину, шт', 'В корзину (Оплата за клик)']);
            $ctr = $this->firstNumber($row, ['ctr', 'CTR', 'CTR (%)', 'CTR, %', 'CTR (Оплата за клик)']);
            $averageCpc = $this->firstNumber($row, [
                'avgCpc',
                'averageCpc',
                'average_cpc',
                'Ср. цена клика',
                'Средняя стоимость клика',
                'Ср. цена клика (Оплата за клик)',
            ]);
            // «Заказано на сумму» — вся выручка по заказам товара (рекламные + органические),
            // знаменатель для «Общего ДРР» (как в кабинете Ozon).
            $orderedAmount = $this->firstNumber($row, [
                'Заказано на сумму, ₽',
                'Заказано на сумму, Р',
                'Заказано на сумму',
                'orderedAmount',
                'ordered_amount',
            ]);

            $pname = $productName ?: (string) ($meta['product_name'] ?? '');
            $cat = $this->firstString($row, ['Категория товара', 'category', 'categoryName', 'category_name'])
                ?: (string) ($meta['category'] ?? '');
            $campId = (string) ($row['_campaign_id'] ?? $this->firstString($row, ['ID кампании', 'campaignId', 'campaign_id']));
            $paymentType = (string) ($row['_payment_type'] ?? 'cpc');

            if ($paymentType === 'cpo') {
                // CPO («Оплата за заказ»): расход = % от продаж, начисляется на ЗАКАЗЫ МОДЕЛИ
                // (атрибуция продвижения), а не на «прямые» Заказы/Выручка (которые тут = 0).
                // Поэтому заказы/выручку CPO берём из колонок «Заказы модели» / «Выручка с заказов модели».
                $cpoOrders = (int) $this->firstNumber($row, [
                    'Заказы модели (Оплата за клик)',
                    'Заказы модели',
                    'Заказы модели, шт',
                    'ordersModel',
                    'orders_model',
                ]);
                $cpoRevenue = $this->firstNumber($row, [
                    'Выручка с заказов модели (Оплата за клик)',
                    'Продажи с заказов модели (Оплата за клик)',
                    'Выручка с заказов модели',
                    'Выручка с заказов модели, ₽',
                    'Продажи с заказов модели',
                    'revenueModel',
                    'revenue_model',
                ]);
                // Фолбэк на прямые колонки, если у отчёта нет «модельных».
                if ($cpoOrders === 0) {
                    $cpoOrders = (int) $orders;
                }
                if ($cpoRevenue === 0.0) {
                    $cpoRevenue = $revenue;
                }

                // Расход автоматически попадёт в ad_spend (вычитается в юнитке) и в payment_models["Оплата за заказ"].
                // Клики/показы/CTR НЕ примешиваем к CPC, чтобы не путать «оплату за клик» и «за заказ».
                $converted[] = [
                    'SKU' => $ozonSku,
                    'Артикул' => $mappedOfferId,
                    'Название товара' => $pname,
                    'Категория товара' => $cat,
                    'Продвижение' => 'Включено',
                    'Расход (Оплата за заказ)' => $spend,
                    'Продажи (Оплата за заказ)' => $cpoRevenue,
                    'Заказы (Оплата за заказ)' => $cpoOrders,
                    '_source' => 'campaign_cpo_stats',
                    '_payment_type' => 'cpo',
                    '_campaign_id' => $campId,
                    '_mapping_status' => $mapping['status'],
                    '_mapping_key' => $mapping['mapping_key'],
                    '_mapping_source' => $mapping['source'],
                    '_raw_campaign_sku' => $ozonSku,
                    '_raw_campaign_product_id' => $rawCampaignProductId,
                ];

                continue;
            }

            $converted[] = [
                'SKU' => $ozonSku,
                'Артикул' => $mappedOfferId,
                'Название товара' => $pname,
                'Категория товара' => $cat,
                'Продвижение' => 'Включено',
                'Показы (Оплата за клик)' => $impressions,
                'Клики (Оплата за клик)' => $clicks,
                'В корзину (Оплата за клик)' => $toCart,
                'Расход (Оплата за клик)' => $spend,
                'Продажи (Оплата за клик)' => $revenue,
                'Заказы (Оплата за клик)' => $orders,
                'CTR (Оплата за клик)' => $ctr,
                'Ср. цена клика (Оплата за клик)' => $averageCpc,
                'Заказано на сумму' => $orderedAmount,
                '_source' => 'campaign_product_stats_cpc',
                '_campaign_id' => $campId,
                '_payment_type' => 'cpc',
                '_mapping_status' => $mapping['status'],
                '_mapping_key' => $mapping['mapping_key'],
                '_mapping_source' => $mapping['source'],
                '_raw_campaign_sku' => $ozonSku,
                '_raw_campaign_product_id' => $rawCampaignProductId,
            ];
        }

        return $converted;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstNumber(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $number = $this->number($row[$key]);
            if ($number !== 0.0 || trim((string) $row[$key]) === '0') {
                return $number;
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, string> $row
     * @param array<int, string> $needles
     */
    private function sumColumnsContaining(array $row, array $needles): float
    {
        $sum = 0.0;
        foreach ($row as $column => $value) {
            foreach ($needles as $needle) {
                if (str_contains((string) $column, $needle)) {
                    $sum += $this->number($value);
                    break;
                }
            }
        }

        return $sum;
    }

    /**
     * @param array<string, string> $row
     * @return array<string, array<string, mixed>>
     */
    private function extractPaymentModelBreakdown(array $row): array
    {
        $breakdown = [];
        foreach ($row as $column => $value) {
            $columnName = (string) $column;
            $model = $this->paymentModelNameFromColumn($columnName);
            $metric = $this->advertisingMetricFromColumn($columnName);
            if ($metric === null) {
                continue;
            }

            if (! isset($breakdown[$model])) {
                $breakdown[$model] = [
                    'name' => $model,
                    'spend' => 0.0,
                    'revenue' => 0.0,
                    'orders' => 0,
                    'impressions' => 0,
                    'clicks' => 0,
                    'to_cart' => 0,
                ];
            }

            $amount = $this->number($value);
            if (in_array($metric, ['orders', 'impressions', 'clicks', 'to_cart'], true)) {
                $breakdown[$model][$metric] += (int) $amount;
            } else {
                $breakdown[$model][$metric] += $amount;
            }
        }

        return $breakdown;
    }

    /**
     * @param array<string, array<string, mixed>> $left
     * @param array<string, array<string, mixed>> $right
     * @return array<string, array<string, mixed>>
     */
    private function mergePaymentModelBreakdown(array $left, array $right): array
    {
        foreach ($right as $name => $model) {
            if (! isset($left[$name])) {
                $left[$name] = [
                    'name' => $name,
                    'spend' => 0.0,
                    'revenue' => 0.0,
                    'orders' => 0,
                    'impressions' => 0,
                    'clicks' => 0,
                    'to_cart' => 0,
                ];
            }

            foreach (['spend', 'revenue'] as $metric) {
                $left[$name][$metric] += (float) ($model[$metric] ?? 0);
            }
            foreach (['orders', 'impressions', 'clicks', 'to_cart'] as $metric) {
                $left[$name][$metric] += (int) ($model[$metric] ?? 0);
            }
        }

        return $left;
    }

    private function paymentModelNameFromColumn(string $column): string
    {
        if (preg_match('/[\\(（][\"«“]?([^\\)）\"»”]+)[\"»”]?[\\)）]/u', $column, $matches)) {
            return trim($matches[1]) ?: 'Общая модель';
        }

        return 'Общая модель';
    }

    private function advertisingMetricFromColumn(string $column): ?string
    {
        return match (true) {
            str_contains($column, 'Расход') => 'spend',
            str_contains($column, 'Продажи'), str_contains($column, 'Выручка') => 'revenue',
            str_contains($column, 'Заказы') => 'orders',
            str_contains($column, 'Показы') => 'impressions',
            str_contains($column, 'Клики') => 'clicks',
            str_contains($column, 'В корзину') => 'to_cart',
            default => null,
        };
    }

    private function isEnabledRu(mixed $value): bool
    {
        return mb_strtolower(trim((string) $value)) === 'включено';
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<string, array<string, mixed>>
     */
    private function indexProductsBy(array $products, string $field): array
    {
        $indexed = [];
        foreach ($products as $product) {
            $key = trim((string) ($product[$field] ?? ''));
            if ($key !== '') {
                $indexed[$key] = $product;
            }
        }

        return $indexed;
    }

    private function advertisingSignalLabel(string $signal): string
    {
        return match ($signal) {
            'ads_driven_demand' => 'Спрос поддержан рекламой',
            'high_ad_cost' => 'Высокий ДРР',
            'ad_disabled_after_sales' => 'Реклама выключена после рекламных продаж',
            'ad_spend_without_orders' => 'Есть расход рекламы без заказов',
            default => $signal,
        };
    }

    private function errorMessage(mixed $payload): string
    {
        if (is_array($payload)) {
            foreach (['message', 'error_description', 'error'] as $key) {
                if (! empty($payload[$key])) {
                    return (string) $payload[$key];
                }
            }
        }

        return 'Не удалось авторизоваться в Ozon Performance API';
    }
}
