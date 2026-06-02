<?php

namespace App\Services\Ozon;

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

            $response = $this->authorized($token['access_token'])
                ->post(self::BASE_URL . '/api/client/statistic/products/generate', [
                    'from' => $this->toRfc3339Start($dateFrom),
                    'to' => $this->toRfc3339End($dateTo),
                ]);

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
        ?string $dateTo = null
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
            $campaignStats = null;
            $campaignReportRows = [];

            if ($dateFrom !== null && $dateTo !== null) {
                $campaigns = $this->fetchCampaigns($token['access_token']);
                $campaignIds = array_values(array_filter(array_map(
                    static fn (array $campaign): string => (string) ($campaign['id'] ?? ''),
                    $campaigns['list']
                )));

                $campaignStats = $this->fetchProductCampaignStats(
                    $token['access_token'],
                    $dateFrom,
                    $dateTo,
                    $campaignIds
                );
                $campaignReportRows = $this->campaignStatsRowsToProductReportRows($campaignStats['rows']);
            }

            $impact = $this->buildProductAdvertisingImpact(array_merge($parsed['rows'], $campaignReportRows));

            return [
                'success' => true,
                'status' => 'ok',
                'uuid' => $uuid,
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
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
                    'campaign_totals' => $campaignStats['totals'] ?? null,
                    'note' => $campaignStats !== null
                        ? 'Сводка объединяет товарный UUID-отчёт и статистику товарных PPC-кампаний Ozon за тот же период.'
                        : 'Сводка построена только по UUID-отчёту Ozon. Для кликов/CTR/CPC передайте date_from и date_to.',
                ],
                'summary' => $impact['summary'],
                'products' => $impact['products'],
                'by_offer_id' => $impact['by_offer_id'],
                'by_ozon_sku' => $impact['by_ozon_sku'],
                'usage_for_unit_economics' => [
                    'primary_match_key' => 'offer_id',
                    'fallback_match_key' => 'ozon_sku',
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
        array $campaignIds
    ): array {
        $rows = [];
        $chunks = count($campaignIds) > 0 ? array_chunk($campaignIds, 20) : [[]];

        foreach ($chunks as $chunk) {
            $query = [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ];
            if (count($chunk) > 0) {
                $query['campaignIds'] = $chunk;
            }

            $response = $this->authorized($accessToken)
                ->get(self::BASE_URL . '/api/client/statistics/campaign/product/json', $query);

            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload)) {
                continue;
            }

            $rows = array_merge($rows, $this->extractRows($payload));
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
        ];
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

            $row = array_merge($context, $scalar);
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
     * @param array<string, mixed> $row
     */
    private function looksLikeStatisticRow(array $row): bool
    {
        foreach ([
            'SKU', 'sku', 'offerId', 'offer_id', 'productId', 'product_id',
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
                    'payment_models' => [],
                    'signals' => [],
                    'signals_ru' => [],
                    'source' => 'ozon_performance_product_report',
                    'sources' => [],
                ];
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

        $products = array_values($products);

        return [
            'summary' => $summary,
            'products' => $products,
            'by_offer_id' => $this->indexProductsBy($products, 'offer_id'),
            'by_ozon_sku' => $this->indexProductsBy($products, 'ozon_sku'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function campaignStatsRowsToProductReportRows(array $rows): array
    {
        $converted = [];

        foreach ($rows as $row) {
            $ozonSku = $this->firstString($row, [
                'SKU',
                'sku',
                'productSku',
                'product_sku',
                'productId',
                'product_id',
                'ozon_sku',
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
            ]);
            $key = $offerId !== '' ? $offerId : $ozonSku;
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
            ]);
            $orders = $this->firstNumber($row, ['orders', 'ordersCount', 'Заказы', 'Заказы, шт']);
            $impressions = $this->firstNumber($row, ['views', 'impressions', 'Показы', 'Показы, шт']);
            $clicks = $this->firstNumber($row, ['clicks', 'Клики', 'Клики, шт']);
            $toCart = $this->firstNumber($row, ['toCart', 'to_cart', 'cart', 'В корзину', 'В корзину, шт']);
            $ctr = $this->firstNumber($row, ['ctr', 'CTR', 'CTR (%)', 'CTR, %']);
            $averageCpc = $this->firstNumber($row, [
                'avgCpc',
                'averageCpc',
                'average_cpc',
                'Ср. цена клика',
                'Средняя стоимость клика',
            ]);

            $converted[] = [
                'SKU' => $ozonSku,
                'Артикул' => $offerId,
                'Название товара' => $this->firstString($row, ['Название товара', 'title', 'name', 'productName', 'product_name']),
                'Категория товара' => $this->firstString($row, ['Категория товара', 'category', 'categoryName', 'category_name']),
                'Продвижение' => 'Включено',
                'Показы (Оплата за клик)' => $impressions,
                'Клики (Оплата за клик)' => $clicks,
                'В корзину (Оплата за клик)' => $toCart,
                'Расход (Оплата за клик)' => $spend,
                'Продажи (Оплата за клик)' => $revenue,
                'Заказы (Оплата за клик)' => $orders,
                'CTR (Оплата за клик)' => $ctr,
                'Ср. цена клика (Оплата за клик)' => $averageCpc,
                '_source' => 'campaign_product_stats_cpc',
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
