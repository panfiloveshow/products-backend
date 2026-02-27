<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SellicoApiService
{
    private string $baseUrl;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->baseUrl = config('services.sellico.base_url', 'https://sellico.ru/api');
    }

    /**
     * Авторизация в Sellico API
     */
    public function login(string $email, string $password): array
    {
        try {
            $response = Http::post("{$this->baseUrl}/login", [
                'email' => $email,
                'password' => $password,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'] ?? null;
                
                // Кэшируем токен
                if ($this->accessToken) {
                    Cache::put('sellico_access_token', $this->accessToken, now()->addHours(23));
                }

                return [
                    'success' => true,
                    'access_token' => $this->accessToken,
                    'user' => $data['user'] ?? null,
                    'integrations' => $data['integrations'] ?? [],
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Ошибка авторизации'),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico login error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить список workspaces пользователя
     */
    public function getWorkspaces(): array
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Не авторизован в Sellico API',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/workspaces");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'workspaces' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Ошибка получения workspaces'),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico get workspaces error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить интеграции с маркетплейсами (API ключи) для workspace
     */
    public function getIntegrations(int $workspaceId): array
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Не авторизован в Sellico API',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/workspaces/{$workspaceId}/integrations");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'integrations' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Ошибка получения интеграций'),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico get integrations error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить API ключи маркетплейсов для workspace
     */
    public function getMarketplaceCredentials(int $workspaceId): array
    {
        $result = $this->getIntegrations($workspaceId);
        
        if (!$result['success']) {
            return $result;
        }

        $integrations = [];
        
        foreach ($result['integrations'] as $integration) {
            $type = strtolower($integration['type'] ?? '');
            
            $item = [
                'id' => $integration['id'],
                'name' => $integration['name'],
                'type' => $integration['type'],
                'api_key' => $integration['api_key'] ?? null,
                'client_id' => $integration['client_id'] ?? null,
                'created_at' => $integration['created_at'] ?? null,
            ];
            
            // Группируем по типу маркетплейса
            $marketplaceType = match ($type) {
                'wildberries' => 'wildberries',
                'ozon' => 'ozon',
                'yandexmarket' => 'yandex_market',
                default => $type,
            };
            
            if (!isset($integrations[$marketplaceType])) {
                $integrations[$marketplaceType] = [];
            }
            $integrations[$marketplaceType][] = $item;
        }

        return [
            'success' => true,
            'integrations' => $integrations,
            'all' => $result['integrations'],
        ];
    }

    /**
     * Сохранить API ключ маркетплейса
     */
    public function saveMarketplaceCredentials(string $marketplace, array $credentials): array
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Не авторизован в Sellico API',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->post("{$this->baseUrl}/integrations/{$marketplace}", $credentials);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'integration' => $response->json('data'),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Ошибка сохранения интеграции'),
            ];
        } catch (\Exception $e) {
            Log::error("Sellico save {$marketplace} credentials error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Удалить интеграцию с маркетплейсом
     */
    public function deleteIntegration(string $marketplace): array
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Не авторизован в Sellico API',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->delete("{$this->baseUrl}/integrations/{$marketplace}");

            if ($response->successful()) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Ошибка удаления интеграции'),
            ];
        } catch (\Exception $e) {
            Log::error("Sellico delete {$marketplace} integration error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Проверить подключение к маркетплейсу
     */
    public function testConnection(string $marketplace): array
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Не авторизован в Sellico API',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->post("{$this->baseUrl}/integrations/{$marketplace}/test");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'connected' => $response->json('connected', false),
                    'message' => $response->json('message'),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Ошибка проверки подключения'),
            ];
        } catch (\Exception $e) {
            Log::error("Sellico test {$marketplace} connection error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить текущий access token
     */
    private function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        return Cache::get('sellico_access_token');
    }

    /**
     * Установить access token
     */
    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token;
        Cache::put('sellico_access_token', $token, now()->addHours(23));
    }

    /**
     * Получить профиль пользователя
     */
    public function getProfile(): array
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Не авторизован в Sellico API',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/me");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'user' => $response->json('data'),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Ошибка получения профиля'),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico get profile error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить интеграцию по ID
     */
    public function getIntegrationById(int $integrationId): array
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Не авторизован в Sellico API',
            ];
        }

        try {
            // 1) Пробуем прямой endpoint (может отсутствовать на некоторых версиях Sellico API)
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/integrations/{$integrationId}");

            if ($response->successful()) {
                $data = $response->json('data') ?? $response->json();

                return [
                    'success' => true,
                    'integration' => $data,
                    'credentials' => $data['credentials'] ?? [
                        'api_key' => $data['api_key'] ?? null,
                        'client_id' => $data['client_id'] ?? null,
                        'token' => $data['token'] ?? null,
                        'campaign_id' => $data['campaign_id'] ?? null,
                        'business_id' => $data['business_id'] ?? null,
                    ],
                ];
            }

            // 2) Fallback: ищем интеграцию во всех workspace пользователя
            $workspacesResponse = Http::withToken($token)
                ->get("{$this->baseUrl}/workspaces");

            Log::info('getIntegrationById fallback: workspaces response', [
                'status' => $workspacesResponse->status(),
                'body_preview' => substr($workspacesResponse->body(), 0, 500),
            ]);

            if ($workspacesResponse->successful()) {
                $workspacesRaw = $workspacesResponse->json('data') ?? $workspacesResponse->json();
                $workspaces = is_array($workspacesRaw) ? $workspacesRaw : [];

                Log::info('getIntegrationById fallback: workspaces parsed', [
                    'count' => count($workspaces),
                    'first_keys' => !empty($workspaces) ? array_keys((array)($workspaces[0] ?? [])) : [],
                ]);

                foreach ($workspaces as $workspace) {
                    $workspaceId = $workspace['id'] ?? null;
                    if (!$workspaceId) {
                        continue;
                    }

                    $integrationsResponse = Http::withToken($token)
                        ->get("{$this->baseUrl}/workspaces/{$workspaceId}/integrations");

                    Log::info('getIntegrationById fallback: workspace integrations', [
                        'workspace_id' => $workspaceId,
                        'status' => $integrationsResponse->status(),
                        'body_preview' => substr($integrationsResponse->body(), 0, 500),
                    ]);

                    if (!$integrationsResponse->successful()) {
                        continue;
                    }

                    $integrationsRaw = $integrationsResponse->json('data') ?? $integrationsResponse->json();
                    $integrations = is_array($integrationsRaw) ? $integrationsRaw : [];

                    Log::info('getIntegrationById fallback: integrations parsed', [
                        'workspace_id' => $workspaceId,
                        'count' => count($integrations),
                        'ids' => array_slice(array_column($integrations, 'id'), 0, 10),
                    ]);

                    foreach ($integrations as $integration) {
                        if ((int)($integration['id'] ?? 0) !== $integrationId) {
                            continue;
                        }

                        return [
                            'success' => true,
                            'integration' => $integration,
                            'credentials' => $integration['credentials'] ?? [
                                'api_key' => $integration['api_key'] ?? null,
                                'client_id' => $integration['client_id'] ?? null,
                                'token' => $integration['token'] ?? null,
                                'campaign_id' => $integration['campaign_id'] ?? null,
                                'business_id' => $integration['business_id'] ?? null,
                            ],
                        ];
                    }
                }
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Интеграция не найдена в Sellico API'),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico get integration by id error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить список товаров (SKU) интеграции из Sellico
     */
    public function getIntegrationProducts(int $integrationId): array
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Не авторизован в Sellico API',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/integrations/{$integrationId}/products");

            if ($response->successful()) {
                $products = $response->json('data', []);
                $skus = array_column($products, 'sku');
                
                return [
                    'success' => true,
                    'skus' => array_filter($skus),
                    'count' => count($skus),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Ошибка получения товаров интеграции'),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico get integration products error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
