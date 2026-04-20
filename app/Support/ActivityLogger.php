<?php

namespace App\Support;

use App\Jobs\SendActivityJob;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Удобная точка входа для отправки активностей в PlaceSales API.
 *
 * Все методы возвращают void и никогда не бросают исключения — сбой логирования
 * активностей не должен ломать бизнес-операции. Все вызовы асинхронны (через очередь).
 *
 * Примеры:
 *   ActivityLogger::forRequest($request, 'integration_created', 'Интеграция создана', meta: [...]);
 *   ActivityLogger::forIntegration($integrationId, 'products_sync_completed', ...);
 *   ActivityLogger::forWorkspace($workspaceId, ...);
 */
class ActivityLogger
{
    /**
     * Отправить активность с явно указанным workspace_id.
     *
     * @param  array<string, mixed>  $meta
     * @param  string|null           $token  Пользовательский токен. Если null — job использует service token.
     */
    public static function forWorkspace(
        int $workspaceId,
        string $action,
        string $title,
        ?string $description = null,
        array $meta = [],
        ?string $token = null,
    ): void {
        if ($workspaceId <= 0) {
            Log::debug('ActivityLogger: workspace_id отсутствует, activity не отправляем', [
                'action' => $action,
            ]);

            return;
        }

        try {
            SendActivityJob::dispatch($workspaceId, $action, $title, $description, $meta, $token)
                ->onQueue('activities');
        } catch (\Throwable $e) {
            Log::warning('ActivityLogger: dispatch failed', [
                'action' => $action,
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Отправить активность, вычислив workspace_id по integration_id
     * (Integration->work_space_id).
     *
     * Используется из фоновых job'ов. ОБЯЗАТЕЛЬНО передавать $userToken —
     * это токен пользователя, инициировавшего синхронизацию (живёт в
     * SyncLog.credentials['_sellico_token']). Без user-токена activity
     * не отправится (service token для activities не используется).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function forIntegration(
        int $integrationId,
        string $action,
        string $title,
        ?string $description = null,
        array $meta = [],
        ?string $userToken = null,
    ): void {
        if (! $userToken) {
            Log::info('ActivityLogger: нет user-токена, activity пропущена', [
                'integration_id' => $integrationId,
                'action' => $action,
            ]);

            return;
        }

        $workspaceId = (int) (Integration::where('id', $integrationId)->value('work_space_id') ?? 0);

        if ($workspaceId <= 0) {
            Log::debug('ActivityLogger: integration без workspace_id, activity пропущена', [
                'integration_id' => $integrationId,
                'action' => $action,
            ]);

            return;
        }

        $meta = array_merge([
            'integration_id' => $integrationId,
        ], $meta);

        self::forWorkspace($workspaceId, $action, $title, $description, $meta, $userToken);
    }

    /**
     * Отправить активность из HTTP-контроллера, подобрав workspace и user-token из request.
     *
     * Порядок источников workspace_id:
     *   1) X-Sellico-Workspace / X-Workspace-Id (заголовок)
     *   2) ?workspace=... (query/body)
     *   3) sellico_user.workspace_id (установлен SellicoAuth middleware)
     *
     * @param  array<string, mixed>  $meta
     */
    public static function forRequest(
        Request $request,
        string $action,
        string $title,
        ?string $description = null,
        array $meta = [],
    ): void {
        $workspaceId = self::extractWorkspaceFromRequest($request);
        $token = $request->bearerToken()
            ?? $request->header('X-Sellico-Token')
            ?? $request->header('X-Token')
            ?? $request->input('sellico_token');

        if ($workspaceId <= 0) {
            Log::debug('ActivityLogger: не удалось определить workspace_id из request', [
                'action' => $action,
            ]);

            return;
        }

        if (! is_string($token) || $token === '') {
            Log::info('ActivityLogger: нет user-токена в request, activity пропущена', [
                'action' => $action,
                'workspace_id' => $workspaceId,
            ]);

            return;
        }

        self::forWorkspace($workspaceId, $action, $title, $description, $meta, $token);
    }

    private static function extractWorkspaceFromRequest(Request $request): int
    {
        $candidates = [
            $request->header('X-Sellico-Workspace'),
            $request->header('X-Workspace-Id'),
            $request->input('workspace'),
            $request->input('workspace_id'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return (int) $candidate;
            }
        }

        $sellicoUser = $request->input('sellico_user');
        if (is_array($sellicoUser) && ! empty($sellicoUser['workspace_id'])) {
            return (int) $sellicoUser['workspace_id'];
        }

        return 0;
    }
}
