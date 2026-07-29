<?php

namespace App\Services\Supply;

use App\Exceptions\OzonPreconditionException;
use App\Domains\Ozon\OzonMarketplace;
use App\Models\Integration;
use App\Models\Supply;
use App\Models\SupplyEvent;
use App\Models\SupplyItem;
use App\Models\SupplyRecommendation;
use App\Models\TimeslotCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис управления поставками Ozon FBO
 * 
 * Отвечает за:
 * - Создание поставок из рекомендаций/планов
 * - Интеграцию с Ozon API (черновики, слоты, подтверждение)
 * - Управление жизненным циклом поставки
 * - Мониторинг статусов
 */
class SupplyService
{
    protected OzonMarketplace $ozon;

    /**
     * Создать поставку из рекомендаций
     */
    public function createFromRecommendations(
        Integration $integration,
        array $recommendationIds,
        array $options = []
    ): Supply {
        return DB::transaction(function () use ($integration, $recommendationIds, $options) {
            // Получаем рекомендации
            $recommendations = SupplyRecommendation::whereIn('id', $recommendationIds)
                ->where('integration_id', $integration->id)
                ->whereIn('state', [
                    SupplyRecommendation::STATE_NEW,
                    SupplyRecommendation::STATE_ACCEPTED,
                ])
                ->get();

            if ($recommendations->isEmpty()) {
                throw new \InvalidArgumentException('Нет доступных рекомендаций для создания поставки');
            }

            // Определяем кластер/склад (берём из первой рекомендации или из опций)
            $clusterId = $options['cluster_id'] ?? $recommendations->first()->cluster_id;
            $warehouseId = $options['warehouse_id'] ?? $recommendations->first()->warehouse_id;

            // Создаём поставку
            $supply = Supply::create([
                'integration_id' => $integration->id,
                'supply_type' => Supply::TYPE_FBO,
                'supply_method' => $options['supply_method'] ?? Supply::METHOD_DIRECT,
                'delivery_scheme' => $options['delivery_scheme'] ?? null,
                'cluster_id' => $clusterId,
                'cluster_name' => $recommendations->first()->cluster_name,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $recommendations->first()->warehouse_name,
                'status' => Supply::STATUS_DRAFT,
                'created_by' => $options['user_id'] ?? null,
                'responsible_id' => $options['responsible_id'] ?? $options['user_id'] ?? null,
                'supply_plan_id' => $options['plan_id'] ?? null,
                'comment' => $options['comment'] ?? null,
            ]);

            // Добавляем позиции
            foreach ($recommendations as $rec) {
                SupplyItem::create([
                    'supply_id' => $supply->id,
                    'product_id' => $rec->product_id,
                    'sku' => $rec->sku,
                    'ozon_product_id' => $rec->ozon_product_id,
                    'product_name' => $rec->product_name,
                    'planned_qty' => $rec->final_qty,
                    'pack_multiple' => $rec->pack_multiple,
                    'recommendation_id' => $rec->id,
                    'status' => SupplyItem::STATUS_PENDING,
                ]);

                // Обновляем статус рекомендации
                $rec->addToSupply($supply->id);
            }

            // Пересчитываем итоги
            $supply->recalculateTotals();

            // Логируем событие
            $supply->logEvent(SupplyEvent::TYPE_CREATED, [
                'title' => 'Поставка создана',
                'description' => "Создана из {$recommendations->count()} рекомендаций",
                'initiated_by' => $options['user_id'] ? 'user' : 'system',
                'user_id' => $options['user_id'] ?? null,
            ]);

            return $supply;
        });
    }

