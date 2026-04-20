<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class IntegrationAccessService
{
    public function __construct(
        private SellicoApiService $sellicoApi
    ) {}

    /**
     * ID воркспейса из payload интеграции Sellico (snake_case и camelCase).
     */
    public static function extractRemoteWorkspaceIdFromSellicoPayload(array $integrationData): int
    {
        foreach (['work_space_id', 'workspace_id', 'workSpaceId', 'workspaceId', 'work_spaceId'] as $key) {
            if (! array_key_exists($key, $integrationData)) {
                continue;
            }
            $value = $integrationData[$key];
            if ($value === null || $value === '') {
                continue;
            }

            return (int) $value;
        }

        return 0;
    }

    /**
     * Гарантирует, что интеграция доступна локально и принадлежит текущему workspace.
     *
     * @return array{success: bool, status?: int, message?: string, integration?: Integration}
     */
    public function ensureAccessibleIntegration(
        Request $request,
        int $integrationId,
        ?string $expectedMarketplace = null
    ): array {
        $workspaceId = $this->extractWorkspaceId($request);
        $expectedMarketplace = $expectedMarketplace
            ? $this->normalizeMarketplace($expectedMarketplace)
            : null;

        $integration = Integration::find($integrationId);

        if ($integration) {
            $workspaceCheck = $this->validateWorkspaceAccess($integration, $workspaceId);
            if ($workspaceCheck !== null) {
                $refreshedIntegration = $this->refreshIntegrationFromRemote($request, $integrationId, $expectedMarketplace, $workspaceId);
                if ($refreshedIntegration !== null) {
                    $integration = $refreshedIntegration;
                    $workspaceCheck = $this->validateWorkspaceAccess($integration, $workspaceId);
                }

                if ($workspaceCheck !== null) {
                    return $workspaceCheck;
                }
            }

            if ($expectedMarketplace && ! empty($integration->marketplace)) {
                $actualMarketplace = $this->normalizeMarketplace((string) $integration->marketplace);
                if ($actualMarketplace !== $expectedMarketplace) {
                    return [
                        'success' => false,
                        'status' => 404,
                        'message' => 'Интеграция не принадлежит выбранному маркетплейсу',
                    ];
                }
            }

            return [
                'success' => true,
                'integration' => $integration->fresh(),
            ];
        }

        $token = $this->extractToken($request);
        if ($token) {
            $this->sellicoApi->setAccessToken($token);
        }

        $result = $this->sellicoApi->getIntegrationById($integrationId, $workspaceId);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'status' => 404,
                'message' => $result['error'] ?? 'Интеграция не найдена',
            ];
        }

        $integrationData = $result['integration'] ?? [];
        $remoteWorkspaceId = (int) ($integrationData['work_space_id'] ?? $integrationData['workspace_id'] ?? 0);

        if ($workspaceId && $remoteWorkspaceId && $remoteWorkspaceId !== $workspaceId) {
            return [
                'success' => false,
                'status' => 403,
                'message' => 'Интеграция не принадлежит текущему workspace',
            ];
        }

        $marketplace = $this->normalizeMarketplace((string) ($integrationData['type'] ?? $expectedMarketplace ?? ''));
        if ($expectedMarketplace && $marketplace && $marketplace !== $expectedMarketplace) {
            return [
                'success' => false,
                'status' => 404,
                'message' => 'Интеграция не принадлежит выбранному маркетплейсу',
            ];
        }

        $credentials = $result['credentials'] ?? ($integrationData['credentials'] ?? []);
        if (! is_array($credentials)) {
            $credentials = [];
        }

        $integration = Integration::find($integrationId) ?? new Integration;
        $integration->fill([
            'id' => $integrationId,
            'work_space_id' => $remoteWorkspaceId ?: $workspaceId,
            'name' => $integrationData['name'] ?? ($marketplace ? "{$marketplace} {$integrationId}" : "integration {$integrationId}"),
            'marketplace' => $marketplace ?: $expectedMarketplace,
            'credentials' => $this->sanitizeStoredCredentials($credentials),
            'is_active' => (bool) ($integrationData['is_active'] ?? true),
            'is_premium' => (bool) ($integrationData['is_premium'] ?? false),
            'premium_checked_at' => ! empty($integrationData['premium_checked_at']) ? $integrationData['premium_checked_at'] : null,
            'manual_redemption_rate' => $integrationData['manual_redemption_rate'] ?? null,
        ]);

        if (! $integration->exists) {
            $integration->auto_sync_enabled = true;
            $integration->sync_interval_hours = 6;
        }

        try {
            $integration->save();
        } catch (QueryException $exception) {
            // Параллельный запрос мог успеть создать интеграцию между find() и save().
            if ($this->isDuplicateIntegrationKey($exception, $integrationId)) {
                $integration = Integration::find($integrationId);
                if ($integration) {
                    $workspaceCheck = $this->validateWorkspaceAccess($integration, $workspaceId);
                    if ($workspaceCheck !== null) {
                        return $workspaceCheck;
                    }

                    return [
                        'success' => true,
                        'integration' => $integration->fresh(),
                    ];
                }
            }

            throw $exception;
        }

        return [
            'success' => true,
            'integration' => $integration->fresh(),
        ];
    }

    /**
     * @return array{success: false, status: int, message: string}|null
     */
    private function validateWorkspaceAccess(Integration $integration, ?int $workspaceId): ?array
    {
        if (! $workspaceId) {
            return null;
        }

        if ($integration->work_space_id !== null && (int) $integration->work_space_id !== $workspaceId) {
            return [
                'success' => false,
                'status' => 403,
                'message' => 'Интеграция не принадлежит текущему workspace',
            ];
        }

        if ($integration->work_space_id === null) {
            $integration->update(['work_space_id' => $workspaceId]);
        }

        return null;
    }

    private function extractWorkspaceId(Request $request): ?int
    {
        $workspaceId = $request->header('X-Sellico-Workspace')
            ?? $request->header('X-Workspace-Id')
            ?? $request->input('workspace');

        return $workspaceId ? (int) $workspaceId : null;
    }

    private function extractToken(Request $request): ?string
    {
        return $request->bearerToken()
            ?? $request->header('X-Sellico-Token')
            ?? $request->header('X-Token');
    }

    private function normalizeMarketplace(string $marketplace): string
    {
        return match (strtolower($marketplace)) {
            'yandexmarket', 'yandex_market', 'yandex' => 'yandex_market',
            default => strtolower($marketplace),
        };
    }

    private function sanitizeStoredCredentials(array $credentials): array
    {
        return array_filter(
            $credentials,
            static fn ($value, $key) => $value !== null && $value !== '' && ! str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function refreshIntegrationFromRemote(
        Request $request,
        int $integrationId,
        ?string $expectedMarketplace,
        ?int $workspaceId
    ): ?Integration {
        $token = $this->extractToken($request);
        if (! $token) {
            return null;
        }

        $this->sellicoApi->setAccessToken($token);
        $result = $this->sellicoApi->getIntegrationById($integrationId, $workspaceId);
        if (! ($result['success'] ?? false)) {
            return null;
        }

        $integrationData = $result['integration'] ?? [];
        $remoteWorkspaceId = self::extractRemoteWorkspaceIdFromSellicoPayload($integrationData);
        $marketplace = $this->normalizeMarketplace((string) ($integrationData['type'] ?? $expectedMarketplace ?? ''));

        if ($workspaceId && $remoteWorkspaceId && $remoteWorkspaceId !== $workspaceId) {
            return null;
        }

        if ($expectedMarketplace && $marketplace && $marketplace !== $expectedMarketplace) {
            return null;
        }

        $credentials = $result['credentials'] ?? ($integrationData['credentials'] ?? []);
        if (! is_array($credentials)) {
            $credentials = [];
        }

        $integration = Integration::find($integrationId) ?? new Integration;
        $integration->fill([
            'id' => $integrationId,
            'work_space_id' => $remoteWorkspaceId ?: $workspaceId,
            'name' => $integrationData['name'] ?? $integration->name ?? ($marketplace ? "{$marketplace} {$integrationId}" : "integration {$integrationId}"),
            'marketplace' => $marketplace ?: $expectedMarketplace ?: $integration->marketplace,
            'credentials' => $this->sanitizeStoredCredentials($credentials),
            'is_active' => (bool) ($integrationData['is_active'] ?? $integration->is_active ?? true),
            'is_premium' => (bool) ($integrationData['is_premium'] ?? $integration->is_premium ?? false),
            'premium_checked_at' => ! empty($integrationData['premium_checked_at']) ? $integrationData['premium_checked_at'] : $integration->premium_checked_at,
            'manual_redemption_rate' => $integrationData['manual_redemption_rate'] ?? $integration->manual_redemption_rate,
        ]);

        if (! $integration->exists) {
            $integration->auto_sync_enabled = true;
            $integration->sync_interval_hours = 6;
        }

        $integration->save();

        return $integration->fresh();
    }

    private function isDuplicateIntegrationKey(QueryException $exception, int $integrationId): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'integrations_pkey')
            && str_contains($message, "(id)=({$integrationId})");
    }
}
