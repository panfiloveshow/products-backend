<?php

namespace App\Services;

use App\Domains\Ozon\OzonMarketplace;
use App\Domains\Wildberries\WildberriesMarketplace;
use App\Models\Integration;
use App\Models\Posting;
use App\Models\PostingItem;
use App\Models\Product;
use Illuminate\Support\Carbon;
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
    // Overlap-окно к водяному знаку: перекрываем последние дни, чтобы поймать
    // поздние апдейты/смены статуса постингов, созданных до прошлого синка.
    private const OZON_POSTINGS_OVERLAP_DAYS = 5;

    // Бэкфилл при первом прогоне (нет водяного знака), если явный dateFrom не задан.
    private const OZON_POSTINGS_BACKFILL_DAYS = 90;

    private function syncOzonPostings(Integration $integration, ?string $status, ?string $dateFrom): array
    {
        $marketplace = OzonMarketplace::fromIntegration($integration);

        // Инкрементальный синк: тянем только постинги свежее водяного знака (минус
        // overlap), а не всё окно каждый раз. Первый прогон / сброс знака → полный
        // бэкфилл ($dateFrom или BACKFILL_DAYS). Статусы старых постингов догоняет
        // refreshInFlightOzonPostings отдельно. Это срезает ~21000 постингов до
        // сотен и снимает риск MAX_OFFSET_EXCEEDED offset-пагинации.
        // ponytail: per-row upsert оставлен — при инкрементальном окне это сотни
        // строк (~секунды); bulk Posting::upsert() имеет смысл, только если батчи
        // снова вырастут (крупный бэкфилл).
        $hadWatermark = $integration->ozon_postings_synced_until !== null;
        $sinceDate = $this->ozonPostingsSince($integration->ozon_postings_synced_until, $dateFrom);

        // Момент старта фиксируем ДО фетча — станет новым знаком, чтобы не пропустить
        // постинги, созданные во время самого синка.
        $syncStartedAt = now();
        $since = $sinceDate->format('c');
        $to = $syncStartedAt->format('c');

        $fbs = $this->syncOzonFbsPostings($integration, $marketplace, $status, $since, $to);
        $fbo = $this->syncOzonFboPostings($integration, $marketplace, $since, $to);

        // Продвигаем знак ТОЛЬКО после успеха обоих потоков (исключение выше не даст
        // сюда дойти → следующий прогон переберёт то же окно, upsert идемпотентен).
        // Отдельная колонка, а не settings JSON: команда перезаписывает settings
        // (localization/premium) и затёрла бы знак.
        $integration->forceFill(['ozon_postings_synced_until' => $syncStartedAt])->save();

        Log::info('Ozon postings synced', [
            'integration_id' => $integration->id,
            'incremental' => $hadWatermark,
            'since' => $since,
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

    /**
     * Нижняя граница окна синка постингов Ozon.
     * Есть водяной знак → знак − overlap (инкрементально, ловим поздние апдейты).
     * Нет знака → полный бэкфилл ($dateFrom или BACKFILL_DAYS).
     */
    private function ozonPostingsSince(?Carbon $watermark, ?string $dateFrom): Carbon
    {
        if ($watermark !== null) {
            return $watermark->copy()->subDays(self::OZON_POSTINGS_OVERLAP_DAYS);
        }

        return $dateFrom
            ? Carbon::parse($dateFrom)
            : now()->subDays(self::OZON_POSTINGS_BACKFILL_DAYS);
    }

    private function syncOzonFbsPostings(Integration $integration, OzonMarketplace $marketplace, ?string $status, string $since, string $to): array
    {
        $ozonStatus = match ($status) {
            'awaiting_packaging', 'awaiting_deliver', 'delivering', 'delivered', 'cancelled' => $status,
            default => null,
        };

        // /v3/posting/fbs/list отключён 31.08.2026 → /v4: cursor, filter.statuses[], sort_dir.
        return $this->syncOzonPostingPages($integration, $marketplace, '/v4/posting/fbs/list', 'fbs', array_filter([
            'since' => $since,
            'to' => $to,
            'statuses' => $ozonStatus ? [$ozonStatus] : null,
        ]), [
            'analytics_data' => true,
            'barcodes' => true,
            'financial_data' => true,
        ]);
    }

    private function syncOzonFboPostings(Integration $integration, OzonMarketplace $marketplace, string $since, string $to): array
    {
        // FBO v2 отключён 31.08.2026 → /v3. «Пустой result» у v3 в июле
        // был не поломкой: у v3 нет обёртки result, postings лежат в корне ответа.
        return $this->syncOzonPostingPages($integration, $marketplace, '/v3/posting/fbo/list', 'fbo', [
            'since' => $since,
            'to' => $to,
        ], [
            'analytics_data' => true,
            'financial_data' => true,
        ]);
    }

    /**
     * Курсорная пагинация /v4/posting/fbs/list и /v3/posting/fbo/list (limit ≤ 100,
     * ответ {postings, cursor, has_next} без обёртки result).
     */
    private function syncOzonPostingPages(Integration $integration, OzonMarketplace $marketplace, string $endpoint, string $deliveryType, array $filter, array $with): array
    {
        $created = 0;
        $updated = 0;
        $cursor = '';

        do {
            $body = ['filter' => $filter, 'limit' => 100, 'sort_dir' => 'ASC', 'with' => $with];
            if ($cursor !== '') {
                $body['cursor'] = $cursor;
            }

            $response = $marketplace->getClient()->post($endpoint, $body);

            // null (исключение/429) и _error (4xx/5xx, в т.ч. «метод отключён») — бросаем,
            // чтобы НЕ продвинуть водяной знак: пустая страница вместо ошибки и была
            // причиной «тихой смерти» постингов.
            if ($response === null || ! empty($response['_error'])) {
                throw new \RuntimeException("Ozon {$endpoint}: ошибка API (HTTP " . ($response['_http_status'] ?? '—') . ')');
            }

            $postings = $response['postings'] ?? $response['result']['postings'] ?? [];
            $postings = is_array($postings) ? $postings : [];

            DB::beginTransaction();
            try {
                foreach ($postings as $ozonPosting) {
                    $result = $this->upsertOzonPosting($integration, $ozonPosting, $deliveryType);
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

            $nextCursor = (string) ($response['cursor'] ?? '');
            // Защита от зацикливания: курсор не сдвинулся — дальше идти некуда.
            $hasNext = ! empty($response['has_next']) && $nextCursor !== '' && $nextCursor !== $cursor;
            $cursor = $nextCursor;
        } while ($hasNext);

        return [
            'total' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * Сумма Ozon: число, строка или объект {amount, currency} (v3/v4 posting list).
     */
    private function ozonAmount(mixed $value): float
    {
        if (is_array($value)) {
            $value = $value['amount'] ?? 0;
        }

        return is_numeric($value) ? (float) $value : 0.0;
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
            'total_price' => collect($data['products'] ?? [])->sum(fn($p) => $this->ozonAmount($p['price'] ?? 0) * ($p['quantity'] ?? 1)),
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
                'price' => $this->ozonAmount($product['price'] ?? 0),
                'commission_amount' => $product['commission_amount'] ?? null,
                'commission_percent' => $product['commission_percent'] ?? null,
                'payout' => $product['payout'] ?? null,
                'weight' => $product['weight'] ?? null,
                'volume' => $product['volume'] ?? null,
                'meta' => [
                    'delivery_type' => $deliveryType,
                    'currency_code' => $product['price']['currency'] ?? $product['currency_code'] ?? 'RUB',
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

    private const WB_ORDERS_LIMIT = 1000;

    /**
     * Синхронизация отправлений Wildberries
     *
     * GET /api/v3/orders: с 21.07.2026 отдаёт только задания моложе 3 месяцев,
     * период dateFrom–dateTo — не больше 30 дней за запрос, пагинация limit ≤ 1000
     * + next из ответа. Архив (/api/marketplace/v3/fbs/orders/archive) не грузим:
     * синк отправлений работает с недавними заданиями.
     */
    private function syncWildberriesPostings(Integration $integration, ?string $status, ?string $dateFrom): array
    {
        $marketplace = WildberriesMarketplace::fromIntegration($integration);
        $client = $marketplace->getClient();

        $now = now();
        $oldest = $now->copy()->subMonths(3)->addDay(); // запас на сутки до границы WB
        $from = $dateFrom ? Carbon::parse($dateFrom) : $now->copy()->subDays(30);
        if ($from->lt($oldest)) {
            Log::info('WB postings: dateFrom старше 3 месяцев обрезан, архив WB не загружаем', [
                'integration_id' => $integration->id,
                'date_from' => $from->toDateTimeString(),
            ]);
            $from = $oldest;
        }

        $orders = [];
        for ($windowFrom = $from->copy(); $windowFrom->lt($now); $windowFrom = $windowTo) {
            $windowTo = $windowFrom->copy()->addDays(30);
            if ($windowTo->gt($now)) {
                $windowTo = $now->copy();
            }

            $next = 0;
            for ($page = 0; $page < 100; $page++) {
                $response = $client->get('/api/v3/orders', [
                    'limit' => self::WB_ORDERS_LIMIT,
                    'next' => $next,
                    'dateFrom' => $windowFrom->timestamp,
                    'dateTo' => $windowTo->timestamp,
                ]);

                // Ошибку API не выдаём за «заданий нет»
                if (! is_array($response) || ! isset($response['orders'])) {
                    throw new \RuntimeException('WB GET /api/v3/orders: ошибка API (HTTP '.($client->getLastResponseStatus() ?? 'нет ответа').')');
                }

                array_push($orders, ...$response['orders']);

                $prev = $next;
                $next = (int) ($response['next'] ?? 0);
                if (count($response['orders']) < self::WB_ORDERS_LIMIT || $next === 0 || $next === $prev) {
                    break;
                }
            }
        }

        $created = 0;
        $updated = 0;

        DB::beginTransaction();
        try {
            foreach ($orders as $wbOrder) {
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

        return $this->fetchOzonPackageLabel($marketplace->getClient(), [$posting->posting_number]);
    }

    private const OZON_LABEL_POLL_ATTEMPTS = 10;

    /**
     * Этикетки FBS: /v2/posting/fbs/package-label отключается 02.11.2026 →
     * асинхронно /v3/posting/fbs/package-label/create (задания) и
     * /v2/posting/fbs/package-label/get (статус + file_url). Параметра scanit
     * у этих методов нет: штрихкод scanit — поле отправления (/v4/posting/fbs/list).
     */
    private function fetchOzonPackageLabel(\App\Domains\Ozon\Api\OzonClient $client, array $postingNumbers): array
    {
        if ($postingNumbers === []) {
            throw new \RuntimeException('Отправления для печати этикеток не найдены');
        }

        $created = $client->post('/v3/posting/fbs/package-label/create', [
            'posting_numbers' => array_values($postingNumbers),
        ]);
        if (! is_array($created) || ! empty($created['_error'])) {
            throw new \RuntimeException('Ozon не создал задание на этикетки: '.($created['message'] ?? $created['error']['message'] ?? 'нет ответа'));
        }

        // Обычная этикетка — big_label; small_label берём, только если другой нет.
        $tasks = collect($created['tasks'] ?? []);
        $taskId = (int) (($tasks->firstWhere('task_type', 'big_label') ?? $tasks->first())['task_id'] ?? 0);
        if ($taskId <= 0) {
            throw new \RuntimeException('Ozon не вернул задание на этикетки');
        }

        for ($attempt = 1; $attempt <= self::OZON_LABEL_POLL_ATTEMPTS; $attempt++) {
            $label = $client->post('/v2/posting/fbs/package-label/get', ['task_id' => $taskId]);
            if (! is_array($label) || ! empty($label['_error'])) {
                throw new \RuntimeException('Ozon: ошибка получения этикеток: '.($label['message'] ?? $label['error']['message'] ?? 'нет ответа'));
            }

            $status = $label['status']['code'] ?? null;
            if ($status === 'completed' && ! empty($label['file_url'])) {
                return [
                    'type' => 'pdf',
                    'content_base64' => null,
                    'url' => $label['file_url'],
                    'task_id' => $taskId,
                    'unprinted_postings' => $label['status']['unprinted_postings'] ?? [],
                ];
            }
            if ($status === 'error' || $status === 'completed') {
                $reasons = collect($label['status']['unprinted_postings'] ?? [])
                    ->map(fn ($p) => trim(($p['posting_number'] ?? '').': '.($p['message'] ?? '')))
                    ->implode('; ');
                throw new \RuntimeException('Ozon не сформировал этикетки: '.($label['error']['message'] ?? ($reasons !== '' ? $reasons : 'файл не получен')));
            }

            if ($attempt < self::OZON_LABEL_POLL_ATTEMPTS) {
                \Illuminate\Support\Sleep::for(1)->second();
            }
        }

        throw new \RuntimeException("Этикетки ещё формируются (задание {$taskId}), повторите через несколько секунд");
    }

    /**
     * Получить этикетку WB
     *
     * POST /api/v3/orders/stickers?type=png&width=58&height=40, тело {"orders":[id]}
     * (стикеры есть только у заданий в статусах confirm/complete).
     */
    private function getWildberriesLabel(Integration $integration, Posting $posting): array
    {
        $marketplace = WildberriesMarketplace::fromIntegration($integration);
        $client = $marketplace->getClient();

        $response = $client->post('/api/v3/orders/stickers?type=png&width=58&height=40', [
            'orders' => [(int) $posting->posting_number],
        ]);

        if ($response === null) {
            $status = $client->getLastResponseStatus();
            // 409 CustomsDeclarationIsRequired (с 18.08.2026): без номера ДТ стикер не выдаётся.
            if ($status === 409) {
                throw new \RuntimeException('WB не выдаёт стикер: к сборочному заданию не привязан обязательный номер декларации на товары (ДТ). Укажите ДТ в кабинете WB и повторите.');
            }

            throw new \RuntimeException('WB не вернул стикер сборочного задания (HTTP '.($status ?? 'нет ответа').')');
        }

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

            return $this->fetchOzonPackageLabel(
                $marketplace->getClient(),
                $postings->pluck('posting_number')->toArray()
            );
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

            // /v2/posting/fbs/act/create отключён 07.09.2026 → /v1/carriage/create
            // (отгрузка из всех «Готов к отгрузке») + /v1/carriage/approve.
            $created = $client->post('/v1/carriage/create', [
                'departure_date' => Carbon::parse($departureDate)->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
            $carriageId = (int) (is_array($created) ? ($created['carriage_id'] ?? 0) : 0);
            if ($carriageId <= 0 || ! empty($created['_error'])) {
                throw new \RuntimeException('Ozon не создал отгрузку: '.($created['message'] ?? $created['error']['message'] ?? 'нет ответа'));
            }

            $approved = $client->post('/v1/carriage/approve', ['carriage_id' => $carriageId]);
            if (! is_array($approved) || ! empty($approved['_error'])) {
                throw new \RuntimeException("Отгрузка {$carriageId} создана, но не подтверждена: ".($approved['message'] ?? $approved['error']['message'] ?? 'нет ответа'));
            }

            // Идентификатор перевозки — он же id для /v2/posting/fbs/act/check-status и get-pdf.
            return [
                'act_id' => $carriageId,
                'carriage_id' => $carriageId,
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

    /**
     * Освежить статусы «в пути» постингов Ozon за окно $windowDays.
     *
     * Ozon-виджет «Выкупы за 28 дней» иногда уже считает delivering-заказ
     * выкупленным, хотя в /v3/posting/fbo/list он ещё delivering. Чтобы
     * максимально приблизиться к виджету, перед расчётом выкупа точечно
     * перезапрашиваем каждый висящий постинг через /v2/posting/fbo/get
     * (или /v3/posting/fbs/get) и обновляем статус.
     *
     * Ограничение $limit — чтобы один кривой магазин не съел всё время синка.
     *
     * @return array{refreshed:int, changed:int, skipped:int, errors:int}
     */
    public function refreshInFlightOzonPostings(Integration $integration, int $windowDays = 28, int $limit = 200): array
    {
        if ($integration->marketplace !== 'ozon') {
            return ['refreshed' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $dateTo = now()->subDay()->endOfDay();
        $dateFrom = (clone $dateTo)->subDays($windowDays - 1)->startOfDay();

        $inFlightStatuses = [
            Posting::STATUS_DELIVERING,
            Posting::STATUS_AWAITING_DELIVER,
            Posting::STATUS_AWAITING_PACKAGING,
        ];

        $inFlight = Posting::where('integration_id', $integration->id)
            ->where('marketplace', 'ozon')
            ->whereIn('status', $inFlightStatuses)
            ->whereBetween('in_process_at', [$dateFrom, $dateTo])
            ->orderByDesc('in_process_at')
            ->limit($limit)
            ->get(['id', 'posting_number', 'delivery_type', 'status']);

        if ($inFlight->isEmpty()) {
            return ['refreshed' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $marketplace = OzonMarketplace::fromIntegration($integration);
        $fbo = $marketplace->fboPostings();
        $fbs = $marketplace->fbsPostings();

        $refreshed = 0;
        $changed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($inFlight as $posting) {
            $deliveryType = $posting->delivery_type === 'fbs' ? 'fbs' : 'fbo';
            $previousStatus = $posting->status;

            try {
                $data = $deliveryType === 'fbs'
                    ? $fbs->get($posting->posting_number)
                    : $fbo->get($posting->posting_number);
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('refreshInFlightOzonPostings: API error', [
                    'integration_id' => $integration->id,
                    'posting_number' => $posting->posting_number,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (empty($data) || ! isset($data['status'])) {
                $skipped++;
                continue;
            }

            try {
                $this->upsertOzonPosting($integration, $data, $deliveryType);
                $refreshed++;
                $newStatus = $this->mapOzonStatus($data['status']);
                if ($newStatus !== $previousStatus) {
                    $changed++;
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('refreshInFlightOzonPostings: upsert error', [
                    'integration_id' => $integration->id,
                    'posting_number' => $posting->posting_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('refreshInFlightOzonPostings: done', [
            'integration_id' => $integration->id,
            'window_days' => $windowDays,
            'total' => $inFlight->count(),
            'refreshed' => $refreshed,
            'changed' => $changed,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return compact('refreshed', 'changed', 'skipped', 'errors');
    }
}
