<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Yandex Market Partner API Service
 * Актуальные эндпоинты на 2024-2025 год
 * Документация: https://yandex.ru/dev/market/partner-api
 */
class YandexMarketService implements MarketplaceInterface
{
    private string $token;

    private string $campaignId;

    private ?string $configuredBusinessId;

    private ?string $resolvedBusinessId = null;

    private string $baseUrl = 'https://api.partner.market.yandex.ru';

    private string $defaultCurrency = 'RUR';

    public function __construct(?string $token = null, ?string $campaignId = null, ?string $businessId = null)
    {
        $this->token = $this->normalizeToken((string) ($token ?: config('services.yandex_market.token') ?: ''));
        $this->campaignId = trim((string) ($campaignId ?: config('services.yandex_market.campaign_id') ?: ''));
        $fromConfig = config('services.yandex_market.business_id');
        $raw = $businessId ?? $fromConfig;
        $trimmed = $raw !== null && $raw !== '' ? trim((string) $raw) : '';
        $this->configuredBusinessId = $trimmed !== '' ? $trimmed : null;
    }

    /**
     * ID кабинета для POST /v2/businesses/{businessId}/offer-mappings
     */
    private function businessId(): string
    {
        if ($this->configuredBusinessId !== null) {
            return $this->configuredBusinessId;
        }
        if ($this->resolvedBusinessId !== null) {
            return $this->resolvedBusinessId;
        }
        if ($this->campaignId === '') {
            throw new \RuntimeException('Yandex Market: укажите campaign_id магазина');
        }
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/v2/campaigns/{$this->campaignId}");

        if (! $response->successful()) {
            throw new \RuntimeException($this->formatHttpError('getCampaign(v2)', $response->status(), $response->body()));
        }

        $id = data_get($response->json(), 'campaign.business.id')
            ?? data_get($response->json(), 'result.campaign.business.id');
        if ($id === null || $id === '') {
            throw new \RuntimeException('Yandex Market: в ответе кампании нет business.id');
        }
        $this->resolvedBusinessId = (string) $id;

        return $this->resolvedBusinessId;
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);

        if ($token === '') {
            return '';
        }