    /**
     * Создать поставку вручную (без рекомендаций)
     */
    public function createManual(
        Integration $integration,
        array $items,
        array $options = []
    ): Supply {
        return DB::transaction(function () use ($integration, $items, $options) {
            // Создаём поставку
            $supply = Supply::create([
                'integration_id' => $integration->id,
                'supply_type' => Supply::TYPE_FBO,
                'supply_method' => $options['supply_method'] ?? Supply::METHOD_DIRECT,
                'delivery_scheme' => $options['delivery_scheme'] ?? null,
                'warehouse_id' => $options['warehouse_id'] ?? null,
                'warehouse_name' => $options['warehouse_name'] ?? null,
                'status' => Supply::STATUS_DRAFT,
                'created_by' => $options['user_id'] ?? null,
                'responsible_id' => $options['responsible_id'] ?? $options['user_id'] ?? null,
                'comment' => $options['comment'] ?? null,
            ]);

            // Добавляем позиции
            foreach ($items as $item) {
                // Пытаемся найти продукт в БД для дополнительных данных
                $product = \App\Models\Product::where('integration_id', $integration->id)
                    ->where('sku', $item['sku'])
                    ->first();

                SupplyItem::create([
                    'supply_id' => $supply->id,
                    'product_id' => $product?->id,
                    'sku' => $item['sku'],
                    'ozon_product_id' => $product?->ozon_product_id ?? $item['ozon_product_id'] ?? null,
                    'product_name' => $item['product_name'] ?? $product?->name ?? null,
                    'barcode' => $product?->barcode ?? $item['barcode'] ?? null,
                    'planned_qty' => $item['quantity'],
                    'pack_multiple' => $product?->pack_multiple ?? $item['pack_multiple'] ?? 1,
                    'status' => SupplyItem::STATUS_PENDING,
                ]);
            }

            // Пересчитываем итоги
            $supply->recalculateTotals();

            // Логируем событие
            $supply->logEvent(SupplyEvent::TYPE_CREATED, [
                'title' => 'Поставка создана вручную',
                'description' => "Создана с " . count($items) . " позициями",
                'initiated_by' => $options['user_id'] ? 'user' : 'system',
                'user_id' => $options['user_id'] ?? null,
            ]);

            return $supply;
        });
    }

