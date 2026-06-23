<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Uzum\IngestExtensionRequest;
use App\Models\Integration;
use App\Models\UzumExtensionCommand;
use App\Models\UzumExtensionSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Приём данных из браузерного расширения Sellico для кабинета Uzum (ТЗ §17.1).
 *
 * Доступ закрыт middleware 'integration.access' (EnsureIntegrationAccess):
 * интеграция проверяется на принадлежность workspace до входа в метод —
 * IDOR-защита бесплатно (ср. memory autosupply_idor_bola, паттерн роута sync).
 *
 * MVP read-only: только сохраняем снапшот, никаких действий в кабинете Uzum.
 */
class UzumExtensionController extends Controller
{
    /**
     * GET /integrations/uzum/extension/status (ТЗ §17.2).
     * Возвращает статус подключения для попапа расширения.
     */
    public function status(Request $request): JsonResponse
    {
        $workspace = (int) (
            $request->header('X-Sellico-Workspace')
            ?? $request->header('X-Workspace-Id')
            ?? $request->input('workspace')
            ?? 0
        );

        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'workspace_id обязателен'], 422);
        }

        $integrations = Integration::query()
            ->forWorkspace($workspace)
            ->where('marketplace', 'uzum')
            ->where('is_active', true)
            ->get();

        $lastSnapshot = $integrations->isNotEmpty()
            ? UzumExtensionSnapshot::query()
                ->whereIn('integration_id', $integrations->pluck('id'))
                ->latest('collected_at')
                ->first()
            : null;

        return response()->json([
            'connected' => $integrations->isNotEmpty(),
            'userId' => (string) ($request->user()?->id ?? ''),
            'shopId' => $integrations->first()?->settings['uzum_shop_id'] ?? null,
            'lastSyncAt' => $lastSnapshot?->collected_at?->toIso8601String(),
            'availableModules' => ['products', 'moderation'],
        ]);
    }

    public function ingest(IngestExtensionRequest $request, int $id): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('authorized_integration');

        if ($integration->marketplace !== 'uzum') {
            return response()->json([
                'success' => false,
                'message' => 'Интеграция не принадлежит маркетплейсу Uzum',
            ], 404);
        }

        $data = $request->validated();
        [$accepted, $rejected, $errors] = self::partitionItems($data['items']);

        $status = $rejected === 0 ? 'ok' : ($accepted === 0 ? 'error' : 'partial');

        UzumExtensionSnapshot::create([
            'integration_id' => $integration->id,
            'shop_id' => $data['shop_id'] ?? ($integration->settings['uzum_shop_id'] ?? null),
            'payload_type' => $data['payload_type'],
            'raw_payload' => $data['items'],
            'items_count' => count($data['items']),
            'accepted_count' => $accepted,
            'rejected_count' => $rejected,
            'status' => $status,
            'extension_version' => $data['extension']['version'] ?? null,
            'extractor_version' => $data['extractor_version'] ?? null,
            'collected_at' => $data['collected_at'] ?? now(),
        ]);

        $integration->updateSyncStatus($status === 'error' ? 'failed' : 'completed');

        return response()->json([
            'success' => $accepted > 0,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'errors' => $errors,
        ]);
    }

    /**
     * Мост §17: расширение опрашивает pending-команды для интеграции.
     * GET integrations/{id}/uzum/extension/commands
     */
    public function commands(Request $request, int $id): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('authorized_integration');

        $commands = UzumExtensionCommand::query()
            ->where('integration_id', $integration->id)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(20)
            ->get(['id', 'method', 'path', 'body']);

        return response()->json(['data' => $commands]);
    }

    /**
     * Мост §17: Sellico ставит команду «дёрни эндпоинт кабинета Uzum».
     * POST integrations/{id}/uzum/extension/commands  body: {method?, path, body?}
     */
    public function enqueueCommand(Request $request, int $id): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('authorized_integration');

        $data = $request->validate([
            'method' => 'nullable|in:GET',           // read-only MVP (§24)
            'path' => 'required|string|max:2048',
            'body' => 'nullable|array',
        ]);

        // Защита: только api-seller.uzum.uz путь (SSRF-через-расширение закрыт и тут, и в расширении).
        if (! str_starts_with($data['path'], '/api/')) {
            return response()->json(['success' => false, 'message' => 'path должен начинаться с /api/'], 422);
        }

        $command = UzumExtensionCommand::create([
            'integration_id' => $integration->id,
            'method' => $data['method'] ?? 'GET',
            'path' => $data['path'],
            'body' => $data['body'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json(['id' => $command->id, 'status' => 'pending']);
    }

    /**
     * Мост §17: расширение возвращает результат выполнения команды.
     * POST integrations/{id}/uzum/extension/commands/{commandId}/result
     */
    public function commandResult(Request $request, int $id, string $commandId): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('authorized_integration');

        $command = UzumExtensionCommand::query()
            ->where('integration_id', $integration->id) // BOLA-защита: команда принадлежит интеграции
            ->where('id', $commandId)
            ->first();

        if (! $command) {
            return response()->json(['success' => false, 'message' => 'Команда не найдена'], 404);
        }

        $data = $request->validate([
            'ok' => 'required|boolean',
            'status' => 'nullable|integer',
            'data' => 'nullable',
            'error' => 'nullable|string',
        ]);

        $command->update([
            'status' => $data['ok'] ? 'done' : 'error',
            'http_status' => $data['status'] ?? null,
            'response' => $data['ok'] ? ['data' => $data['data'] ?? null] : null,
            'error' => $data['ok'] ? null : ($data['error'] ?? 'unknown'),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Делит items на принятые/отклонённые. Принимаем непустой объект (ассоц. массив);
     * отклоняем скаляры и пустые — на MVP это вся валидация уровня item.
     *
     * @param  array<int,mixed>  $items
     * @return array{0:int,1:int,2:array<int,array{index:int,code:string,message:string}>}
     */
    public static function partitionItems(array $items): array
    {
        $accepted = 0;
        $rejected = 0;
        $errors = [];

        foreach ($items as $index => $item) {
            if (is_array($item) && $item !== []) {
                $accepted++;
                continue;
            }

            $rejected++;
            $errors[] = [
                'index' => $index,
                'code' => 'invalid_item',
                'message' => 'Элемент должен быть непустым объектом',
            ];
        }

        return [$accepted, $rejected, $errors];
    }
}