        return preg_replace('/^(oauth|bearer)\s+/i', '', $token) ?? $token;
    }

    private function headers(): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if (str_starts_with($this->token, 'ACMA:')) {
            $headers['Api-Key'] = $this->token;
        } else {
            $headers['Authorization'] = "Bearer {$this->token}";
        }

        return $headers;
    }

    /**
     * Получение списка товаров (Partner API v2).
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/ru/reference/business-assortment/getOfferMappings
     */
    public function getProducts(): array
    {
        try {
            $businessId = $this->businessId();
            $products = [];
            $pageToken = null;

            do {
                $query = array_filter([
                    'limit' => 100,
                    'page_token' => $pageToken,
                ]);
                $url = "{$this->baseUrl}/v2/businesses/{$businessId}/offer-mappings";
                if ($query !== []) {
                    $url .= '?'.http_build_query($query);
                }

                $response = Http::withHeaders($this->headers())
                    ->post($url, new \stdClass);

                if (! $response->successful()) {
                    $message = $this->formatHttpError('getProducts(offer-mappings v2)', $response->status(), $response->body());
                    Log::error('Yandex Market API error (getProducts)', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    throw new \RuntimeException($message);
                }

                $data = $response->json();
                $entries = $data['result']['offerMappings'] ?? [];

                foreach ($entries as $entry) {
                    $products[] = $this->transformProduct($entry);
                }

                $pageToken = $data['result']['paging']['nextPageToken'] ?? null;
            } while ($pageToken && count($products) < 100000);

            Log::info('Yandex Market products fetched', ['count' => count($products)]);

            return $products;
        } catch (\Throwable $e) {
            Log::error('YM getProducts error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Трансформация товара YM в формат Product
     */
    private function transformProduct(array $entry): array
    {
        $offer = $entry['offer'] ?? [];
        $mapping = $entry['mapping'] ?? [];

        // Получаем цену
        $price = null;
        if (isset($offer['price']['value'])) {
            $price = (float) $offer['price']['value'];
        }

        $shopSku = trim((string) ($offer['shopSku'] ?? ''));
        $vendorCode = trim((string) ($offer['vendorCode'] ?? ''));
        $marketSku = $mapping['marketSku'] ?? null;
        $sku = $shopSku !== '' ? $shopSku : ($vendorCode !== '' ? $vendorCode : (string) ($marketSku ?? ''));

        return [
            'sku' => $sku,
            'name' => $offer['name'] ?? 'Без названия',
            'barcode' => $offer['barcodes'][0] ?? null,
            'price' => $price,
            'old_price' => null,
            'stock' => 0, // Остатки получаем отдельно
            'description' => $offer['description'] ?? null,
            'images' => $offer['pictures'] ?? $offer['urls'] ?? [],
            'category' => $offer['category'] ?? $mapping['categoryId'] ?? null,
            'brand' => $offer['vendor'] ?? null,
            'rating' => null,
            'reviews_count' => 0,
            'marketplace' => 'yandex_market',
            'marketplace_id' => (string) ($mapping['marketSku'] ?? $sku),
            'url' => isset($mapping['marketSku'])
                ? "https://market.yandex.ru/product/{$mapping['marketSku']}"
                : null,
            'yandex_data' => [
                'shopSku' => $offer['shopSku'] ?? null,
                'marketSku' => $mapping['marketSku'] ?? null,
                'categoryId' => $mapping['categoryId'] ?? null,
                'modelId' => $mapping['modelId'] ?? null,
                'vendorCode' => $offer['vendorCode'] ?? null,
                'availability' => $offer['availability'] ?? null,
                'processingState' => $offer['processingState'] ?? null,
            ],
        ];
    }

    /**
     * Получение остатков по складам (POST /v2/campaigns/{campaignId}/offers/stocks).
     */
    public function getInventory(): array
    {
        try {
            $inventory = [];
            $pageToken = null;

            do {
                $query = array_filter([
                    'limit' => 200,
                    'page_token' => $pageToken,
                ]);
                $url = "{$this->baseUrl}/v2/campaigns/{$this->campaignId}/offers/stocks";
                if ($query !== []) {
                    $url .= '?'.http_build_query($query);
                }

                $response = Http::withHeaders($this->headers())
                    ->post($url, new \stdClass);

                if (! $response->successful()) {
                    $message = $this->formatHttpError('getInventory(offers/stocks v2)', $response->status(), $response->body());
                    Log::error('YM API error (getInventory)', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    throw new \RuntimeException($message);
                }

                $data = $response->json();

                foreach ($data['result']['warehouses'] ?? [] as $warehouse) {
                    $wid = $warehouse['warehouseId'] ?? '';
                    $wname = $warehouse['warehouseName'] ?? ($wid !== '' ? "Склад {$wid}" : 'Склад');
                    foreach ($warehouse['offers'] ?? [] as $offer) {
                        $sku = $offer['offerId'] ?? $offer['shopSku'] ?? null;
                        if ($sku === null || $sku === '') {
                            continue;
                        }
                        foreach ($offer['stocks'] ?? [] as $stock) {
                            $inventory[] = [
                                'sku' => (string) $sku,
                                'warehouse_id' => (string) $wid,
                                'warehouse_name' => $wname,
                                'marketplace' => 'yandex_market',
                                'quantity' => (int) ($stock['count'] ?? 0),
                            ];
                        }
                    }
                }

                $pageToken = $data['result']['paging']['nextPageToken'] ?? null;
            } while ($pageToken && count($inventory) < 50000);

            Log::info('Yandex Market inventory fetched', ['count' => count($inventory)]);

            return $inventory;
        } catch (\Throwable $e) {
            Log::error('YM getInventory error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Список фулфилмент-складов (GET /v2/warehouses?campaignId=…).
     */
    public function getWarehouses(): array
    {
        try {
            if ($this->campaignId === '') {
                return [];
            }

            $response = Http::withHeaders($this->headers())
                ->get("{$this->baseUrl}/v2/warehouses", [
                    'campaignId' => $this->campaignId,
                ]);

            if (! $response->successful()) {
                Log::error('YM getWarehouses error', ['status' => $response->status()]);

                return [];
            }

            $data = $response->json();

            return array_map(function ($wh) {
                return [
                    'id' => $wh['id'] ?? null,
                    'name' => $wh['name'] ?? '',
                    'address' => $wh['address'] ?? null,
                ];
            }, $data['result']['warehouses'] ?? []);
        } catch (\Exception $e) {
            Log::error('YM getWarehouses error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Статистика продаж (POST /v2/campaigns/{campaignId}/stats/skus).
     */
    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->post("{$this->baseUrl}/v2/campaigns/{$this->campaignId}/stats/skus", [
                    'shopSkus' => [],
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                ]);

            if (! $response->successful()) {
                return [];
            }

            return $response->json()['result']['shopSkus'] ?? [];

        } catch (\Exception $e) {
            Log::error('YM getSalesStats error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Получение комиссий
     * Актуальный эндпоинт: POST /tariffs/calculate
     */
    public function getCommissions(): array
    {
        Log::warning('YM getCommissions called without offer context, returning empty result');

        return [];
    }

    public function calculateTariffs(array $offers, array $parameters = []): array
    {
        if (empty($offers)) {
            return [];
        }

        try {
            $sellingProgram = strtoupper((string) ($parameters['sellingProgram'] ?? $parameters['selling_program'] ?? 'FBY'));
            $response = Http::withHeaders($this->headers())
                ->post("{$this->baseUrl}/v2/tariffs/calculate", [
                    'parameters' => [
                        'campaignId' => (int) ($parameters['campaignId'] ?? $parameters['campaign_id'] ?? $this->campaignId),
                        'sellingProgram' => $sellingProgram,
                        'frequency' => strtoupper((string) ($parameters['frequency'] ?? 'DAILY')),
                        'paymentDelayWeeks' => (int) ($parameters['paymentDelayWeeks'] ?? $parameters['payment_delay_weeks'] ?? 0),
                        'currency' => (string) ($parameters['currency'] ?? $this->defaultCurrency),
                    ],
                    'offers' => array_map(function (array $offer) {
                        return [
                            'categoryId' => (int) ($offer['categoryId'] ?? $offer['category_id'] ?? 0),
                            'price' => (float) ($offer['price'] ?? 0),
                            'length' => (float) ($offer['length'] ?? 0),
                            'width' => (float) ($offer['width'] ?? 0),
                            'height' => (float) ($offer['height'] ?? 0),
                            'weight' => (float) ($offer['weight'] ?? 0),
                            'quantity' => (int) ($offer['quantity'] ?? 1),
                        ];
                    }, $offers),
                ]);

            if (! $response->successful()) {
                Log::error('YM calculateTariffs error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return $response->json()['result']['offers'] ?? $response->json()['result'] ?? [];

        } catch (\Exception $e) {
            Log::error('YM calculateTariffs error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function formatHttpError(string $operation, int $status, string $body): string
    {
        $snippet = trim(mb_substr($body, 0, 300));

        return "Yandex Market {$operation} failed [{$status}] {$snippet}";
    }
}