    /**
     * Создать черновик в Ozon
     */
    public function createOzonDraft(Supply $supply): array
    {
        $supply->refresh();
        if ($supply->ozon_draft_id) {
            return array_merge(
                is_array($supply->ozon_response) ? $supply->ozon_response : [],
                [
                    'draft_id' => (string) $supply->ozon_draft_id,
                    'idempotent' => true,
                ]
            );
        }

        $previousResponse = is_array($supply->ozon_response) ? $supply->ozon_response : [];
        $existingOperationId = $previousResponse['operation_id'] ?? null;
        if ($existingOperationId) {
            $ozon = OzonMarketplace::fromIntegration($supply->integration);
            $draftInfo = $ozon->supplies()->getDraftCreateInfo((string) $existingOperationId);
            $draftId = $this->extractDraftId($draftInfo);
            $calculationFailed = ($draftInfo['status'] ?? null) === 'CALCULATION_STATUS_FAILED';
            $result = array_merge($previousResponse, [
                'draft_id' => $draftId,
                'operation_id' => (string) $existingOperationId,
                'draft_info' => $draftInfo,
                'status' => $draftId ? 'draft' : ($calculationFailed ? 'failed' : 'pending'),
                'idempotent' => true,
            ]);
            $supply->update([
                'ozon_draft_id' => $draftId,
                'ozon_response' => $result,
            ]);
            if ($draftId) {
                $supply->updateStatus(Supply::STATUS_DRAFT_OZON);
            }

            return $result;
        }

        $integration = $supply->integration;
        $ozon = OzonMarketplace::fromIntegration($integration);

        // Подготавливаем товары (Ozon требует числовой SKU)
        $invalidSkus = [];
        $items = $supply->items->map(function ($item) use (&$invalidSkus) {
            $product = $item->product;
            $ozonSku = $item->ozon_product_id
                ?? $product?->ozon_product_id
                ?? ($product?->ozon_data['sku'] ?? null)
                ?? ($product?->ozon_data['product_id'] ?? null)
                ?? $product?->marketplace_id
                ?? $item->sku;

            if (!$ozonSku || !is_numeric($ozonSku)) {
                $invalidSkus[] = $item->sku;
            }

            return [
                'sku' => $ozonSku,
                'quantity' => $item->planned_qty,
            ];
        })
            ->groupBy(fn (array $item): string => (string) $item['sku'])
            ->map(fn ($sameSkuItems, string $sku): array => [
                'sku' => (int) $sku,
                'quantity' => (int) $sameSkuItems->sum('quantity'),
            ])
            ->values()
            ->all();

        if (!empty($invalidSkus)) {
            throw new OzonPreconditionException('Не удалось определить числовой Ozon SKU для товаров: ' . implode(', ', $invalidSkus));
        }

        $startTime = microtime(true);

        try {
            // Выбираем метод создания черновика
            $result = match ($supply->supply_method) {
                Supply::METHOD_DIRECT => $ozon->supplies()->createDirectDraft([
                    'cluster_id' => $supply->cluster_id,
                    'macrolocal_cluster_id' => $supply->cluster_id,
                    'items' => $items,
                ]),
                Supply::METHOD_CROSSDOCK => $ozon->supplies()->createCrossdockDraft([
                    'macrolocal_cluster_id' => $supply->cluster_id,
                    'delivery_scheme' => $supply->delivery_scheme,
                    'point_id' => $supply->drop_off_point_id,
                    'point_type' => $supply->drop_off_point_type,
                    'seller_warehouse_id' => $supply->seller_warehouse_id,
                    'items' => $items,
                ]),
                Supply::METHOD_MULTI_CLUSTER => $ozon->supplies()->createMultiClusterDraft([
                    'cluster_ids' => [$supply->cluster_id], // TODO: поддержка нескольких кластеров
                    'delivery_scheme' => $supply->delivery_scheme,
                    'point_id' => $supply->drop_off_point_id,
                    'point_type' => $supply->drop_off_point_type,
                    'seller_warehouse_id' => $supply->seller_warehouse_id,
                    'items' => $items,
                ]),
                default => throw new \InvalidArgumentException("Unknown supply method: {$supply->supply_method}"),
            };

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            // Обновляем поставку
            $draftId = isset($result['draft_id']) && $result['draft_id'] !== ''
                ? (string) $result['draft_id']
                : null;
            $supply->update([
                'ozon_draft_id' => $draftId,
                'ozon_response' => $result,
            ]);

            if ($draftId) {
                $supply->updateStatus(Supply::STATUS_DRAFT_OZON);
            }

            // Логируем успех
            $supply->logEvent(SupplyEvent::TYPE_DRAFT_CREATED, [
                'title' => 'Черновик создан в Ozon',
                'new_value' => $draftId ?: ($result['operation_id'] ?? null),
                'api_method' => 'POST',
                'api_endpoint' => "/v1/draft/{$supply->supply_method}/create",
                'api_response_body' => $result,
                'api_response_code' => 200,
                'api_duration_ms' => $duration,
            ]);

            return $result;

        } catch (\Exception $e) {
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            $supply->updateStatus(Supply::STATUS_ERROR);

            $supply->logEvent(SupplyEvent::TYPE_ERROR, [
                'title' => 'Ошибка создания черновика в Ozon',
                'error_message' => $e->getMessage(),
                'is_critical' => true,
                'api_duration_ms' => $duration,
            ]);

            throw $e;
        }
    }

    /**
     * Получить информацию о черновике из Ozon
     */
    public function getDraftInfo(Supply $supply): array
    {
        if (!$supply->ozon_draft_id) {
            throw new \InvalidArgumentException('Поставка не имеет черновика в Ozon');
        }

        $ozon = OzonMarketplace::fromIntegration($supply->integration);
        
        return $ozon->supplies()->getDraftInfo($supply->ozon_draft_id);
    }

