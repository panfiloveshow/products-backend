<?php

namespace App\Domains\Wildberries\Api;

use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
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
        $credentials = $integration->getDecryptedCredentials();
        return new self($credentials['api_key'] ?? null);
    }

    /**
     * Запрос к Statistics API
     * 
     * Statistics API может быть медленным (особенно /supplier/sales),
     * поэтому используем увеличенный таймаут 60 секунд
     */
    public function statistics(string $endpoint, array $params = []): ?array
    {
        $requestId = uniqid('wb_stat_', true);
        
        Log::info('WB Statistics API Request', [
            'request_id' => $requestId,
            'method' => 'GET',
            'endpoint' => $endpoint,
            'url' => self::STATISTICS_URL . $endpoint,
            'params' => $this->sanitizeParams($params),
            'api_key_prefix' => substr($this->apiKey, 0, 6) . '***',
        ]);

        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->rateLimitDelay();

                $response = Http::withHeaders($this->getHeaders())
                    ->timeout(60)
                    ->get(self::STATISTICS_URL . $endpoint, $params);

                $status = $response->status();
                $body = $response->body();

                Log::info('WB Statistics API Response', [
                    'request_id' => $requestId,
                    'method' => 'GET',
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'response_size' => strlen($body),
                    'response_sample' => $this->truncateResponse($body, 500),
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($status === 429 && $attempt < $maxAttempts) {
                    $delay = min(3 * pow(2, $attempt - 1), 20);
                    Log::info('WB Statistics API 429 rate limit, retrying', [
                        'request_id' => $requestId, 'attempt' => $attempt, 'delay_s' => $delay,
                    ]);
                    sleep($delay);
                    continue;
                }

                Log::warning('WB Statistics API error', [
                    'request_id' => $requestId,
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'body' => substr($body, 0, 500),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('WB Statistics API exception', [
                    'request_id' => $requestId,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }

    /**
     * GET запрос к API
     */
    public function get(string $endpoint, array $params = [], string $baseUrl = self::BASE_URL): ?array
    {
        $requestId = uniqid('wb_req_', true);
        $apiName = $this->getApiName($baseUrl);

        Log::info('WB API Request', [
            'request_id' => $requestId,
            'api' => $apiName,
            'method' => 'GET',
            'endpoint' => $endpoint,
            'url' => $baseUrl . $endpoint,
            'params' => $this->sanitizeParams($params),
            'api_key_prefix' => substr($this->apiKey, 0, 6) . '***',
        ]);

        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->rateLimitDelay();

                $response = Http::withHeaders($this->getHeaders())
                    ->timeout($this->timeout)
                    ->get($baseUrl . $endpoint, $params);

                $status = $response->status();
                $body = $response->body();

                Log::info('WB API Response', [
                    'request_id' => $requestId,
                    'api' => $apiName,
                    'method' => 'GET',
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'response_size' => strlen($body),
                    'response_sample' => $this->truncateResponse($body, 500),
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                // Retry при 429 (rate limit)
                if ($status === 429 && $attempt < $maxAttempts) {
                    $delay = min(2 * pow(2, $attempt - 1), 15);
                    Log::info('WB API 429 rate limit, retrying', [
                        'request_id' => $requestId, 'attempt' => $attempt, 'delay_s' => $delay,
                    ]);
                    sleep($delay);
                    continue;
                }

                Log::warning('WB API error', [
                    'request_id' => $requestId,
                    'api' => $apiName,
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'body' => substr($body, 0, 500),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('WB API exception', [
                    'request_id' => $requestId,
                    'api' => $apiName,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }

    /**
     * GET запрос к API с Bearer авторизацией
     * 
     * Некоторые эндпоинты Wildberries (особенно новые Marketplace API v3)
     * требуют формат авторизации: "Bearer {api_key}"
     * 
     * @see https://dev.wildberries.ru/openapi/api-information
     */
    public function getWithBearer(string $endpoint, array $params = [], string $baseUrl = self::BASE_URL): ?array
    {
        try {
            $response = Http::withHeaders($this->getBearerHeaders())
                ->timeout($this->timeout)
                ->get($baseUrl . $endpoint, $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('WB API error (Bearer)', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('WB API exception (Bearer)', [
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
        $requestId = uniqid('wb_req_', true);
        $apiName = $this->getApiName($baseUrl);

        Log::info('WB API Request', [
            'request_id' => $requestId,
            'api' => $apiName,
            'method' => 'POST',
            'endpoint' => $endpoint,
            'url' => $baseUrl . $endpoint,
            'payload_size' => strlen(json_encode($data)),
            'payload_sample' => $this->truncateResponse(json_encode($data), 500),
            'api_key_prefix' => substr($this->apiKey, 0, 6) . '***',
        ]);

        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->rateLimitDelay();

                $response = Http::withHeaders($this->getHeaders())
                    ->timeout($this->timeout)
                    ->post($baseUrl . $endpoint, $data);

                $status = $response->status();
                $body = $response->body();

                Log::info('WB API Response', [
                    'request_id' => $requestId,
                    'api' => $apiName,
                    'method' => 'POST',
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'response_size' => strlen($body),
                    'response_sample' => $this->truncateResponse($body, 500),
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                // Retry при 429 (rate limit)
                if ($status === 429 && $attempt < $maxAttempts) {
                    $delay = min(2 * pow(2, $attempt - 1), 15);
                    Log::info('WB API 429 rate limit, retrying', [
                        'request_id' => $requestId, 'attempt' => $attempt, 'delay_s' => $delay,
                    ]);
                    sleep($delay);
                    continue;
                }

                Log::warning('WB API error', [
                    'request_id' => $requestId,
                    'api' => $apiName,
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'body' => substr($body, 0, 500),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('WB API exception', [
                    'request_id' => $requestId,
                    'api' => $apiName,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
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
        $tokenHash = substr(sha1($this->apiKey), 0, 16);
        $disabledKey = "wb:analytics:disabled:{$tokenHash}";
        if (Cache::get($disabledKey)) {
            return null;
        }

        // WB docs: для /api/analytics/v1/stocks-report/wb-warehouses лимит ~1 запрос / 20 секунд на продавца.
        // Защищаемся от параллельных job-ов (несколько queue воркеров / несколько интеграций).
        $lockSeconds = str_contains($endpoint, 'stocks-report/wb-warehouses') ? 20 : 1;
        $lockKey = "wb:analytics:post:{$tokenHash}:".sha1($endpoint);

        $lock = Cache::lock($lockKey, $lockSeconds);
        if (! $lock->get()) {
            // Не блокируем воркер: пусть InventoryApi уйдёт на legacy Statistics API.
            return null;
        }

        try {
            $attempts = 0;
            $maxAttempts = 3;
            $sleepSeconds = 0;

            while (true) {
                if ($sleepSeconds > 0) {
                    sleep($sleepSeconds);
                }

                $attempts++;
                $response = Http::withHeaders($this->getHeaders())
                    ->timeout(60)
                    ->post(self::ANALYTICS_URL.$endpoint, $data);

                if ($response->successful()) {
                    return $response->json();
                }

                $status = $response->status();
                $body = (string) $response->body();

                // 403 "base token is not allowed" — токен типа Basic не имеет доступа к этому пути.
                // Чтобы не спамить лог и не долбить API, отключаем Analytics API для этого токена на сутки.
                if ($status === 403 && str_contains($body, 'base token is not allowed')) {
                    Cache::put($disabledKey, true, now()->addDay());
                    Log::warning('WB Analytics API disabled for token (base token forbidden)', [
                        'endpoint' => $endpoint,
                        'status' => $status,
                    ]);
                    return null;
                }

                // 429 — глобальный лимитер. Для stocks-report по документации ~20 секунд.
                if ($status === 429 && $attempts < $maxAttempts) {
                    $sleepSeconds = 20;
                    continue;
                }

                Log::warning('WB Analytics API POST error', [
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'body' => substr($body, 0, 500),
                ]);

                return null;
            }
        } catch (\Exception $e) {
            Log::error('WB Analytics API POST exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            optional($lock)->release();
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
    /**
     * Rate limiting — 300ms пауза между запросами к WB API
     */
    private function rateLimitDelay(): void
    {
        $cacheKey = 'wb_api_last_req:' . substr(md5($this->apiKey ?? ''), 0, 8);
        $lastMs = Cache::get($cacheKey, 0);
        $elapsed = (microtime(true) * 1000) - $lastMs;

        if ($elapsed < 300) {
            usleep((int) ((300 - $elapsed) * 1000));
        }

        Cache::put($cacheKey, microtime(true) * 1000, 60);
    }

    private function getHeaders(): array
    {
        return [
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Заголовки запроса с Bearer авторизацией
     * 
     * Новые эндпоинты Marketplace API v3 требуют Bearer формат.
     * 
     * @see https://dev.wildberries.ru/openapi/api-information
     */
    private function getBearerHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
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

    /**
     * Получить имя API по URL
     */
    private function getApiName(string $url): string
    {
        return match ($url) {
            self::BASE_URL => 'Marketplace',
            self::CONTENT_URL => 'Content',
            self::STATISTICS_URL => 'Statistics',
            self::ANALYTICS_URL => 'Analytics',
            self::PRICES_URL => 'Prices',
            self::COMMON_URL => 'Common',
            self::ADVERT_URL => 'Advert',
            self::SUPPLIERS_URL => 'Suppliers',
            default => 'Unknown',
        };
    }

    /**
     * Обрезать параметры для логирования (убрать чувствительные данные)
     */
    private function sanitizeParams(array $params): array
    {
        return array_map(function ($value) {
            if (is_string($value) && strlen($value) > 50) {
                return substr($value, 0, 50) . '...';
            }
            return $value;
        }, $params);
    }

    /**
     * Обрезать тело ответа для логирования
     */
    private function truncateResponse(string $body, int $maxLength = 500): string
    {
        if (strlen($body) <= $maxLength) {
            return $body;
        }
        return substr($body, 0, $maxLength) . '... [truncated]';
    }
}
