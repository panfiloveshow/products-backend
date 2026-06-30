<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Uzum\IngestExtensionRequest;
use App\Models\Integration;
use App\Models\Product;
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
    private const MAX_ITEMS_PER_INGEST = 1000;

    private const COMMAND_TTL_MINUTES = 10;

    private const MAX_COMMAND_RESPONSE_BYTES = 1_000_000;

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
        if (count($data['items']) > self::MAX_ITEMS_PER_INGEST) {
            return response()->json([
                'success' => false,
                'message' => 'Слишком много items в одном запросе',
                'limit' => self::MAX_ITEMS_PER_INGEST,
            ], 422);
        }

        [$accepted, $rejected, $errors] = self::partitionItems($data['payload_type'], $data['items']);

        $status = $rejected === 0 ? 'ok' : ($accepted === 0 ? 'error' : 'partial');
        $payloadHash = $data['payload_hash'] ?? self::payloadHash(
            $data['payload_type'],
            $data['shop_id'] ?? ($integration->settings['uzum_shop_id'] ?? null),
            $data['items']
        );

        $duplicate = UzumExtensionSnapshot::query()
            ->where('integration_id', $integration->id)
            ->where('payload_type', $data['payload_type'])
            ->where('payload_hash', $payloadHash)
            ->first();

        if ($duplicate) {
            return response()->json([
                'success' => $duplicate->accepted_count > 0,
                'accepted' => $duplicate->accepted_count,
                'rejected' => $duplicate->rejected_count,
                'errors' => $errors,
                'duplicate' => true,
            ]);
        }

        UzumExtensionSnapshot::create([
            'integration_id' => $integration->id,
            'shop_id' => $data['shop_id'] ?? ($integration->settings['uzum_shop_id'] ?? null),
            'payload_type' => $data['payload_type'],
            'payload_hash' => $payloadHash,
            'raw_payload' => $data['items'],
            'items_count' => count($data['items']),
            'accepted_count' => $accepted,
            'rejected_count' => $rejected,
            'status' => $status,
            'extension_version' => $data['extension']['version'] ?? null,
            'extractor_version' => $data['extractor_version'] ?? null,
            'collected_at' => $data['collected_at'] ?? now(),
        ]);

        // Габариты из кабинета (skuDimension) → в products.uzum_data['dimensions'],
        // откуда юнит-экономика берёт объём (UnitEconomicsCacheService строки 686-693, 1045).
        if ($data['payload_type'] === 'dimensions') {
            $accepted = $this->applyDimensions($integration->id, $data['items']);
            $rejected = count($data['items']) - $accepted;
        } elseif ($data['payload_type'] === 'products') {
            $accepted = $this->applyProducts($integration->id, $data['shop_id'] ?? null, $data['items']);
            $rejected = count($data['items']) - $accepted;
        }

        $integration->updateSyncStatus($status === 'error' ? 'failed' : 'completed');

        return response()->json([
            'success' => $accepted > 0,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'errors' => $errors,
        ]);
    }

    /**
     * Мерджит габариты в products.uzum_data['dimensions'] по product_id.
     * Формат item: {product_id, sku_id?, length, width, height, weight, dimensional_group?}.
     * Маппинг под существующий расчёт: length→depth (см. UnitEconomicsCacheService::686).
     *
     * @param  array<int,mixed>  $items
     */
    private function applyDimensions(int $integrationId, array $items): int
    {
        $applied = 0;

        foreach ($items as $item) {
            if (! is_array($item) || (empty($item['sku_id']) && empty($item['product_id']))) {
                continue;
            }

            // Матчим по sku_id (точно, per-SKU), фолбэк — product_id.
            $query = Product::query()->where('integration_id', $integrationId);
            if (! empty($item['sku_id'])) {
                $query->whereJsonContains('uzum_data->sku_id', (int) $item['sku_id']);
            } else {
                $query->whereJsonContains('uzum_data->product_id', (int) $item['product_id']);
            }
            $product = $query->first();

            if (! $product) {
                continue;
            }

            $uzum = is_array($product->uzum_data) ? $product->uzum_data : [];
            $uzum['dimensions'] = array_filter([
                'depth' => isset($item['length']) ? (float) $item['length'] : null,  // длина → depth
                'width' => isset($item['width']) ? (float) $item['width'] : null,
                'height' => isset($item['height']) ? (float) $item['height'] : null,
                'weight' => isset($item['weight']) ? (float) $item['weight'] : null,
            ], fn ($v) => $v !== null);

            if (! empty($item['dimensional_group'])) {
                $uzum['dimensional_group'] = $item['dimensional_group'];
            }

            $product->forceFill(['uzum_data' => $uzum])->saveQuietly();
            $applied++;
        }

        return $applied;
    }

    /**
     * Мягкий merge карточек из расширения в products. Официальный OpenAPI остаётся
     * главным источником per-SKU строк; extension дополняет то, что видит кабинет.
     *
     * @param  array<int,mixed>  $items
     */
    private function applyProducts(int $integrationId, ?string $shopId, array $items): int
    {
        $applied = 0;

        foreach ($items as $item) {
            if (! is_array($item) || empty($item['productId']) || empty($item['title'])) {
                continue;
            }

            $productId = (string) $item['productId'];
            $query = Product::query()
                ->where('integration_id', $integrationId)
                ->where('marketplace', 'uzum')
                ->whereJsonContains('uzum_data->product_id', is_numeric($productId) ? (int) $productId : $productId);

            $existing = $query->get();
            if ($existing->isEmpty()) {
                $existing = collect([
                    Product::firstOrCreate(
                        [
                            'integration_id' => $integrationId,
                            'marketplace' => 'uzum',
                            'sku' => 'uzum-product-'.$productId,
                        ],
                        [
                            'name' => (string) $item['title'],
                            'marketplace_id' => $productId,
                            'price' => isset($item['price']) ? (float) $item['price'] : null,
                            'old_price' => isset($item['oldPrice']) ? (float) $item['oldPrice'] : null,
                            'stock' => isset($item['stock']) ? (int) $item['stock'] : 0,
                            'category' => $item['category'] ?? null,
                            'uzum_data' => [],
                        ]
                    ),
                ]);
            }

            foreach ($existing as $product) {
                $uzum = is_array($product->uzum_data) ? $product->uzum_data : [];
                $uzum['shop_id'] = $shopId ?? ($uzum['shop_id'] ?? null);
                $uzum['product_id'] = is_numeric($productId) ? (int) $productId : $productId;
                $uzum['extension_snapshot'] = array_filter([
                    'status' => $item['status'] ?? null,
                    'moderation' => $item['moderation'] ?? null,
                    'source' => $item['source'] ?? null,
                ], fn ($v) => $v !== null);

                $update = [
                    'name' => (string) $item['title'],
                    'category' => $item['category'] ?? $product->category,
                    'price' => isset($item['price']) ? (float) $item['price'] : $product->price,
                    'old_price' => isset($item['oldPrice']) ? (float) $item['oldPrice'] : $product->old_price,
                    'stock' => isset($item['stock']) ? (int) $item['stock'] : $product->stock,
                    'uzum_data' => $uzum,
                ];

                if (! empty($item['sku']) && str_starts_with((string) $product->sku, 'uzum-product-')) {
                    $update['vendor_code'] = (string) $item['sku'];
                }

                $product->forceFill($update)->saveQuietly();
                $applied++;
            }
        }

        return $applied;
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
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at')
            ->limit(20)
            ->get(['id', 'command_key', 'method', 'path', 'body']);

        return response()->json(['data' => $commands]);
    }

    /**
     * Мост §17: Sellico ставит продуктовую команду «дёрни эндпоинт кабинета Uzum».
     * POST integrations/{id}/uzum/extension/commands  body: {command_key, params?}
     */
    public function enqueueCommand(Request $request, int $id): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('authorized_integration');

        $data = $request->validate([
            'command_key' => 'nullable|string|max:80',
            'params' => 'nullable|array',
            'method' => 'nullable|in:GET',
            'path' => 'nullable|string|max:2048',
            'body' => 'nullable|array',
        ]);

        try {
            $built = $this->buildCommandPath($integration, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $command = UzumExtensionCommand::create([
            'integration_id' => $integration->id,
            'command_key' => $built['command_key'],
            'method' => 'GET',
            'path' => $built['path'],
            'body' => $built['params'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(self::COMMAND_TTL_MINUTES),
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

        $response = $data['ok'] ? ['data' => $data['data'] ?? null] : null;
        $encodedResponse = $response !== null
            ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        if ($encodedResponse === false) {
            return response()->json(['success' => false, 'message' => 'Ответ команды не сериализуется'], 422);
        }
        if ($encodedResponse !== null && strlen($encodedResponse) > self::MAX_COMMAND_RESPONSE_BYTES) {
            return response()->json([
                'success' => false,
                'message' => 'Ответ команды слишком большой',
                'limit' => self::MAX_COMMAND_RESPONSE_BYTES,
            ], 413);
        }

        $command->update([
            'status' => $data['ok'] ? 'done' : 'error',
            'http_status' => $data['status'] ?? null,
            'response' => $response,
            'error' => $data['ok'] ? null : ($data['error'] ?? 'unknown'),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Делит items на принятые/отклонённые по минимальной схеме payload_type.
     *
     * @param  array<int,mixed>  $items
     * @return array{0:int,1:int,2:array<int,array{index:int,code:string,message:string}>}
     */
    public static function partitionItems(string $payloadType, array $items): array
    {
        $accepted = 0;
        $rejected = 0;
        $errors = [];

        foreach ($items as $index => $item) {
            $error = self::validateItem($payloadType, $item);
            if ($error === null) {
                $accepted++;

                continue;
            }

            $rejected++;
            $errors[] = [
                'index' => $index,
                'code' => $error['code'],
                'message' => $error['message'],
            ];
        }

        return [$accepted, $rejected, $errors];
    }

    /**
     * @return array{code:string,message:string}|null
     */
    private static function validateItem(string $payloadType, mixed $item): ?array
    {
        if (! is_array($item) || $item === []) {
            return ['code' => 'invalid_item', 'message' => 'Элемент должен быть непустым объектом'];
        }

        return match ($payloadType) {
            'products' => empty($item['productId']) || empty($item['title'])
                ? ['code' => 'invalid_product', 'message' => 'Товар должен содержать productId и title']
                : null,
            'dimensions' => (empty($item['sku_id']) && empty($item['product_id']))
                ? ['code' => 'invalid_dimensions', 'message' => 'Габариты должны содержать sku_id или product_id']
                : null,
            default => null,
        };
    }

    private static function payloadHash(string $payloadType, mixed $shopId, array $items): string
    {
        return hash('sha256', json_encode([
            'payload_type' => $payloadType,
            'shop_id' => $shopId,
            'items' => self::normalizeForHash($items),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_key_exists('collectedAt', $value)) {
            unset($value['collectedAt']);
        }
        if (isset($value['source']) && is_array($value['source'])) {
            unset($value['source']['collectedAt']);
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::normalizeForHash($child);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array{command_key:string,path:string,params:array<string,mixed>}
     */
    private function buildCommandPath(Integration $integration, array $data): array
    {
        $params = $data['params'] ?? [];
        $shopId = (string) ($params['shop_id'] ?? $integration->settings['uzum_shop_id'] ?? '');
        $commandKey = (string) ($data['command_key'] ?? '');

        if ($commandKey === '' && ! empty($data['path']) && config('services.uzum.extension_allow_raw_commands', false)) {
            if (($data['method'] ?? 'GET') !== 'GET' || ! str_starts_with($data['path'], '/api/')) {
                throw new \InvalidArgumentException('raw path должен быть GET и начинаться с /api/');
            }

            return [
                'command_key' => 'raw.get',
                'path' => $data['path'],
                'params' => ['path' => $data['path']],
            ];
        }

        if ($shopId === '') {
            throw new \InvalidArgumentException('shop_id обязателен для команды Uzum');
        }

        return match ($commandKey) {
            'products.list' => [
                'command_key' => $commandKey,
                'path' => sprintf(
                    '/api/seller/shop/%s/product/getProducts?page=%d&size=%d&filter=%s',
                    rawurlencode($shopId),
                    max(0, (int) ($params['page'] ?? 0)),
                    min(100, max(1, (int) ($params['size'] ?? 100))),
                    rawurlencode((string) ($params['filter'] ?? 'ALL'))
                ),
                'params' => $params,
            ],
            'product.detail' => [
                'command_key' => $commandKey,
                'path' => sprintf(
                    '/api/seller/shop/%s/product?productId=%s',
                    rawurlencode($shopId),
                    rawurlencode((string) ($params['product_id'] ?? throw new \InvalidArgumentException('product_id обязателен')))
                ),
                'params' => $params,
            ],
            'products.stats' => [
                'command_key' => $commandKey,
                'path' => sprintf('/api/seller/shop/%s/product/products-statistic', rawurlencode($shopId)),
                'params' => $params,
            ],
            default => throw new \InvalidArgumentException('Неизвестная команда Uzum: '.$commandKey),
        };
    }
}
