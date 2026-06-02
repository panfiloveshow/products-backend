<?php

namespace App\Domains\Locality\Recommendation;

use App\Domains\Ozon\Api\FboSupplyOrdersApi;
use App\Domains\Ozon\Api\OzonClient;
use App\Models\Integration;
use App\Models\LocalityRecommendation;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Собирает payload и вызывает /v1/draft/create для LocalityRecommendation.
 * Polling getDraftCreateStatus с backoff (до 5 попыток × 2 сек).
 */
class LocalityDraftApplier
{
    public function buildPayload(LocalityRecommendation $rec): array
    {
        $product = Product::query()
            ->where('integration_id', $rec->integration_id)
            ->where('sku', $rec->sku)
            ->first();

        $ozonSku = $this->resolveOzonSku($product);

        return [
            'items' => [[
                'sku' => $ozonSku,
                'quantity' => (int) $rec->recommended_qty_units,
            ]],
            'cluster_ids' => $rec->target_cluster_id !== null ? [(int) $rec->target_cluster_id] : [],
            'type' => 'CREATE_TYPE_DIRECT',
        ];
    }

    /** @return array{success:bool, draft_id:?string, error:?string} */
    public function apply(LocalityRecommendation $rec): array
    {
        $integration = Integration::findOrFail($rec->integration_id);
        $api = new FboSupplyOrdersApi(OzonClient::fromIntegration($integration));

        $payload = $this->buildPayload($rec);

        $result = $api->createDirectDraft(
            $payload['items'],
            $payload['cluster_ids'],
            $payload['type'],
        );

        if (! ($result['success'] ?? false)) {
            Log::channel('locality')->warning('LocalityDraftApplier createDirectDraft failed', [
                'recommendation_id' => $rec->id,
                'error' => $result['error'] ?? null,
            ]);
            return ['success' => false, 'draft_id' => null, 'error' => $result['error'] ?? 'draft_create_failed'];
        }

        $operationId = (string) $result['operation_id'];
        $draftId = $this->pollDraftId($api, $operationId);

        if ($draftId === null) {
            return ['success' => false, 'draft_id' => null, 'error' => 'draft_status_timeout'];
        }

        $rec->fill([
            'state' => LocalityRecommendation::STATE_APPLIED,
            'applied_at' => now(),
            'linked_draft_id' => $draftId,
        ])->save();

        return ['success' => true, 'draft_id' => $draftId, 'error' => null];
    }

    /**
     * Batch-версия: создаёт один Ozon FBO-draft для произвольного набора позиций в целевой кластер.
     * Используется AutoSupplyPlanController::createClusterDrafts, когда план уже split по кластерам.
     *
     * @param list<array{sku:int, quantity:int}> $items уже с числовым ozon SKU (не offer_id)
     * @param array<string, mixed> $options
     * @return array{success:bool, draft_id:?string, error:?string, supply_method?:string}
     */
    public function applyBatch(Integration $integration, array $items, int $clusterId, array $options = []): array
    {
        if (empty($items)) {
            return ['success' => false, 'draft_id' => null, 'error' => 'empty_items'];
        }

        $api = new FboSupplyOrdersApi(OzonClient::fromIntegration($integration));
        $supplyMethod = (($options['supply_method'] ?? null) === 'crossdock') ? 'crossdock' : 'direct';
        $draftType = $supplyMethod === 'crossdock' ? 'CREATE_TYPE_CROSSDOCK' : 'CREATE_TYPE_DIRECT';
        $dropOffPointWarehouseId = isset($options['drop_off_point_warehouse_id'])
            ? (int) $options['drop_off_point_warehouse_id']
            : null;

        $result = $api->createDirectDraft(
            $items,
            [$clusterId],
            $draftType,
            $dropOffPointWarehouseId,
        );

        if (! ($result['success'] ?? false)) {
            Log::channel('locality')->warning('LocalityDraftApplier applyBatch createDirectDraft failed', [
                'integration_id' => $integration->id,
                'cluster_id' => $clusterId,
                'supply_method' => $supplyMethod,
                'drop_off_point_warehouse_id' => $dropOffPointWarehouseId,
                'items_count' => count($items),
                'error' => $result['error'] ?? null,
            ]);
            return ['success' => false, 'draft_id' => null, 'error' => $result['error'] ?? 'draft_create_failed'];
        }

        $operationId = (string) $result['operation_id'];
        $draftId = $this->pollDraftId($api, $operationId);
        if ($draftId === null) {
            return ['success' => false, 'draft_id' => null, 'error' => 'draft_status_timeout'];
        }

        return ['success' => true, 'draft_id' => $draftId, 'error' => null, 'supply_method' => $supplyMethod];
    }

    private function pollDraftId(FboSupplyOrdersApi $api, string $operationId): ?string
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $status = $api->getDraftCreateStatus($operationId);
            $draftId = $status['draft_id'] ?? ($status['result']['draft_id'] ?? null);
            if ($draftId !== null) {
                return (string) $draftId;
            }
            sleep(2);
        }
        return null;
    }

    private function resolveOzonSku(?Product $product): int
    {
        if ($product === null) {
            return 0;
        }
        $ozonData = is_array($product->ozon_data ?? null) ? $product->ozon_data : [];
        $sku = $ozonData['sku'] ?? ($ozonData['product_id'] ?? null);
        return (int) ($sku ?? 0);
    }
}
