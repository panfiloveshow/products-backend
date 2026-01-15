<?php

namespace App\Domains\Wildberries\Api;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP клиент для Wildberries API
 */
class WildberriesClient
{
    private const BASE_URL = 'https://marketplace-api.wildberries.ru';
    private const CONTENT_URL = 'https://content-api.wildberries.ru';
    private const SUPPLIERS_URL = 'https://suppliers-api.wildberries.ru';
    private const STATISTICS_URL = 'https://statistics-api.wildberries.ru';
    private const ADVERT_URL = 'https://advert-api.wb.ru';
    private const COMMON_URL = 'https://common-api.wildberries.ru';
    private const ANALYTICS_URL = 'https://seller-analytics-api.wildberries.ru';
    private const PRICES_URL = 'https://discounts-prices-api.wildberries.ru';
    
    private string $apiKey;
    private int $timeout = 30;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('services.wildberries.api_key') ?? '';
    }

    /**
     * Запрос к Content API (карточки товаров)
     */
    public function contentGet(string $endpoint, array $params = []): ?array
    {
        return $this->get($endpoint, $params, self::CONTENT_URL);
    }

    /**
     * Запрос к Content API (карточки товаров)
     */
    public function contentPost(string $endpoint, array $data = []): ?array
    {
        return $this->post($endpoint, $data, self::CONTENT_URL);
    }

    /**
     * Создать клиент из Integration модели
     */
    public static function fromIntegration(Integration $integration): self
    {
        return new self($integration->api_key);
    }

    /**
     * Запрос к Statistics API
     * 
     * Statistics API может быть медленным (особенно /supplier/sales),
     * поэтому используем увеличенный таймаут 60 секунд
     */
    public function statistics(string $endpoint, array $params = []): ?array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(60) // Увеличенный таймаут для Statistics API
                ->get(self::STATISTICS_URL . $endpoint, $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('WB Statistics API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB Statistics API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * GET запрос к API
     */
    public function get(string $endpoint, array $params = [], string $baseUrl = self::BASE_URL): ?array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->get($baseUrl . $endpoint, $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('WB API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * POST запрос к API
     */
    public function post(string $endpoint, array $data = [], string $baseUrl = self::BASE_URL): ?array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->post($baseUrl . $endpoint, $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('WB API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * PUT запрос к API
     */
    public function put(string $endpoint, array $data = [], string $baseUrl = self::BASE_URL): ?array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->put($baseUrl . $endpoint, $data);

            // 204 No Content - успешное обновление без тела ответа
            if ($response->status() === 204) {
                return ['success' => true];
            }

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('WB API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * DELETE запрос к API
     */
    public function delete(string $endpoint, string $baseUrl = self::BASE_URL): ?array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->delete($baseUrl . $endpoint);

            if ($response->status() === 204) {
                return ['success' => true];
            }

            if ($response->successful()) {
                return $response->json() ?? ['success' => true];
            }

            Log::warning('WB API DELETE error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB API DELETE exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * PATCH запрос к API
     */
    public function patch(string $endpoint, array $data = [], string $baseUrl = self::BASE_URL): ?array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->patch($baseUrl . $endpoint, $data);

            if ($response->status() === 204) {
                return ['success' => true];
            }

            if ($response->successful()) {
                return $response->json() ?? ['success' => true];
            }

            Log::warning('WB API PATCH error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB API PATCH exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Запрос к Statistics API (GET)
     * 
     * Statistics API может быть медленным (особенно /supplier/sales),
     * поэтому используем увеличенный таймаут 60 секунд
     */
    public function statisticsGet(string $endpoint, array $params = []): ?array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(60) // Увеличенный таймаут для Statistics API
                ->get(self::STATISTICS_URL . $endpoint, $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('WB Statistics API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB Statistics API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Запрос к Statistics API
     */
    public function getStatistics(string $endpoint, array $params = []): ?array
    {
        return $this->get($endpoint, $params, self::STATISTICS_URL);
    }

    /**
     * Запрос к Seller Analytics API (GET)
     * 
     * Используется для отчётов: Paid Storage, Warehouse Remains и др.
     * URL: https://seller-analytics-api.wildberries.ru
     */
    public function analyticsGet(string $endpoint, array $params = []): ?array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(60)
                ->get(self::ANALYTICS_URL . $endpoint, $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('WB Analytics API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB Analytics API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Запрос к Seller Analytics API (POST)
     * 
     * Используется для аналитики: воронка продаж, рейтинги карточек и др.
     */
    public function analyticsPost(string $endpoint, array $data = []): ?array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(60)
                ->post(self::ANALYTICS_URL . $endpoint, $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('WB Analytics API POST error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB Analytics API POST exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Запрос к Advert API
     */
    public function getAdvert(string $endpoint, array $params = []): ?array
    {
        return $this->get($endpoint, $params, self::ADVERT_URL);
    }

    /**
     * Запрос к Common API (тарифы, коэффициенты)
     */
    public function commonGet(string $endpoint, array $params = []): ?array
    {
        return $this->get($endpoint, $params, self::COMMON_URL);
    }

    /**
     * GET запрос к Prices API (цены и скидки)
     */
    public function pricesGet(string $endpoint, array $params = []): ?array
    {
        return $this->get($endpoint, $params, self::PRICES_URL);
    }
    
    /**
     * POST запрос к Prices API (цены и скидки)
     */
    public function pricesPost(string $endpoint, array $data = []): ?array
    {
        return $this->post($endpoint, $data, self::PRICES_URL);
    }

    /**
     * Заголовки запроса
     * 
     * WB API использует два формата авторизации:
     * - Старый: Authorization: {api_key} (без Bearer)
     * - Новый: Authorization: Bearer {api_key}
     * 
     * Большинство эндпоинтов работают с обоими форматами,
     * но некоторые (Statistics API) требуют формат без Bearer.
     * 
     * @see https://dev.wildberries.ru/openapi/api-information
     */
    private function getHeaders(): array
    {
        return [
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Установить таймаут
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }
}