    /**
     * Получить доступные слоты для поставки
     */
    public function getAvailableTimeslots(Supply $supply, bool $useCache = true): array
    {
        if (!$supply->ozon_draft_id) {
            throw new \InvalidArgumentException('Сначала создайте черновик в Ozon');
        }

        if (! $supply->warehouse_id) {
            $this->resolveWarehouseForDraft($supply);
            $supply->refresh();
        }
        if (! $supply->warehouse_id) {
            throw new \RuntimeException(
                'Ozon не вернул доступный склад для выбранного кластера черновика.'
            );
        }

        // Проверяем кэш
        if ($useCache) {
            $cached = TimeslotCache::where('integration_id', $supply->integration_id)
                ->where('warehouse_id', $supply->warehouse_id)
                ->forDraft((string) $supply->ozon_draft_id)
                ->notExpired()
                ->available()
                ->upcoming()
                ->orderBy('slot_date')
                ->orderBy('time_from')
                ->get();

            if ($cached->isNotEmpty()) {
                return $cached->toArray();
            }
        }

        // Запрашиваем из API
        $ozon = OzonMarketplace::fromIntegration($supply->integration);
        
        $slots = $ozon->supplies()->getDraftTimeslots(
            $supply->ozon_draft_id,
            $supply->warehouse_id,
            $supply->cluster_id,
            $supply->warehouse_name
        );

        // Обновляем кэш
        TimeslotCache::updateCache(
            $supply->integration_id,
            $supply->warehouse_id,
            array_map(fn($s) => [...$s, 'draft_id' => $supply->ozon_draft_id], $slots),
            30 // TTL 30 минут
        );

        // Логируем запрос слотов
        $supply->logEvent(SupplyEvent::TYPE_SLOT_REQUESTED, [
            'title' => 'Запрошены слоты приёмки',
            'description' => "Получено " . count($slots) . " слотов",
        ]);

        return $slots;
    }

    /**
     * Забронировать слот
     */
    public function bookTimeslot(Supply $supply, string $timeslotId): array
    {
        if (!$supply->ozon_draft_id) {
            throw new \InvalidArgumentException('Сначала создайте черновик в Ozon');
        }

        $ozon = OzonMarketplace::fromIntegration($supply->integration);

        $startTime = microtime(true);

        try {
            $slotData = TimeslotCache::query()
                ->where('integration_id', $supply->integration_id)
                ->where('timeslot_id', $timeslotId)
                ->forDraft((string) $supply->ozon_draft_id)
                ->forWarehouse((string) $supply->warehouse_id)
                ->notExpired()
                ->available()
                ->first();
            if (! $slotData?->datetime_from || ! $slotData?->datetime_to) {
                throw new OzonPreconditionException(
                    'Слот устарел или не принадлежит этому черновику. Обновите список доступных слотов.'
                );
            }
            $timeslotPayload = [
                'from' => $slotData->datetime_from->toIso8601String(),
                'to' => $slotData->datetime_to->toIso8601String(),
            ];

            $result = $ozon->supplies()->createSupplyFromDraft(
                (int) $supply->ozon_draft_id,
                (int) $supply->warehouse_id,
                $timeslotPayload,
                $supply->cluster_id ? (int) $supply->cluster_id : null,
                $supply->warehouse_name,
                $supply->supply_method ?? 'direct'
            );

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            $timeslotFrom = $slotData->datetime_from;
            $timeslotTo = $slotData->datetime_to;
            $plannedDeliveryDate = $slotData->slot_date;

            // Обновляем поставку
            $supply->update([
                'ozon_order_id' => $result['supply_order_id'] ?? $result['order_id'] ?? null,
                // Старые записи использовали ozon_supply_id как order_id.
                'ozon_supply_id' => $supply->ozon_supply_id
                    ?: ($result['supply_id'] ?? $result['supply_order_id'] ?? $result['order_id'] ?? null),
                'timeslot_id' => $timeslotId,
                'timeslot_from' => $timeslotFrom,
                'timeslot_to' => $timeslotTo,
                'planned_delivery_date' => $plannedDeliveryDate,
                'ozon_response' => $result,
            ]);

            $supply->updateStatus(Supply::STATUS_SLOT_BOOKED);

            // Логируем
            $supply->logEvent(SupplyEvent::TYPE_SLOT_BOOKED, [
                'title' => 'Слот забронирован',
                'new_value' => $timeslotId,
                'description' => "Дата: {$slotData->slot_date}, время: {$slotData->formatted_time}",
                'api_response_body' => $result,
                'api_response_code' => 200,
                'api_duration_ms' => $duration,
            ]);

            return $result;

        } catch (\Exception $e) {
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            $supply->logEvent(SupplyEvent::TYPE_ERROR, [
                'title' => 'Ошибка бронирования слота',
                'error_message' => $e->getMessage(),
                'is_critical' => true,
                'api_duration_ms' => $duration,
            ]);

            throw $e;
        }
    }

