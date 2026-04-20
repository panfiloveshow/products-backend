<?php

namespace App\Services\Ozon;

use App\Domains\Ozon\OzonMarketplace;
use App\Models\Integration;
use App\Models\OzonWarehouseCluster;
use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OzonSupplySyncService
{
    public function syncForIntegration(int $integrationId, array $states = []): array
    {
        $integration = Integration::query()->findOrFail($integrationId);
        $ozon = OzonMarketplace::fromIntegration($integration);

        $orderIds = [];
        $lastId = null;

        do {
            $page = $ozon->supplies()->getSupplyOrdersList($states, 100, $lastId);
            $batchIds = array_values(array_filter(array_map('intval', $page['order_ids'] ?? [])));
            $orderIds = array_merge($orderIds, $batchIds);
            $lastId = $page['last_id'] ?? null;
        } while (! empty($lastId));

        $orderIds = array_values(array_unique($orderIds));

        if ($orderIds === []) {
            Log::info('Ozon supplies sync: no supply orders returned', [
                'integration_id' => $integrationId,
            ]);

            return ['total' => 0, 'created' => 0, 'updated' => 0, 'items_synced' => 0];
        }

        $created = 0;
        $updated = 0;
        $itemsSynced = 0;

        foreach (array_chunk($orderIds, 20) as $chunk) {
            $details = $ozon->supplies()->getSupplyOrdersDetails($chunk);
            $orders = $details['orders'] ?? [];

            foreach ($orders as $order) {
                $result = $this->upsertOrder($integration, $ozon, $order);
                $created += $result['created'];
                $updated += $result['updated'];
                $itemsSynced += $result['items_synced'];
            }
        }

        Log::info('Ozon supplies sync completed', [
            'integration_id' => $integrationId,
            'orders_total' => count($orderIds),
            'created' => $created,
            'updated' => $updated,
            'items_synced' => $itemsSynced,
        ]);

        return [
            'total' => count($orderIds),
            'created' => $created,
            'updated' => $updated,
            'items_synced' => $itemsSynced,
        ];
    }

    private function upsertOrder(Integration $integration, OzonMarketplace $ozon, array $order): array
    {
        $orderId = (int) ($order['id'] ?? $order['order_id'] ?? 0);
        if ($orderId <= 0) {
            return ['created' => 0, 'updated' => 0, 'items_synced' => 0];
        }

        $supplies = $order['supplies'] ?? [];
        if (! is_array($supplies) || $supplies === []) {
            $supplies = [['supply_id' => $orderId]];
        }

        $v1Details = $ozon->supplies()->getSupplyOrderDetailsV1((string) $orderId);
        $bundle = $ozon->fboSupplyOrders()->getBundle($orderId);
        $directItems = $ozon->fboSupplyOrders()->getItems($orderId);
        $bundleItemsByBundleId = $this->extractBundleItems($bundle, $supplies);

        $created = 0;
        $updated = 0;
        $itemsSynced = 0;

        foreach ($supplies as $supplyData) {
            $remoteSupplyId = (string) ($supplyData['supply_id'] ?? "order_{$orderId}");
            $bundleId = (string) ($supplyData['bundle_id'] ?? '');
            $clusterName = $this->extractClusterName($supplyData, $order, $v1Details);
            $clusterId = $this->extractClusterId($supplyData, $order, $v1Details);
            $warehouseName = $this->extractWarehouseName($supplyData, $order, $v1Details, $clusterName);
            $warehouseId = $this->extractWarehouseId($supplyData, $order, $v1Details);
            $timeslot = $this->extractTimeslot($supplyData, $v1Details);
            $status = $this->mapOrderStateToSupplyStatus((string) ($order['state'] ?? $order['status'] ?? ''));
            $createdAt = $this->parseDate(
                $order['created_at'] ?? $order['created_date'] ?? $order['creation_date'] ?? $order['created'] ?? null
            );

            $existing = Supply::query()
                ->where('integration_id', $integration->id)
                ->where('ozon_supply_id', $remoteSupplyId)
                ->first();

            $payload = [
                'integration_id' => $integration->id,
                'ozon_supply_id' => $remoteSupplyId,
                'supply_type' => Supply::TYPE_FBO,
                'supply_method' => Supply::METHOD_DIRECT,
                'delivery_scheme' => null,
                'cluster_id' => $clusterId,
                'cluster_name' => $clusterName,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseName,
                'timeslot_id' => $timeslot['id'],
                'timeslot_from' => $timeslot['from'],
                'timeslot_to' => $timeslot['to'],
                'planned_delivery_date' => $timeslot['date'],
                'status' => $status,
                'ozon_status' => (string) ($order['state'] ?? $order['status'] ?? ''),
                'ozon_status_description' => (string) ($order['state_name'] ?? $order['status_name'] ?? ''),
                'created_in_ozon_at' => $createdAt,
                'meta' => [
                    'order_id' => $orderId,
                    'bundle_id' => $bundleId !== '' ? $bundleId : null,
                    'source' => 'ozon_supply_order_sync',
                ],
                'ozon_response' => [
                    'order' => $order,
                    'details_v1' => $v1Details,
                    'bundle' => $bundle,
                    'direct_items' => $directItems,
                ],
            ];

            DB::beginTransaction();
            try {
                if ($existing) {
                    $existing->update($payload);
                    $supply = $existing->fresh();
                    $updated++;
                } else {
                    $supply = Supply::query()->create($payload);
                    $created++;
                }

                $items = $bundleId !== '' ? ($bundleItemsByBundleId[$bundleId] ?? []) : $this->flattenBundleItems($bundleItemsByBundleId);
                if ($items === []) {
                    $items = $this->extractDirectItems($directItems);
                }
                $itemsSynced += $this->syncSupplyItems($integration->id, $supply, $items, $remoteSupplyId);
                $supply->recalculateTotals();
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'items_synced' => $itemsSynced];
    }

    private function syncSupplyItems(int $integrationId, Supply $supply, array $items, string $remoteSupplyId): int
    {
        $synced = 0;
        $keepIds = [];

        foreach ($items as $item) {
            $sku = (string) ($item['offer_id'] ?? $item['sku'] ?? '');
            if ($sku === '') {
                continue;
            }

            $product = Product::query()
                ->where('integration_id', $integrationId)
                ->where('sku', $sku)
                ->first();

            $row = SupplyItem::query()->updateOrCreate(
                [
                    'supply_id' => $supply->id,
                    'sku' => $sku,
                ],
                [
                    'product_id' => $product?->id,
                    'ozon_product_id' => (string) ($item['product_id'] ?? $product?->ozon_product_id ?? ''),
                    'barcode' => $item['barcode'] ?? $product?->barcode,
                    'product_name' => $item['name'] ?? $item['product_name'] ?? $product?->name,
                    'planned_qty' => (int) ($item['quantity'] ?? 0),
                    'packed_qty' => (int) ($item['packed_qty'] ?? 0),
                    'shipped_qty' => (int) ($item['shipped_qty'] ?? ($item['quantity'] ?? 0)),
                    'accepted_qty' => isset($item['accepted_qty']) ? (int) $item['accepted_qty'] : null,
                    'rejected_qty' => isset($item['rejected_qty']) ? (int) $item['rejected_qty'] : null,
                    'pack_multiple' => max(1, (int) ($item['pack_multiple'] ?? 1)),
                    'boxes_count' => (int) ($item['boxes_count'] ?? 0),
                    'weight' => $item['weight'] ?? null,
                    'length' => $item['length'] ?? null,
                    'width' => $item['width'] ?? null,
                    'height' => $item['height'] ?? null,
                    'status' => SupplyItem::STATUS_PENDING,
                    'meta' => [
                        'remote_supply_id' => $remoteSupplyId,
                        'source' => 'ozon_supply_order_bundle',
                        'raw' => $item,
                    ],
                ]
            );

            $keepIds[] = $row->id;
            $synced++;
        }

        if ($keepIds !== []) {
            SupplyItem::query()
                ->where('supply_id', $supply->id)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }

        return $synced;
    }

    private function extractBundleItems(array $bundle, array $supplies = []): array
    {
        $result = [];

        foreach (($bundle['bundles'] ?? []) as $bundleData) {
            $bundleId = (string) ($bundleData['bundle_id'] ?? $bundleData['id'] ?? '');
            if ($bundleId === '') {
                continue;
            }

            $result[$bundleId] = $this->extractItemsFromNode($bundleData);
        }

        if ($result === [] && ! empty($bundle['items']) && count($supplies) === 1) {
            $bundleId = (string) ($supplies[0]['bundle_id'] ?? 'single_bundle');
            $result[$bundleId] = $this->extractItemsFromNode($bundle);
        }

        return $result;
    }

    private function flattenBundleItems(array $bundles): array
    {
        return array_values(array_merge(...array_values($bundles ?: [[]])));
    }

    private function extractItemsFromNode(array $node): array
    {
        $items = [];

        foreach (['items', 'products'] as $key) {
            foreach (($node[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        foreach (($node['cargoes'] ?? []) as $cargo) {
            if (is_array($cargo)) {
                $items = array_merge($items, $this->extractItemsFromNode($cargo));
            }
        }

        return $items;
    }

    private function extractDirectItems(array $response): array
    {
        $items = [];
        foreach (($response['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function extractClusterName(array $supplyData, array $order, array $v1Details): ?string
    {
        $storageWarehouseName = $supplyData['storage_warehouse']['name'] ?? null;
        $storageWarehouseCluster = is_string($storageWarehouseName) ? OzonWarehouseCluster::findByWarehouseName($storageWarehouseName) : null;
        $candidates = [
            $storageWarehouseCluster?->cluster_name,
            $supplyData['cluster_name'] ?? null,
            $supplyData['macrolocal_cluster_name'] ?? null,
            $v1Details['cluster_name'] ?? null,
            $v1Details['warehouse']['cluster_name'] ?? null,
            $order['cluster_name'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractClusterId(array $supplyData, array $order, array $v1Details): ?string
    {
        $storageWarehouseName = $supplyData['storage_warehouse']['name'] ?? null;
        $storageWarehouseCluster = is_string($storageWarehouseName) ? OzonWarehouseCluster::findByWarehouseName($storageWarehouseName) : null;
        $candidates = [
            $storageWarehouseCluster?->cluster_id,
            $supplyData['cluster_id'] ?? null,
            $supplyData['macrolocal_cluster_id'] ?? null,
            $v1Details['cluster_id'] ?? null,
            $v1Details['warehouse']['cluster_id'] ?? null,
            $order['cluster_id'] ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function extractWarehouseName(array $supplyData, array $order, array $v1Details, ?string $fallbackClusterName): ?string
    {
        $candidates = [
            $supplyData['storage_warehouse']['name'] ?? null,
            $supplyData['warehouse_name'] ?? null,
            $supplyData['warehouse']['name'] ?? null,
            $order['drop_off_warehouse']['name'] ?? null,
            $v1Details['warehouse_name'] ?? null,
            $v1Details['warehouse']['name'] ?? null,
            $order['warehouse_name'] ?? null,
            $fallbackClusterName,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractWarehouseId(array $supplyData, array $order, array $v1Details): ?string
    {
        $candidates = [
            $supplyData['storage_warehouse']['warehouse_id'] ?? null,
            $supplyData['warehouse_id'] ?? null,
            $supplyData['warehouse']['id'] ?? null,
            $order['drop_off_warehouse']['warehouse_id'] ?? null,
            $v1Details['warehouse_id'] ?? null,
            $v1Details['warehouse']['id'] ?? null,
            $order['warehouse_id'] ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function extractTimeslot(array $supplyData, array $v1Details): array
    {
        $from = $this->parseDate(
            $supplyData['timeslot_from']
                ?? $supplyData['from']
                ?? $v1Details['timeslot']['timeslot']['from']
                ?? $v1Details['timeslot_from']
                ?? null
        );
        $to = $this->parseDate(
            $supplyData['timeslot_to']
                ?? $supplyData['to']
                ?? $v1Details['timeslot']['timeslot']['to']
                ?? $v1Details['timeslot_to']
                ?? null
        );

        return [
            'id' => (string) ($supplyData['timeslot_id'] ?? $v1Details['timeslot_id'] ?? $v1Details['timeslot']['id'] ?? ''),
            'from' => $from,
            'to' => $to,
            'date' => $from?->toDateString(),
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapOrderStateToSupplyStatus(string $state): string
    {
        return match (mb_strtoupper($state)) {
            'DATA_FILLING' => Supply::STATUS_DRAFT_OZON,
            'READY_TO_SUPPLY' => Supply::STATUS_READY_TO_SHIP,
            'IN_TRANSIT' => Supply::STATUS_IN_TRANSIT,
            'ACCEPTANCE' => Supply::STATUS_AT_WAREHOUSE,
            'ACCEPTED' => Supply::STATUS_ACCEPTED_FULL,
            'PARTIALLY_ACCEPTED' => Supply::STATUS_ACCEPTED_PARTIAL,
            'CANCELLED' => Supply::STATUS_CANCELLED,
            default => Supply::STATUS_DRAFT_OZON,
        };
    }
}
