<?php

namespace App\Domains\Locality\Jobs;

use App\Domains\Ozon\Api\FboSupplyOrdersApi;
use App\Domains\Ozon\Api\OzonClient;
use App\Models\Integration;
use App\Models\LocalityRecommendation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Обновляет state рекомендаций, привязанных к Ozon supply order:
 * ACCEPTED/COMPLETED → применено (остаётся applied)
 * CANCELLED → expired
 */
class SyncLinkedSupplyOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function handle(): void
    {
        $query = LocalityRecommendation::query()
            ->whereNotNull('linked_supply_order_id')
            ->where('state', LocalityRecommendation::STATE_APPLIED);

        $byIntegration = $query->get()->groupBy('integration_id');

        foreach ($byIntegration as $integrationId => $recs) {
            try {
                $integration = Integration::find((int) $integrationId);
                if ($integration === null) {
                    continue;
                }
                $api = new FboSupplyOrdersApi(OzonClient::fromIntegration($integration));

                $orderIds = $recs->pluck('linked_supply_order_id')->filter()->unique()->values()->all();
                if (empty($orderIds)) {
                    continue;
                }

                $response = $api->get($orderIds);
                $orders = $response['orders'] ?? ($response['result']['orders'] ?? []);

                $statusByOrder = [];
                foreach ($orders as $o) {
                    $statusByOrder[(string) ($o['order_id'] ?? $o['id'] ?? '')] = (string) ($o['state'] ?? $o['status'] ?? '');
                }

                foreach ($recs as $rec) {
                    $status = $statusByOrder[(string) $rec->linked_supply_order_id] ?? null;
                    if ($status === null) {
                        continue;
                    }

                    if (in_array($status, ['CANCELLED', 'CANCELED', 'cancelled'], true)) {
                        $rec->fill(['state' => LocalityRecommendation::STATE_EXPIRED])->save();
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('locality')->error('SyncLinkedSupplyOrdersJob failed', [
                    'integration_id' => $integrationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