    /**
     * Выбрать лучший слот автоматически
     */
    public function selectBestTimeslot(Supply $supply, array $preferences = []): ?array
    {
        $slots = $this->getAvailableTimeslots($supply);

        if (empty($slots)) {
            return null;
        }

        // Скоринг слотов
        $scoredSlots = [];
        $targetDate = $preferences['target_date'] ?? now()->addDays(3)->toDateString();
        $preferredWeekdays = $preferences['weekdays'] ?? [1, 2, 3, 4, 5]; // Пн-Пт
        $preferredTimeFrom = $preferences['time_from'] ?? '10:00';
        $preferredTimeTo = $preferences['time_to'] ?? '16:00';

        foreach ($slots as $slot) {
            $score = 100;

            // Штраф за удалённость от целевой даты
            $slotDate = $slot['date'] ?? substr($slot['from_datetime'] ?? '', 0, 10);
            $daysDiff = abs((strtotime($slotDate) - strtotime($targetDate)) / 86400);
            $score -= $daysDiff * 5;

            // Штраф за нежелательный день недели
            $weekday = date('N', strtotime($slotDate));
            if (!in_array($weekday, $preferredWeekdays)) {
                $score -= 20;
            }

            // Штраф за неудобное время
            $timeFrom = $slot['time_from'] ?? substr($slot['from_datetime'] ?? '', 11, 5);
            if ($timeFrom < $preferredTimeFrom || $timeFrom > $preferredTimeTo) {
                $score -= 10;
            }

            // Бонус за доступную вместимость
            if (isset($slot['remaining_capacity']) && $slot['remaining_capacity'] > 50) {
                $score += 5;
            }

            $scoredSlots[] = [
                'slot' => $slot,
                'score' => $score,
                'reasons' => [
                    'days_from_target' => $daysDiff,
                    'weekday' => $weekday,
                    'time' => $timeFrom,
                ],
            ];
        }

        // Сортируем по скору
        usort($scoredSlots, fn($a, $b) => $b['score'] <=> $a['score']);

        return $scoredSlots[0] ?? null;
    }

