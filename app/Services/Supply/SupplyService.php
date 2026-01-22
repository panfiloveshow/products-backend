<?php

namespace App\Services\Supply;

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
     * Создать черновик в Ozon
     */
    public function createOzonDraft(Supply $supply): array
    {
        $integration = $supply->integration;
        $ozon = OzonMarketplace::fromIntegration($integration);

        // Подготавливаем товары
        $items = $supply->items->map(fn($item) => [
            'sku' => $item->sku,
            'quantity' => $item->planned_qty,
        ])->toArray();

        $startTime = microtime(true);

        try {
            // Выбираем метод создания черновика
            $result = match ($supply->supply_method) {
                Supply::METHOD_DIRECT => $ozon->supplies()->createDirectDraft([
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
            $supply->update([
                'ozon_draft_id' => $result['draft_id'],
                'ozon_response' => $result,
            ]);

            $supply->updateStatus(Supply::STATUS_DRAFT_OZON);

            // Логируем успех
            $supply->logEvent(SupplyEvent::TYPE_DRAFT_CREATED, [
                'title' => 'Черновик создан в Ozon',
                'new_value' => $result['draft_id'],
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

        // Проверяем кэш
        if ($useCache) {
            $cached = TimeslotCache::where('integration_id', $supply->integration_id)
                ->where('warehouse_id', $supply->warehouse_id)
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
            $supply->warehouse_id
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
            $result = $ozon->supplies()->createSupplyFromDraft(
                $supply->ozon_draft_id,
                $supply->warehouse_id,
                $timeslotId
            );

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            // Получаем данные слота из кэша
            $slotData = TimeslotCache::where('timeslot_id', $timeslotId)->first();

            // Обновляем поставку
            $supply->update([
                'ozon_supply_id' => $result['supply_order_id'] ?? null,
                'timeslot_id' => $timeslotId,
                'timeslot_from' => $slotData?->datetime_from,
                'timeslot_to' => $slotData?->datetime_to,
                'planned_delivery_date' => $slotData?->slot_date,
                'ozon_response' => $result,
            ]);

            $supply->updateStatus(Supply::STATUS_SLOT_BOOKED);

            // Логируем
            $supply->logEvent(SupplyEvent::TYPE_SLOT_BOOKED, [
                'title' => 'Слот забронирован',
                'new_value' => $timeslotId,
                'description' => $slotData ? "Дата: {$slotData->slot_date}, время: {$slotData->formatted_time}" : null,
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
        if (!$supply->ozon_draft_id) {
            return;
        }

        $ozon = OzonMarketplace::fromIntegration($supply->integration);

        try {
            $status = $ozon->supplies()->getSupplyCreateStatus($supply->ozon_draft_id);

            $oldStatus = $supply->ozon_status;
            $newStatus = $status['status'] ?? null;

            if ($newStatus && $newStatus !== $oldStatus) {
                $supply->update([
                    'ozon_status' => $newStatus,
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
            }

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
            'AWAITING_SLOT' => Supply::STATUS_SLOT_PENDING,
            'SLOT_BOOKED' => Supply::STATUS_SLOT_BOOKED,
            'AWAITING_DELIVER' => Supply::STATUS_READY_TO_SHIP,
            'IN_TRANSIT' => Supply::STATUS_IN_TRANSIT,
            'ACCEPTANCE_IN_PROGRESS' => Supply::STATUS_AT_WAREHOUSE,
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

    /**
     * Отменить поставку
     */
    public function cancel(Supply $supply, ?string $reason = null, ?int $userId = null): void
    {
        if (!$supply->is_editable) {
            throw new \InvalidArgumentException('Поставку нельзя отменить на текущем этапе');
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
