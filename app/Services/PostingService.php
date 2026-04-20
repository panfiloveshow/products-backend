<?php

namespace App\Services;

use App\Domains\Ozon\OzonMarketplace;
use App\Domains\Wildberries\WildberriesMarketplace;
use App\Models\Integration;
use App\Models\Posting;
use App\Models\PostingItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PostingService
{
    /**
     * Синхронизировать отправления с маркетплейса
     */
    public function sync(string $integrationId, ?string $status = null, ?string $dateFrom = null): array
    {
        $integration = Integration::findOrFail($integrationId);
        
        Log::info('Starting postings sync', [
            'integration_id' => $integrationId,
            'marketplace' => $integration->marketplace,
            'status' => $status,
            'date_from' => $dateFrom,
        ]);

        try {
            $postings = match ($integration->marketplace) {
                'ozon' => $this->syncOzonPostings($integration, $status, $dateFrom),
                'wildberries' => $this->syncWildberriesPostings($integration, $status, $dateFrom),
                default => throw new \RuntimeException("Unsupported marketplace: {$integration->marketplace}"),
            };

            return [
                'synced' => $postings['total'],
                'created' => $postings['created'],
                'updated' => $postings['updated'],
            ];
        } catch (\Exception $e) {
            Log::error('Postings sync failed', [
                'integration_id' => $integrationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Синхронизация отправлений Ozon FBS
     */
    private function syncOzonPostings(Integration $integration, ?string $status, ?string $dateFrom): array
    {
        $marketplace = OzonMarketplace::fromIntegration($integration);
        $since = $dateFrom ? date('c', strtotime($dateFrom)) : date('c', strtotime('-60 days'));
        $to = date('c');

        $fbs = $this->syncOzonFbsPostings($integration, $marketplace, $status, $since, $to);
        $fbo = $this->syncOzonFboPostings($integration, $marketplace, $since, $to);

        Log::info('Ozon postings synced', [
            'integration_id' => $integration->id,
            'total' => $fbs['total'] + $fbo['total'],
            'created' => $fbs['created'] + $fbo['created'],
            'updated' => $fbs['updated'] + $fbo['updated'],
            'fbs_total' => $fbs['total'],
            'fbo_total' => $fbo['total'],
        ]);

        return [
            'total' => $fbs['total'] + $fbo['total'],
            'created' => $fbs['created'] + $fbo['created'],
            'updated' => $fbs['updated'] + $fbo['updated'],
        ];
    }

    private function syncOzonFbsPostings(Integration $integration, OzonMarketplace $marketplace, ?string $status, string $since, string $to): array
    {
        $ozonStatus = match ($status) {
            'awaiting_packaging' => 'awaiting_packaging',
            'awaiting_deliver' => 'awaiting_deliver',
            'delivering' => 'delivering',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled',
            default => null,
        };

        $created = 0;
        $updated = 0;
        $offset = 0;
        $limit = 1000;

        do {
            $response = $marketplace->getClient()->post('/v3/posting/fbs/list', [
                'dir' => 'DESC',
                'filter' => array_filter([
                    'status' => $ozonStatus,
                    'since' => $since,
                    'to' => $to,
                ]),
                'limit' => $limit,
                'offset' => $offset,
                'with' => [
                    'analytics_data' => true,
                    'barcodes' => true,
                    'financial_data' => true,
                ],
            ]);

            $postings = $response['result']['postings'] ?? [];

            DB::beginTransaction();
            try {
                foreach ($postings as $ozonPosting) {
                    $result = $this->upsertOzonPosting($integration, $ozonPosting, 'fbs');
                    if ($result === 'created') {
                        $created++;
                    } else {
                        $updated++;
                    }
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            $offset += $limit;
        } while (count($postings) === $limit);

        return [
            'total' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    private function syncOzonFboPostings(Integration $integration, OzonMarketplace $marketplace, string $since, string $to): array
    {
        $created = 0;
        $updated = 0;
        $offset = 0;
        $limit = 1000;

        do {
            $response = $marketplace->getClient()->post('/v2/posting/fbo/list', [
                'dir' => 'DESC',
                'filter' => [
                    'since' => $since,
                    'to' => $to,
                ],
                'limit' => $limit,
                'offset' => $offset,
                'with' => [
                    'analytics_data' => true,
                    'financial_data' => true,
                ],
            ]);

            $postings = $response['result'] ?? [];

            DB::beginTransaction();
            try {
                foreach ($postings as $ozonPosting) {
                    $result = $this->upsertOzonPosting($integration, $ozonPosting, 'fbo');
                    if ($result === 'created') {
                        $created++;
                    } else {
                        $updated++;
                    }
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            $offset += $limit;
        } while (count($postings) === $limit);

        return [
            'total' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * Создать или обновить отправление Ozon
     */
    private function upsertOzonPosting(Integration $integration, array $data, string $deliveryType = 'fbs'): string
    {
        $postingNumber = (string) ($data['posting_number'] ?? '');
        if ($postingNumber === '') {
            throw new \RuntimeException('Ozon posting_number is missing');
        }
        
        $existing = Posting::where('integration_id', $integration->id)
            ->where('posting_number', $postingNumber)
            ->first();

        $postingData = [
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'posting_number' => $postingNumber,
            'order_id' => $data['order_id'] ?? null,
            'order_number' => $data['order_number'] ?? null,
            'status' => $this->mapOzonStatus($data['status']),
            'substatus' => $data['substatus'] ?? null,
            'external_status' => $data['status'],
            'shipment_date' => isset($data['shipment_date']) ? date('Y-m-d H:i:s', strtotime($data['shipment_date'])) : null,
            'delivering_date' => isset($data['delivering_date']) ? date('Y-m-d H:i:s', strtotime($data['delivering_date'])) : null,
            // Реальная дата создания заказа на Ozon. Раньше терялась в meta JSON —
            // без неё нельзя было корректно отфильтровать «заказы за последние N дней»,
            // приходилось использовать created_at (время нашего sync'а).
            'in_process_at' => isset($data['in_process_at']) ? date('Y-m-d H:i:s', strtotime($data['in_process_at'])) : null,
            // Дата фактической отмены (если есть в ответе API или в блоке cancellation).
            'cancelled_at' => isset($data['cancellation']['cancelled_at'])
                ? date('Y-m-d H:i:s', strtotime($data['cancellation']['cancelled_at']))
                : (isset($data['cancelled_at']) ? date('Y-m-d H:i:s', strtotime($data['cancelled_at'])) : null),
            'warehouse_id' => $data['delivery_method']['warehouse_id'] ?? $data['analytics_data']['warehouse_id'] ?? null,
            'warehouse_name' => $data['delivery_method']['warehouse'] ?? $data['analytics_data']['warehouse_name'] ?? null,
            'delivery_method' => $data['delivery_method']['tpl_provider_type'] ?? $data['delivery_method']['name'] ?? $deliveryType,
            'delivery_type' => $deliveryType,
            'tpl_integration_type' => $data['tpl_integration_type'] ?? null,
            'customer' => $this->extractOzonCustomer($data),
            'financial_data' => $data['financial_data'] ?? null,
            'analytics_data' => $data['analytics_data'] ?? null,
            'barcodes' => $data['barcodes'] ?? null,
            'meta' => [
                'cancellation' => $data['cancellation'] ?? null,
                'requirements' => $data['requirements'] ?? null,
            ],
            'synced_at' => now(),
        ];

        // Финансовые данные
        if (isset($data['financial_data'])) {
            $fd = $data['financial_data'];
            $postingData['products_total'] = $fd['products_total'] ?? 0;
            $postingData['commission'] = $fd['commission_amount'] ?? 0;
            $postingData['delivery_cost'] = $fd['delivery_cost'] ?? 0;
            $postingData['payout'] = $fd['payout'] ?? 0;
        }

        if ($existing) {
            $existing->update($postingData);
            $posting = $existing;
            $result = 'updated';
        } else {
            $posting = Posting::create($postingData);
            $result = 'created';
        }

        // Синхронизируем товары
        $this->syncOzonPostingItems($posting, $data['products'] ?? [], $deliveryType);

        // Пересчитываем итоги
        $posting->recalculateTotals();
        $posting->update([
            'total_price' => collect($data['products'] ?? [])->sum(fn($p) => ($p['price'] ?? 0) * ($p['quantity'] ?? 1)),
        ]);

        return $result;
    }

    /**
     * Синхронизировать товары отправления Ozon
     */
    private function syncOzonPostingItems(Posting $posting, array $products, string $deliveryType = 'fbs'): void
    {
        // Удаляем старые товары
        $posting->items()->delete();

        foreach ($products as $product) {
            PostingItem::create([
                'posting_id' => $posting->id,
                'sku' => $product['offer_id'] ?? $product['sku'] ?? '',
                'marketplace_sku' => (string) ($product['sku'] ?? ''),
                'offer_id' => $product['offer_id'] ?? null,
                'barcode' => $product['barcode'] ?? null,
                'name' => $product['name'] ?? '',
                'image_url' => $product['digital_codes'][0] ?? null,
                'quantity' => $product['quantity'] ?? 1,
                'price' => $product['price'] ?? 0,
                'commission_amount' => $product['commission_amount'] ?? null,
                'commission_percent' => $product['commission_percent'] ?? null,
                'payout' => $product['payout'] ?? null,
                'weight' => $product['weight'] ?? null,
                'volume' => $product['volume'] ?? null,
                'meta' => [
                    'delivery_type' => $deliveryType,
                    'currency_code' => $product['currency_code'] ?? 'RUB',
                    'mandatory_mark' => $product['mandatory_mark'] ?? null,
                ],
            ]);
        }
    }

    /**
     * Извлечь данные покупателя из Ozon
     */
    private function extractOzonCustomer(array $data): array
    {
        $customer = $data['customer'] ?? [];
        $address = $data['addressee'] ?? [];
        $customerId = $customer['customer_id'] ?? null;
        
        return [
            'name' => $address['name'] ?? ($customerId ? "Покупатель #{$customerId}" : 'Покупатель'),
            'phone' => isset($address['phone']) ? $this->maskPhone($address['phone']) : null,
            'address' => $address['address'] ?? null,
            'customer_id' => $customerId,
        ];
    }

    /**
     * Маскировать телефон
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 4) {
            return $phone;
        }
        return substr($phone, 0, 3) . str_repeat('*', strlen($phone) - 5) . substr($phone, -2);
    }

    /**
     * Маппинг статусов Ozon
     */
    private function mapOzonStatus(string $ozonStatus): string
    {
        return match ($ozonStatus) {
            'awaiting_registration' => Posting::STATUS_AWAITING_REGISTRATION,
            'acceptance_in_progress' => Posting::STATUS_ACCEPTANCE_IN_PROGRESS,
            'awaiting_approve' => Posting::STATUS_AWAITING_PACKAGING,
            'awaiting_packaging' => Posting::STATUS_AWAITING_PACKAGING,
            'awaiting_deliver' => Posting::STATUS_AWAITING_DELIVER,
            'arbitration' => Posting::STATUS_ARBITRATION,
            'client_arbitration' => Posting::STATUS_ARBITRATION,
            'delivering' => Posting::STATUS_DELIVERING,
            'driver_pickup' => Posting::STATUS_DRIVER_PICKUP,
            'delivered' => Posting::STATUS_DELIVERED,
            'cancelled' => Posting::STATUS_CANCELLED,
            'not_accepted' => Posting::STATUS_NOT_ACCEPTED,
            'sent_by_seller' => Posting::STATUS_SENT_BY_SELLER,
            default => Posting::STATUS_AWAITING_PACKAGING,
        };
    }

    /**
     * Синхронизация отправлений Wildberries
     */
    private function syncWildberriesPostings(Integration $integration, ?string $status, ?string $dateFrom): array
    {
        $marketplace = WildberriesMarketplace::fromIntegration($integration);
        $client = $marketplace->getClient();

        // WB API: GET /api/v3/orders
        $params = [
            'limit' => 1000,
            'next' => 0,
            'dateFrom' => $dateFrom ? strtotime($dateFrom) : strtotime('-30 days'),
        ];

        $response = $client->get('/api/v3/orders', $params);

        if (!$response || !isset($response['orders'])) {
            Log::warning('No orders returned from WB API', [
                'integration_id' => $integration->id,
            ]);
            return ['total' => 0, 'created' => 0, 'updated' => 0];
        }

        $created = 0;
        $updated = 0;

        DB::beginTransaction();
        try {
            foreach ($response['orders'] as $wbOrder) {
                $result = $this->upsertWildberriesPosting($integration, $wbOrder);
                if ($result === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'total' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * Создать или обновить отправление WB
     */
    private function upsertWildberriesPosting(Integration $integration, array $data): string
    {
        $postingNumber = (string) $data['id'];
        
        $existing = Posting::where('integration_id', $integration->id)
            ->where('posting_number', $postingNumber)
            ->first();

        $postingData = [
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
            'posting_number' => $postingNumber,
            'order_id' => (string) ($data['rid'] ?? $data['id']),
            'status' => $this->mapWildberriesStatus($data['status'] ?? 0),
            'external_status' => (string) ($data['status'] ?? 0),
            'shipment_date' => isset($data['deliveryDate']) ? date('Y-m-d H:i:s', strtotime($data['deliveryDate'])) : null,
            'warehouse_id' => (string) ($data['warehouseId'] ?? ''),
            'warehouse_name' => $data['warehouseName'] ?? null,
            'delivery_type' => 'fbs',
            'customer' => [
                'name' => 'Покупатель',
                'address' => $data['address'] ?? null,
            ],
            'total_price' => $data['price'] ?? 0,
            'meta' => [
                'supplyId' => $data['supplyId'] ?? null,
                'nmId' => $data['nmId'] ?? null,
                'chrtId' => $data['chrtId'] ?? null,
                'article' => $data['article'] ?? null,
            ],
            'synced_at' => now(),
        ];

        if ($existing) {
            $existing->update($postingData);
            $posting = $existing;
            $result = 'updated';
        } else {
            $posting = Posting::create($postingData);
            $result = 'created';
        }

        // Создаём товар
        $this->syncWildberriesPostingItems($posting, $data);

        $posting->recalculateTotals();

        return $result;
    }

    /**
     * Синхронизировать товары отправления WB
     */
    private function syncWildberriesPostingItems(Posting $posting, array $data): void
    {
        $posting->items()->delete();

        PostingItem::create([
            'posting_id' => $posting->id,
            'sku' => $data['article'] ?? (string) $data['nmId'],
            'marketplace_sku' => (string) ($data['nmId'] ?? ''),
            'offer_id' => $data['article'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'name' => $data['article'] ?? "Товар #{$data['nmId']}",
            'quantity' => 1,
            'price' => $data['price'] ?? 0,
            'meta' => [
                'chrtId' => $data['chrtId'] ?? null,
                'skus' => $data['skus'] ?? null,
            ],
        ]);
    }

    /**
     * Маппинг статусов WB
     */
    private function mapWildberriesStatus(int $status): string
    {
        return match ($status) {
            0 => Posting::STATUS_AWAITING_PACKAGING,
            1 => Posting::STATUS_AWAITING_DELIVER,
            2 => Posting::STATUS_DELIVERING,
            3 => Posting::STATUS_DELIVERED,
            default => Posting::STATUS_AWAITING_PACKAGING,
        };
    }

    /**
     * Получить статистику отправлений
     */
    public function getStatistics(string $integrationId): array
    {
        $query = Posting::where('integration_id', $integrationId);

        $total = (clone $query)->count();
        
        $byStatus = (clone $query)
            ->select('status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $todayToShip = (clone $query)->toShipToday()->count();
        $overdue = (clone $query)->overdue()->count();

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'today_to_ship' => $todayToShip,
            'overdue' => $overdue,
        ];
    }

    /**
     * Упаковать отправление
     */
    public function pack(Posting $posting, array $products): Posting
    {
        $integration = Integration::findOrFail($posting->integration_id);

        if (!$posting->canPack()) {
            throw new \RuntimeException('Отправление не может быть упаковано в текущем статусе');
        }

        // Вызываем API маркетплейса
        if ($integration->marketplace === 'ozon') {
            $this->packOzonPosting($integration, $posting, $products);
        } elseif ($integration->marketplace === 'wildberries') {
            $this->packWildberriesPosting($integration, $posting);
        }

        $posting->markAsPacked();

        return $posting->fresh(['items']);
    }

    /**
     * Упаковать отправление Ozon
     */
    private function packOzonPosting(Integration $integration, Posting $posting, array $products): void
    {
        $marketplace = OzonMarketplace::fromIntegration($integration);
        $client = $marketplace->getClient();

        // POST /v4/posting/fbs/ship
        $response = $client->post('/v4/posting/fbs/ship', [
            'posting_number' => $posting->posting_number,
            'packages' => [
                [
                    'products' => $products,
                ],
            ],
        ]);

        if (!$response || isset($response['error'])) {
            throw new \RuntimeException('Ошибка упаковки в Ozon: ' . ($response['error']['message'] ?? 'Unknown error'));
        }
    }

    /**
     * Упаковать отправление WB
     */
    private function packWildberriesPosting(Integration $integration, Posting $posting): void
    {
        // WB не требует отдельного вызова для упаковки
        Log::info('WB posting packed locally', ['posting_id' => $posting->id]);
    }

    /**
     * Отгрузить отправление
     */
    public function ship(Posting $posting): Posting
    {
        $integration = Integration::findOrFail($posting->integration_id);

        if (!$posting->canShip()) {
            throw new \RuntimeException('Отправление не может быть отгружено в текущем статусе');
        }

        // Для Ozon отгрузка происходит автоматически после упаковки
        // Для WB нужно передать в поставку

        $posting->markAsShipped();

        return $posting->fresh(['items']);
    }

    /**
     * Отменить отправление
     */
    public function cancel(Posting $posting, int $reasonId, ?string $message = null): Posting
    {
        $integration = Integration::findOrFail($posting->integration_id);

        if (!$posting->canCancel()) {
            throw new \RuntimeException('Отправление не может быть отменено в текущем статусе');
        }

        // Вызываем API маркетплейса
        if ($integration->marketplace === 'ozon') {
            $this->cancelOzonPosting($integration, $posting, $reasonId, $message);
        }

        $posting->markAsCancelled($reasonId, $message);

        return $posting->fresh(['items']);
    }

    /**
     * Отменить отправление Ozon
     */
    private function cancelOzonPosting(Integration $integration, Posting $posting, int $reasonId, ?string $message): void
    {
        $marketplace = OzonMarketplace::fromIntegration($integration);
        $client = $marketplace->getClient();

        // POST /v2/posting/fbs/cancel
        $response = $client->post('/v2/posting/fbs/cancel', [
            'posting_number' => $posting->posting_number,
            'cancel_reason_id' => $reasonId,
            'cancel_reason_message' => $message,
        ]);

        if (!$response || isset($response['error'])) {
            throw new \RuntimeException('Ошибка отмены в Ozon: ' . ($response['error']['message'] ?? 'Unknown error'));
        }
    }

    /**
     * Получить этикетку отправления
     */
    public function getLabel(Posting $posting): array
    {
        $integration = Integration::findOrFail($posting->integration_id);

        if ($integration->marketplace === 'ozon') {
            return $this->getOzonLabel($integration, $posting);
        } elseif ($integration->marketplace === 'wildberries') {
            return $this->getWildberriesLabel($integration, $posting);
        }

        throw new \RuntimeException("Unsupported marketplace: {$integration->marketplace}");
    }

    /**
     * Получить этикетку Ozon
     */
    private function getOzonLabel(Integration $integration, Posting $posting): array
    {
        $marketplace = OzonMarketplace::fromIntegration($integration);
        $client = $marketplace->getClient();

        // POST /v2/posting/fbs/package-label
        $response = $client->post('/v2/posting/fbs/package-label', [
            'posting_number' => [$posting->posting_number],
        ]);

        return [
            'type' => 'pdf',
            'content_base64' => $response['content'] ?? null,
            'url' => $response['url'] ?? null,
        ];
    }

    /**
     * Получить этикетку WB
     */
    private function getWildberriesLabel(Integration $integration, Posting $posting): array
    {
        $marketplace = WildberriesMarketplace::fromIntegration($integration);
        $client = $marketplace->getClient();

        // GET /api/v3/orders/{orderId}/stickers
        $response = $client->get("/api/v3/orders/{$posting->posting_number}/stickers", [
            'type' => 'png',
            'width' => 58,
            'height' => 40,
        ]);

        return [
            'type' => 'png',
            'stickers' => $response['stickers'] ?? [],
        ];
    }

    /**
     * Массовое получение этикеток
     */
    public function getBulkLabels(string $integrationId, array $postingIds): array
    {
        $integration = Integration::findOrFail($integrationId);
        $postings = Posting::whereIn('id', $postingIds)
            ->where('integration_id', $integrationId)
            ->get();

        if ($integration->marketplace === 'ozon') {
            $marketplace = OzonMarketplace::fromIntegration($integration);
            $client = $marketplace->getClient();

            $postingNumbers = $postings->pluck('posting_number')->toArray();

            $response = $client->post('/v2/posting/fbs/package-label', [
                'posting_number' => $postingNumbers,
            ]);

            return [
                'type' => 'pdf',
                'content_base64' => $response['content'] ?? null,
                'url' => $response['url'] ?? null,
            ];
        }

        // Для WB собираем по одной
        $labels = [];
        foreach ($postings as $posting) {
            try {
                $labels[] = $this->getLabel($posting);
            } catch (\Exception $e) {
                Log::warning('Failed to get label', [
                    'posting_id' => $posting->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['labels' => $labels];
    }

    /**
     * Массовая отгрузка
     */
    public function bulkShip(string $integrationId, array $postingIds): array
    {
        $postings = Posting::whereIn('id', $postingIds)
            ->where('integration_id', $integrationId)
            ->get();

        $success = 0;
        $failed = 0;
        $failedIds = [];

        foreach ($postings as $posting) {
            try {
                $this->ship($posting);
                $success++;
            } catch (\Exception $e) {
                $failed++;
                $failedIds[] = $posting->id;
                Log::warning('Bulk ship failed', [
                    'posting_id' => $posting->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success_count' => $success,
            'failed_count' => $failed,
            'failed_ids' => $failedIds,
        ];
    }

    /**
     * Создать акт приёма-передачи
     */
    public function createAct(string $integrationId, string $departureDate): array
    {
        $integration = Integration::findOrFail($integrationId);

        if ($integration->marketplace === 'ozon') {
            $marketplace = OzonMarketplace::fromIntegration($integration);
            $client = $marketplace->getClient();

            // POST /v2/posting/fbs/act/create
            $response = $client->post('/v2/posting/fbs/act/create', [
                'departure_date' => $departureDate,
            ]);

            return [
                'act_id' => $response['id'] ?? null,
            ];
        }

        throw new \RuntimeException("Acts not supported for {$integration->marketplace}");
    }

    /**
     * Скачать акт приёма-передачи
     */
    public function downloadAct(string $integrationId, int $actId): array
    {
        $integration = Integration::findOrFail($integrationId);

        if ($integration->marketplace === 'ozon') {
            $marketplace = OzonMarketplace::fromIntegration($integration);
            $client = $marketplace->getClient();

            // POST /v2/posting/fbs/act/get-pdf
            $response = $client->post('/v2/posting/fbs/act/get-pdf', [
                'id' => $actId,
            ]);

            return [
                'type' => 'pdf',
                'content_base64' => $response['content'] ?? null,
            ];
        }

        throw new \RuntimeException("Acts not supported for {$integration->marketplace}");
    }
}
