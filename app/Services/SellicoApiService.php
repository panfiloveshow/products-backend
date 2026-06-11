<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SellicoApiService
{
    private string $baseUrl;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->baseUrl = config('services.sellico.base_url') ?? 'https://sellico.ru/api';
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
                
                // Кэшируем токен пользователя (отдельный ключ, не пересекается с сервис-аккаунтом)
                if ($this->accessToken) {
                    Cache::put('sellico_user_access_token', $this->accessToken, now()->addHours(23));
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
        if (!$workspaceId) {
            return [
                'success' => false,
                'error' => 'workspace_id обязателен для получения интеграций',
            ];
        }

        $token = $this->getAccessToken();
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Не авторизован в Sellico API',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/get-integrations/{$workspaceId}");

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
                'id'             => $integration['id'],
                'work_space_id'  => $integration['work_space_id'] ?? $integration['workspace_id'] ?? $integration['workSpaceId'] ?? $integration['workspaceId'] ?? null,
                'name'           => $integration['name'],
                'type'           => $integration['type'],
                'description'    => $integration['description'] ?? null,
                'account_status' => $integration['account_status'] ?? null,
                'is_premium'     => $integration['is_premium'] ?? false,
                'api_key'        => $integration['api_key'] ?? null,
                'client_id'      => $integration['client_id'] ?? null,
                'created_at'     => $integration['created_at'] ?? null,
                'updated_at'     => $integration['updated_at'] ?? null,
            ];
            
            // Группируем по типу маркетплейса
            $marketplaceType = match ($type) {
                'wildberries' => 'wildberries',
                'ozon'        => 'ozon',
                'yandexmarket' => 'yandex_market',
                default       => $type,
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

        $cached = Cache::get('sellico_user_access_token');
        if ($cached) {
            $this->accessToken = $cached;
            return $cached;
        }

        // Автологин через .env credentials
        return $this->getServiceToken();
    }

    /**
     * Получить сервисный токен (логин через .env credentials)
     */
    public function getServiceToken(bool $forceRefresh = false): ?string
    {
        if ($forceRefresh) {
            // Токен Sellico живёт меньше, чем наш 23ч кэш: протухший токен даёт 401
            // на всех server-to-server вызовах (интеграции, лимиты, reconcile).
            // forceRefresh сбрасывает кэш и логинится заново.
            Cache::forget('sellico_service_access_token');
        }

        $cached = $forceRefresh ? null : Cache::get('sellico_service_access_token');
        if ($cached) {
            return $cached;
        }

        $email    = config('services.sellico.email')    ?? env('SELLICO_EMAIL');
        $password = config('services.sellico.password') ?? env('SELLICO_PASSWORD');

        if (!$email || !$password) {
            return null;
        }

        try {
            $response = Http::timeout(10)->post("{$this->baseUrl}/login", [
                'email' => $email,
                'password' => $password,
            ]);

            if ($response->successful()) {
                $token = $response->json('access_token');
                if ($token) {
                    Cache::put('sellico_service_access_token', $token, now()->addHours(23));
                    Log::info('Sellico service login successful');
                    return $token;
                }
            }

            Log::error('Sellico service login failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 200),
            ]);
        } catch (\Exception $e) {
            Log::error('Sellico service login exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Выполнить запрос с сервисным токеном и авто-перелогином при 401.
     *
     * $perform получает токен и должен вернуть Response. Если ответ 401
     * (кэшированный токен протух раньше нашего 23ч TTL) — сбрасываем кэш,
     * логинимся заново и повторяем запрос один раз. Возвращает null, только
     * если сервисный токен вообще не удалось получить.
     */
    private function withServiceTokenRetry(\Closure $perform): ?\Illuminate\Http\Client\Response
    {
        $token = $this->getServiceToken();
        if (! $token) {
            return null;
        }

        $response = $perform($token);
        if ($response->status() === 401) {
            $fresh = $this->getServiceToken(true);
            if ($fresh && $fresh !== $token) {
                $response = $perform($fresh);
            }
        }

        return $response;
    }

    /**
     * Установить access token
     */
    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token;
        Cache::put('sellico_user_access_token', $token, now()->addHours(23));
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
     *
     * Логика:
     * 1. Логинимся сервисным аккаунтом (autobidder) через /api/login
     * 2. Ищем интеграцию через /api/get-integrations/{workspaceId}
     *    (прямой endpoint /api/integrations/{id} не существует в Sellico CRM)
     */
    public function getIntegrationById(int $integrationId, ?int $workspaceId = null): array
    {
        $serviceToken = $this->getServiceToken();
        $result = $this->searchIntegrationById($integrationId, $workspaceId, $serviceToken);

        // Кэшированный сервисный токен (23ч) переживает реальный срок жизни токена
        // Sellico — протухший токен даёт 401, и интеграция «не находится». Один раз
        // перелогиниваемся свежим токеном и повторяем поиск.
        if (! ($result['success'] ?? false)) {
            $freshToken = $this->getServiceToken(true);
            if ($freshToken && $freshToken !== $serviceToken) {
                $retry = $this->searchIntegrationById($integrationId, $workspaceId, $freshToken);
                if ($retry['success'] ?? false) {
                    return $retry;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function searchIntegrationById(int $integrationId, ?int $workspaceId, ?string $serviceToken): array
    {
        $tokens = [];

        if ($this->accessToken) {
            $tokens['user'] = $this->accessToken;
        }

        if ($serviceToken) {
            $tokens['service'] = $serviceToken;
        }

        if (empty($tokens)) {
            Log::error('getIntegrationById: не удалось получить сервисный токен Sellico');
            return [
                'success' => false,
                'error' => 'Не удалось авторизоваться в Sellico API (сервисный аккаунт)',
            ];
        }

        try {
            foreach ($tokens as $tokenType => $token) {
                $response = Http::timeout(8)->withToken($token)
                    ->get("{$this->baseUrl}/get-integration/{$integrationId}");

                if (! $response->successful()) {
                    Log::info('getIntegrationById: direct endpoint miss', [
                        'integration_id' => $integrationId,
                        'workspace_id' => $workspaceId,
                        'token_type' => $tokenType,
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                $integration = $response->json('data') ?? $response->json();
                if (is_array($integration) && ! empty($integration)) {
                    $remoteWorkspaceId = IntegrationAccessService::extractRemoteWorkspaceIdFromSellicoPayload($integration);
                    if ($workspaceId && $remoteWorkspaceId && $remoteWorkspaceId !== $workspaceId) {
                        continue;
                    }

                    return $this->formatIntegrationResult($integration);
                }
            }

            $workspaceIds = $this->getCandidateWorkspaceIds($integrationId, $workspaceId, $tokens);

            Log::info('getIntegrationById: searching', [
                'integration_id' => $integrationId,
                'workspace_ids' => $workspaceIds,
            ]);

            foreach ($tokens as $tokenType => $token) {
                foreach ($workspaceIds as $candidateWorkspaceId) {
                    $response = Http::timeout(8)->withToken($token)
                        ->get("{$this->baseUrl}/get-integrations/{$candidateWorkspaceId}");

                    if (! $response->successful()) {
                        Log::info('getIntegrationById: workspace endpoint miss', [
                            'integration_id' => $integrationId,
                            'workspace_id' => $candidateWorkspaceId,
                            'token_type' => $tokenType,
                            'status' => $response->status(),
                        ]);
                        continue;
                    }

                    $integrations = $response->json('data') ?? $response->json();
                    if (! is_array($integrations)) {
                        continue;
                    }

                    foreach ($integrations as $integration) {
                        if ((int) ($integration['id'] ?? 0) !== $integrationId) {
                            continue;
                        }

                        Log::info('getIntegrationById: found', [
                            'integration_id' => $integrationId,
                            'workspace_id' => $candidateWorkspaceId,
                            'token_type' => $tokenType,
                            'type' => $integration['type'] ?? 'unknown',
                        ]);

                        return $this->formatIntegrationResult($integration);
                    }
                }
            }

            Log::warning('getIntegrationById: not found in any workspace', [
                'integration_id' => $integrationId,
                'searched_workspaces' => $workspaceIds,
            ]);

            return [
                'success' => false,
                'error' => "Интеграция #{$integrationId} не найдена в Sellico API",
            ];
        } catch (\Exception $e) {
            Log::error('getIntegrationById exception', [
                'integration_id' => $integrationId,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, string> $tokens
     * @return array<int, int>
     */
    private function getCandidateWorkspaceIds(int $integrationId, ?int $workspaceId, array $tokens): array
    {
        $workspaceIds = [];

        if ($workspaceId) {
            $workspaceIds[] = $workspaceId;
        }

        $localWorkspaceId = Integration::where('id', $integrationId)->value('work_space_id');
        if ($localWorkspaceId) {
            $workspaceIds[] = (int) $localWorkspaceId;
        }

        foreach ($tokens as $tokenType => $token) {
            $response = Http::timeout(8)->withToken($token)->get("{$this->baseUrl}/workspaces");
            if (! $response->successful()) {
                Log::info('getIntegrationById: workspaces endpoint miss', [
                    'integration_id' => $integrationId,
                    'token_type' => $tokenType,
                    'status' => $response->status(),
                ]);
                continue;
            }

            $workspacesRaw = $response->json('data') ?? $response->json();
            $workspaces = is_array($workspacesRaw) ? $workspacesRaw : [];

            foreach ($workspaces as $workspace) {
                $candidateId = $workspace['id'] ?? null;
                if ($candidateId) {
                    $workspaceIds[] = (int) $candidateId;
                }
            }
        }

        return array_values(array_unique(array_filter($workspaceIds)));
    }

    private function formatIntegrationResult(array $integration): array
    {
        return [
            'success' => true,
            'integration' => $integration,
            'credentials' => $integration['credentials'] ?? [
                'api_key' => $integration['api_key'] ?? null,
                'client_id' => $integration['client_id'] ?? null,
                'token' => $integration['token'] ?? null,
                'campaign_id' => $integration['campaign_id'] ?? null,
                'business_id' => $integration['business_id'] ?? null,
                'performance_api_key' => $integration['performance_api_key'] ?? null,
                'performance_client_secret' => $integration['performance_client_secret'] ?? null,
            ],
        ];
    }

    /**
     * Отправить активность пользователя в PlaceSales API.
     *
     * POST /workspaces/{workspace}/activities
     *
     * ТРЕБУЕТСЯ пользовательский токен. Service-account токен НЕ подходит —
     * activity должна быть привязана к реальному user_id в PlaceSales.
     * Service token не используется как fallback: лучше не отправить activity,
     * чем отправить её от имени service-account и исказить аудит в CRM.
     *
     * @param  int                 $workspaceId  ID рабочего пространства PlaceSales.
     * @param  string              $action       Machine-readable ключ события (`products_sync_started`, `integration_created`, ...).
     * @param  string              $title        Человекочитаемый заголовок.
     * @param  string|null         $description  Доп. описание.
     * @param  array<string,mixed> $meta         Произвольные метаданные (entity_type, entity_id, counters).
     * @param  string|null         $token        Пользовательский токен. Если null — берётся из текущей сессии
     *                                           ($this->accessToken или cache 'sellico_user_access_token').
     *                                           Если user-токен не найден — activity НЕ отправляется.
     */
    public function sendActivity(
        int $workspaceId,
        string $action,
        string $title,
        ?string $description = null,
        array $meta = [],
        ?string $token = null
    ): array {
        if ($workspaceId <= 0) {
            return [
                'success' => false,
                'error' => 'workspace_id обязателен для отправки активности',
            ];
        }

        $token = $token ?? $this->accessToken ?? Cache::get('sellico_user_access_token');

        if (! $token) {
            Log::info('Sellico sendActivity skipped: нет пользовательского токена', [
                'workspace_id' => $workspaceId,
                'action' => $action,
            ]);

            return [
                'success' => false,
                'error' => 'Не передан пользовательский токен (service token для activities не используется)',
                'skipped' => true,
            ];
        }

        $payload = [
            'action' => $action,
            'title' => $title,
        ];

        if ($description !== null && $description !== '') {
            $payload['description'] = $description;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        try {
            $response = Http::timeout(8)
                ->withToken($token)
                ->acceptJson()
                ->post("{$this->baseUrl}/workspaces/{$workspaceId}/activities", $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'activity' => $response->json(),
                ];
            }

            Log::warning('Sellico sendActivity failed', [
                'workspace_id' => $workspaceId,
                'action' => $action,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ]);

            return [
                'success' => false,
                'error' => $response->json('message', "HTTP {$response->status()}"),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico sendActivity exception', [
                'workspace_id' => $workspaceId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить внешние лимиты workspace из основного PlaceSales backend.
     *
     * GET /workspaces/{workspace}/limits-external
     *
     * @param  int $workspaceId
     * @return array<string,mixed>
     */
    public function getWorkspaceLimitsExternal(int $workspaceId, ?string $type = null): array
    {
        if ($workspaceId <= 0) {
            return [
                'success' => false,
                'error' => 'workspace_id обязателен для получения лимитов',
                'status' => 422,
            ];
        }

        try {
            $response = $this->withServiceTokenRetry(fn (string $token) => Http::timeout(8)
                ->withToken($token)
                ->acceptJson()
                ->get("{$this->baseUrl}/workspaces/{$workspaceId}/limits-external", array_filter([
                    'type' => $type,
                ])));

            if ($response === null) {
                return [
                    'success' => false,
                    'error' => 'Не удалось получить service account token Sellico API',
                    'status' => 401,
                ];
            }

            if ($response->successful()) {
                return [
                    'success' => true,
                    'limits' => $response->json(),
                    'status' => $response->status(),
                ];
            }

            Log::warning('Sellico getWorkspaceLimitsExternal failed', [
                'workspace_id' => $workspaceId,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ]);

            return [
                'success' => false,
                'error' => $response->json('message', "HTTP {$response->status()}"),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico getWorkspaceLimitsExternal exception', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 502,
            ];
        }
    }

    /**
     * Сохранить внешние лимиты workspace в основном PlaceSales backend.
     *
     * POST /workspaces/{workspace}/limits-external
     *
     * @param  int                 $workspaceId
     * @param  array<string,mixed> $payload Accepts current_value internally, sends value to PlaceSales.
     * @return array<string,mixed>
     */
    public function storeWorkspaceLimitExternal(int $workspaceId, array $payload): array
    {
        if ($workspaceId <= 0) {
            return [
                'success' => false,
                'error' => 'workspace_id обязателен для сохранения лимитов',
                'status' => 422,
            ];
        }

        try {
            $response = $this->withServiceTokenRetry(fn (string $token) => Http::timeout(8)
                ->withToken($token)
                ->acceptJson()
                ->post("{$this->baseUrl}/workspaces/{$workspaceId}/limits-external", $payload));

            if ($response === null) {
                return [
                    'success' => false,
                    'error' => 'Не удалось получить service account token Sellico API',
                    'status' => 401,
                ];
            }

            if ($response->successful()) {
                return [
                    'success' => true,
                    'limits' => $response->json(),
                    'status' => $response->status(),
                ];
            }

            Log::warning('Sellico storeWorkspaceLimitExternal failed', [
                'workspace_id' => $workspaceId,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ]);

            return [
                'success' => false,
                'error' => $response->json('message', "HTTP {$response->status()}"),
                'errors' => $response->json('errors'),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico storeWorkspaceLimitExternal exception', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 502,
            ];
        }
    }

    /**
     * Синхронизировать абсолютное значение внешнего лимита workspace.
     *
     * PUT /workspaces/{workspace}/limits-external/sync
     *
     * @param  int                 $workspaceId
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function syncWorkspaceLimitExternal(int $workspaceId, array $payload): array
    {
        if ($workspaceId <= 0) {
            return [
                'success' => false,
                'error' => 'workspace_id обязателен для синхронизации лимита',
                'status' => 422,
            ];
        }

        try {
            $externalPayload = [
                'type' => $payload['type'] ?? null,
                'value' => $payload['current_value'] ?? $payload['value'] ?? null,
            ];

            $response = $this->withServiceTokenRetry(fn (string $token) => Http::timeout(8)
                ->withToken($token)
                ->acceptJson()
                ->put("{$this->baseUrl}/workspaces/{$workspaceId}/limits-external/sync", $externalPayload));

            if ($response === null) {
                return [
                    'success' => false,
                    'error' => 'Не удалось получить service account token Sellico API',
                    'status' => 401,
                ];
            }

            if ($response->successful()) {
                return [
                    'success' => true,
                    'limits' => $response->json(),
                    'status' => $response->status(),
                ];
            }

            Log::warning('Sellico syncWorkspaceLimitExternal failed', [
                'workspace_id' => $workspaceId,
                'type' => $externalPayload['type'] ?? null,
                'value' => $externalPayload['value'] ?? null,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ]);

            return [
                'success' => false,
                'error' => $response->json('message', "HTTP {$response->status()}"),
                'errors' => $response->json('errors'),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Sellico syncWorkspaceLimitExternal exception', [
                'workspace_id' => $workspaceId,
                'type' => $payload['type'] ?? null,
                'value' => $payload['current_value'] ?? $payload['value'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 502,
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
