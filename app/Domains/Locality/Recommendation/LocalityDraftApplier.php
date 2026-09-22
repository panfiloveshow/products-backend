<?php

namespace App\Domains\Locality\Recommendation;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\SuppliesApi;
use App\Models\Integration;
use App\Models\LocalityRecommendation;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Собирает payload и создаёт черновик прямой поставки для LocalityRecommendation
 * через SuppliesApi::createDirectDraft (/v1/draft/direct/create).
 * Ждёт расчёт черновика: poll /v2/draft/create/info (до 5 попыток × 2 сек).
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
        $api = new SuppliesApi(OzonClient::fromIntegration($integration));

        $payload = $this->buildPayload($rec);

        try {
            [$draftId, $error] = $this->waitForDraft($api, $api->createDirectDraft([
                'cluster_id' => $payload['cluster_ids'][0] ?? 0,
                'items' => $payload['items'],
            ]));
        } catch (\RuntimeException $e) {
            [$draftId, $error] = [null, $e->getMessage()];
        }

        if ($draftId === null) {
            Log::channel('locality')->warning('LocalityDraftApplier createDirectDraft failed', [
                'recommendation_id' => $rec->id,
                'error' => $error,
            ]);
            return ['success' => false, 'draft_id' => null, 'error' => $error];
        }

        $rec->fill([
            'state' => LocalityRecommendation::STATE_APPLIED,
            'applied_at' => now(),
            'linked_draft_id' => $draftId,
        ])->save();

        return ['success' => true, 'draft_id' => $draftId, 'error' => null];
    }

    /**
     * Черновик создаётся сразу, склады Ozon считает асинхронно — ждём status=SUCCESS.
     *
     * @return array{0:?string, 1:?string} [draft_id, ошибка]
     */
    private function waitForDraft(SuppliesApi $api, array $result): array
    {
        for ($attempt = 1; ; $attempt++) {
            if (! empty($result['draft_id'])) {
                return [(string) $result['draft_id'], null];
            }
            if (($result['status'] ?? null) === 'failed' || empty($result['pending_draft_id'])) {
                return [null, implode('; ', (array) ($result['errors'] ?? [])) ?: 'draft_create_failed'];
            }
            if ($attempt > 5) {
                return [null, 'draft_status_timeout'];
            }
            sleep(2);
            $result = $api->pollDraftCreation((string) $result['pending_draft_id'], (array) ($result['errors'] ?? []));
        }
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