    /**
     * Синхронизировать статус поставки из Ozon
     */
    public function syncStatus(Supply $supply): void
    {
        if (! $supply->ozon_order_id && ! $supply->ozon_supply_id && ! $supply->ozon_draft_id) {
            return;
        }

        $ozon = OzonMarketplace::fromIntegration($supply->integration);

        try {
            $orderId = $supply->ozon_order_id ?: $supply->ozon_supply_id;
            if ($orderId) {
                $details = $ozon->supplies()->getSupplyOrdersDetails([
                    (int) $orderId,
                ]);
                $order = collect($details['orders'] ?? [])->first(
                    fn (array $item): bool => (string) ($item['id'] ?? $item['order_id'] ?? '')
                        === (string) $orderId
                ) ?? collect($details['orders'] ?? [])->first();

                if (! is_array($order)) {
                    Log::warning('Ozon supply-order/get returned no matching order', [
                        'supply_id' => $supply->id,
                        'ozon_order_id' => $orderId,
                    ]);

                    return;
                }

                $remoteSupply = collect($order['supplies'] ?? [])->first(
                    fn (array $item): bool => (string) ($item['supply_id'] ?? $item['id'] ?? '')
                        === (string) $supply->ozon_supply_id
                );
                $status = $order;
                $newStatus = $remoteSupply['supply_state']
                    ?? $remoteSupply['state']
                    ?? $order['state']
                    ?? $order['status']
                    ?? null;
                $description = $remoteSupply['supply_state_name']
                    ?? $remoteSupply['state_name']
                    ?? $order['state_name']
                    ?? $order['status_name']
                    ?? null;
            } else {
                // До появления supply_order_id проверяем только асинхронное
                // создание заявки из черновика.
                $status = $ozon->supplies()->getSupplyCreateStatus((string) $supply->ozon_draft_id);
                $newStatus = $status['status'] ?? $status['state'] ?? null;
                $description = $status['status_name'] ?? $status['state_name'] ?? null;
                $supplyOrderId = $status['supply_order_id']
                    ?? $status['order_id']
                    ?? ($status['result']['supply_order_id'] ?? null);

                if ($supplyOrderId) {
                    $supply->ozon_order_id = (string) $supplyOrderId;
                }
            }

            $oldStatus = $supply->ozon_status;

            if ($newStatus && $newStatus !== $oldStatus) {
                $supply->update([
                    'ozon_order_id' => $supply->ozon_order_id,
                    'ozon_status' => $newStatus,
                    'ozon_status_description' => $description,
                    'ozon_response' => $status,
                ]);

                // Маппим статус Ozon на внутренний
                $this->mapOzonStatus($supply, $newStatus);

                $supply->logEvent(SupplyEvent::TYPE_STATUS_CHANGED, [
                    'title' => 'Статус обновлён из Ozon',
                    'old_value' => $oldStatus,
                    'new_value' => $newStatus,
                    'initiated_by' => 'api',
                ]);
            } elseif ($supply->isDirty('ozon_order_id')) {
                $supply->save();
            }

            $supply->forceFill(['external_last_synced_at' => now()])->save();

        } catch (\Exception $e) {
            Log::warning("Failed to sync supply status", [
                'supply_id' => $supply->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Маппинг статуса Ozon на внутренний статус
     */
    protected function mapOzonStatus(Supply $supply, string $ozonStatus): void
    {
        $statusMap = [
            'DRAFT' => Supply::STATUS_DRAFT_OZON,
            'DATA_FILLING' => Supply::STATUS_DRAFT_OZON,
            'AWAITING_SLOT' => Supply::STATUS_SLOT_PENDING,
            'SLOT_BOOKED' => Supply::STATUS_SLOT_BOOKED,
            'AWAITING_DELIVER' => Supply::STATUS_READY_TO_SHIP,
            'READY_TO_SUPPLY' => Supply::STATUS_READY_TO_SHIP,
            'PROCESSING' => Supply::STATUS_PREPARING,
            'IN_TRANSIT' => Supply::STATUS_IN_TRANSIT,
            'ACCEPTANCE_IN_PROGRESS' => Supply::STATUS_AT_WAREHOUSE,
            'ACCEPTANCE' => Supply::STATUS_AT_WAREHOUSE,
            'ACCEPTED' => Supply::STATUS_ACCEPTED_FULL,
            'PARTIALLY_ACCEPTED' => Supply::STATUS_ACCEPTED_PARTIAL,
            'CLOSED' => Supply::STATUS_CLOSED,
            'CANCELLED' => Supply::STATUS_CANCELLED,
        ];

        $newStatus = $statusMap[strtoupper($ozonStatus)] ?? null;

        if ($newStatus && $newStatus !== $supply->status) {
            $supply->updateStatus($newStatus);
        }
    }

    private function extractDraftId(array $response): ?string
    {
        $value = $response['draft_id'] ?? $response['result']['draft_id'] ?? null;
        if ($value) {
            return (string) $value;
        }

        foreach (($response['clusters'] ?? $response['result']['clusters'] ?? []) as $cluster) {
            if (! empty($cluster['draft_id'])) {
                return (string) $cluster['draft_id'];
            }
        }

        return null;
    }

    private function resolveWarehouseForDraft(Supply $supply): void
    {
        $ozon = OzonMarketplace::fromIntegration($supply->integration);
        $info = $ozon->supplies()->getDraftInfo((string) $supply->ozon_draft_id);
        $clusters = $info['clusters'] ?? $info['result']['clusters'] ?? [];
        $wantedCluster = (string) ($supply->cluster_id ?? '');
        $fallback = null;

        foreach ($clusters as $cluster) {
            $clusterId = (string) (
                $cluster['id']
                ?? $cluster['cluster_id']
                ?? $cluster['macrolocal_cluster_id']
                ?? ''
            );
            foreach (($cluster['warehouses'] ?? $cluster['storage_warehouses'] ?? []) as $warehouse) {
                $available = $warehouse['is_available']
                    ?? (($warehouse['availability_status']['state'] ?? 'AVAILABLE') === 'AVAILABLE');
                if (! $available) {
                    continue;
                }

                $candidate = [
                    'warehouse_id' => (string) ($warehouse['id'] ?? $warehouse['warehouse_id'] ?? ''),
                    'warehouse_name' => $warehouse['name'] ?? $warehouse['warehouse_name'] ?? null,
                ];
                if ($candidate['warehouse_id'] === '') {
                    continue;
                }
                $fallback ??= $candidate;
                if ($wantedCluster !== '' && $clusterId === $wantedCluster) {
                    $supply->update($candidate);
                    return;
                }
            }
        }

        if ($fallback !== null) {
            $supply->update($fallback);
        }
    }

    /**
     * Отменить поставку
     */
    public function cancel(Supply $supply, ?string $reason = null, ?int $userId = null): void
    {
        if (!$supply->is_editable) {
            throw new \InvalidArgumentException('Поставку нельзя отменить на текущем этапе');
        }

        $orderId = $supply->ozon_order_id ?: (
            $supply->ozon_draft_id ? null : $supply->ozon_supply_id
        );
        if ($orderId) {
            $remote = OzonMarketplace::fromIntegration($supply->integration)
                ->fboSupplyOrders()
                ->cancel((int) $orderId);
            if (! ($remote['success'] ?? false)) {
                throw new \RuntimeException(
                    'Ozon не подтвердил отмену поставки: '
                    . (string) ($remote['error']['message'] ?? $remote['error'] ?? 'неизвестная ошибка')
                );
            }
            $supply->logEvent(SupplyEvent::TYPE_STATUS_CHANGED, [
                'title' => 'Отмена отправлена в Ozon',
                'description' => $reason,
                'api_endpoint' => '/v1/supply-order/cancel',
                'api_response_body' => $remote,
                'initiated_by' => $userId ? 'user' : 'system',
                'user_id' => $userId,
            ]);
        }

        $supply->updateStatus(Supply::STATUS_CANCELLED, [
            'title' => 'Поставка отменена',
            'description' => $reason,
            'initiated_by' => $userId ? 'user' : 'system',
            'user_id' => $userId,
        ]);

        // Возвращаем рекомендации в статус "new"
        SupplyRecommendation::where('supply_id', $supply->id)
            ->update([
                'state' => SupplyRecommendation::STATE_NEW,
                'supply_id' => null,
            ]);
    }

    public function rescheduleTimeslot(
        Supply $supply,
        string $timeslotId,
        ?int $userId = null
    ): array {
        $orderId = $supply->ozon_order_id ?: $supply->ozon_supply_id;
        if (! $orderId) {
            throw new \InvalidArgumentException('У поставки ещё нет order_id Ozon');
        }

        $slot = TimeslotCache::query()
            ->where('integration_id', $supply->integration_id)
            ->where('timeslot_id', $timeslotId)
            ->notExpired()
            ->available()
            ->first();
        if (! $slot?->datetime_from || ! $slot?->datetime_to) {
            throw new \InvalidArgumentException('Слот устарел. Обновите список доступных слотов.');
        }

        $result = OzonMarketplace::fromIntegration($supply->integration)
            ->fboSupplyOrders()
            ->editTimeslot(
                (int) $orderId,
                $slot->datetime_from->toIso8601String(),
                $slot->datetime_to->toIso8601String()
            );
        if (($result['_error'] ?? false) || ($result['success'] ?? true) === false) {
            throw new \RuntimeException(
                'Ozon отклонил изменение слота: '
                . (string) ($result['error']['message'] ?? $result['error'] ?? 'неизвестная ошибка')
            );
        }

        $oldSlot = $supply->timeslot_id;
        $supply->update([
            'timeslot_id' => $timeslotId,
            'timeslot_from' => $slot->datetime_from,
            'timeslot_to' => $slot->datetime_to,
            'planned_delivery_date' => $slot->slot_date,
        ]);
        $supply->logEvent(SupplyEvent::TYPE_SLOT_BOOKED, [
            'title' => 'Слот поставки изменён',
            'old_value' => $oldSlot,
            'new_value' => $timeslotId,
            'api_endpoint' => '/v1/fbp/order/direct/timeslot/edit',
            'api_response_body' => $result,
            'initiated_by' => $userId ? 'user' : 'system',
            'user_id' => $userId,
        ]);

        return $result;
    }

    /**
     * Начать сборку
     */
    public function startPreparing(Supply $supply, ?int $userId = null): void
    {
        if ($supply->status !== Supply::STATUS_SLOT_BOOKED) {
            throw new \InvalidArgumentException('Сборку можно начать только после бронирования слота');
        }

        $supply->updateStatus(Supply::STATUS_PREPARING, [
            'title' => 'Начата сборка',
            'initiated_by' => $userId ? 'user' : 'system',
            'user_id' => $userId,
        ]);
    }

    /**
     * Отметить готовность к отгрузке
     */
    public function markReadyToShip(Supply $supply, ?int $userId = null): void
    {
        if ($supply->status !== Supply::STATUS_PREPARING) {
            throw new \InvalidArgumentException('Поставка должна быть в статусе "Сборка"');
        }

        $supply->updateStatus(Supply::STATUS_READY_TO_SHIP, [
            'title' => 'Готово к отгрузке',
            'initiated_by' => $userId ? 'user' : 'system',
            'user_id' => $userId,
        ]);
    }

    /**
     * Отметить отгрузку
     */
    public function markShipped(Supply $supply, ?int $userId = null): void
    {
        if (!in_array($supply->status, [Supply::STATUS_READY_TO_SHIP, Supply::STATUS_PREPARING])) {
            throw new \InvalidArgumentException('Поставка должна быть готова к отгрузке');
        }

        $supply->updateStatus(Supply::STATUS_SHIPPED, [
            'title' => 'Отгружено',
            'initiated_by' => $userId ? 'user' : 'system',
            'user_id' => $userId,
        ]);
    }

    /**
     * Получить статистику поставок
     */
    public function getStats(Integration $integration, ?string $period = '30d'): array
    {
        $days = match ($period) {
            '7d' => 7,
            '14d' => 14,
            '30d' => 30,
            '90d' => 90,
            default => 30,
        };

        $startDate = now()->subDays($days)->toDateString();

        $stats = Supply::where('integration_id', $integration->id)
            ->where('created_at', '>=', $startDate)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as drafts,
                SUM(CASE WHEN status IN ('slot_booked', 'preparing', 'ready_to_ship') THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status IN ('shipped', 'in_transit', 'at_warehouse') THEN 1 ELSE 0 END) as in_transit,
                SUM(CASE WHEN status IN ('accepted_full', 'accepted_partial', 'closed') THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors,
                SUM(total_quantity) as total_items,
                AVG(TIMESTAMPDIFF(HOUR, created_at, accepted_at)) as avg_lead_time_hours
            ")
            ->first();

        return [
            'period' => $period,
            'total' => $stats->total ?? 0,
            'by_status' => [
                'drafts' => $stats->drafts ?? 0,
                'in_progress' => $stats->in_progress ?? 0,
                'in_transit' => $stats->in_transit ?? 0,
                'completed' => $stats->completed ?? 0,
                'cancelled' => $stats->cancelled ?? 0,
                'errors' => $stats->errors ?? 0,
            ],
            'total_items' => $stats->total_items ?? 0,
            'avg_lead_time_hours' => round($stats->avg_lead_time_hours ?? 0, 1),
        ];
    }
}
