<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Read-only assembly queues; customer, address, phone and finance blocks never leave this boundary. */
final class ManagerFbsGateway
{
    public function read(ManagerStore $store, array $window, string $cursor = '', int $limit = 25): array
    {
        if (! in_array($store->marketplace, ['wildberries', 'ozon'], true)) {
            throw new ManagerDataException('Источник FBS не поддерживается.', 422);
        }
        try {
            $client = Http::acceptJson()->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false]);
            if ($store->marketplace === 'wildberries') {
                $response = $client->withHeaders(['Authorization' => $store->apiKey])->get('https://marketplace-api.wildberries.ru/api/v3/orders/new');
                $rows = $response->json('orders');
                if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows)) {
                    throw new ManagerDataException('WB не подтвердил очередь новых заданий. Проверьте доступ магазина к FBS.');
                }
                $items = array_map(fn ($row) => $this->wb($row), $rows);
                usort($items, fn ($a, $b) => [$a['created_at'] ?? '', $a['external_id']] <=> [$b['created_at'] ?? '', $b['external_id']]);
                $this->unique($items);

                return ['items' => $items, 'has_next' => false, 'cursor' => null];
            }
            $response = $client->withHeaders(['Client-Id' => $store->clientId, 'Api-Key' => $store->apiKey])->post('https://api-seller.ozon.ru/v4/posting/fbs/list', [
                'sort_dir' => 'ASC', 'filter' => ['since' => $window['since'], 'to' => $window['to'], 'statuses' => ['awaiting_packaging']],
                'limit' => $limit, 'cursor' => $cursor, 'with' => ['analytics_data' => false, 'financial_data' => false, 'barcodes' => false, 'legal_info' => false, 'translit' => false],
            ]);
            $rows = $response->json('postings');
            $hasNext = $response->json('has_next');
            $next = $response->json('cursor');
            if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows) || ! is_bool($hasNext) || ($hasNext && (! is_string($next) || $next === '' || strlen($next) > 4000))) {
                throw new ManagerDataException('Ozon не подтвердил очередь или её продолжение. Проверьте доступ магазина к FBS.');
            }
            if (count($rows) > $limit) {
                throw new ManagerDataException('Ozon вернул непроверенный размер страницы.');
            }
            $items = array_map(fn ($row) => $this->ozon($row), $rows);
            $this->unique($items);

            return ['items' => $items, 'has_next' => $hasNext, 'cursor' => $hasNext ? $next : null];
        } catch (ManagerDataException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ManagerDataException('Очередь сборки временно недоступна. Данные не получены.');
        }
    }

    private function wb(mixed $row): array
    {
        if (! is_array($row)) {
            throw new ManagerDataException('Непроверенный состав задания WB.');
        }

        return ['external_id' => $this->id($row['id'] ?? null), 'vendor_state' => 'new', 'state_basis' => 'new_orders_endpoint',
            'created_at' => $this->date($row['createdAt'] ?? null), 'shipment_deadline' => null,
            'recommended_delivery_date' => $this->wbRecommendedDate($row['sellerDate'] ?? null),
            'warehouse_id' => isset($row['warehouseId']) ? $this->id($row['warehouseId']) : null,
            'delivery_type' => is_string($row['deliveryType'] ?? null) ? $row['deliveryType'] : null,
            'cargo_type' => is_int($row['cargoType'] ?? null) ? $row['cargoType'] : null,
            'cross_border_type' => in_array($row['crossBorderType'] ?? null, [0, 1], true) ? $row['crossBorderType'] : null,
            'is_b2b' => is_bool($row['options']['isB2B'] ?? null) ? $row['options']['isB2B'] : null,
            'items' => [['marketplace_product_id' => isset($row['nmId']) ? $this->id($row['nmId']) : null,
                'offer_id' => is_string($row['article'] ?? null) ? $row['article'] : null,
                'barcodes' => $this->strings($row['skus'] ?? null), 'quantity' => null]],
            'required_metadata' => $this->strings($row['requiredMeta'] ?? null), 'optional_metadata' => $this->strings($row['optionalMeta'] ?? null),
            'physical_status' => null];
    }

    private function ozon(mixed $row, bool $queueOnly = true): array
    {
        if (! is_array($row) || ! is_string($row['posting_number'] ?? null) || $row['posting_number'] === '' || strlen($row['posting_number']) > 100
            || ! is_string($row['status'] ?? null) || $row['status'] === '' || ($queueOnly && $row['status'] !== 'awaiting_packaging')
            || ! is_array($row['products'] ?? null) || ! array_is_list($row['products']) || ! $row['products']) {
            throw new ManagerDataException('Ozon не подтвердил состав или состояние отправления.');
        }
        $items = [];
        foreach ($row['products'] as $item) {
            if (! is_array($item) || ! is_int($item['quantity'] ?? null) || $item['quantity'] <= 0 || $item['quantity'] > 2147483647) {
                throw new ManagerDataException('Не подтверждено количество товара Ozon.');
            }
            $items[] = ['marketplace_product_id' => $this->id($item['sku'] ?? null),
                'offer_id' => is_string($item['offer_id'] ?? null) ? $item['offer_id'] : null,
                'name' => is_string($item['name'] ?? null) ? $item['name'] : null, 'quantity' => $item['quantity']];
        }
        $requirements = null;
        if (is_array($row['requirements'] ?? null)) {
            $requirements = [];
            foreach (['products_requiring_gtd', 'products_requiring_jw_uin', 'products_requiring_country', 'products_requiring_rnpt', 'products_requiring_mandatory_mark', 'products_requiring_change_country', 'products_requiring_imei', 'products_requiring_weight'] as $field) {
                if (array_key_exists($field, $row['requirements'])) {
                    $requirements[$field] = $this->ids($row['requirements'][$field]);
                }
            }
        }

        return ['external_id' => $row['posting_number'], 'vendor_state' => $row['status'], 'substatus' => is_string($row['substatus'] ?? null) ? $row['substatus'] : null,
            'in_process_at' => $this->date($row['in_process_at'] ?? null), 'shipment_deadline' => $this->date($row['shipment_date'] ?? null),
            'warehouse_id' => isset($row['delivery_method']['warehouse_id']) ? $this->id($row['delivery_method']['warehouse_id']) : null,
            'warehouse_name' => is_string($row['delivery_method']['warehouse'] ?? null) ? $row['delivery_method']['warehouse'] : null,
            'is_express' => is_bool($row['is_express'] ?? null) ? $row['is_express'] : null,
            'is_multibox' => is_bool($row['is_multibox'] ?? null) ? $row['is_multibox'] : null,
            'available_actions' => $this->strings($row['available_actions'] ?? null),
            'integration_type_flow' => is_string($row['integration_type_flow'] ?? null) ? $row['integration_type_flow'] : null,
            'items' => $items, 'requirements' => $requirements === null ? null : (object) $requirements, 'physical_status' => null];
    }

    private function id(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value)) {
            throw new ManagerDataException('Источник не подтвердил идентификатор задания или товара.');
        }

        return (string) $value;
    }

    public function ozonPosting(ManagerStore $store, string $externalId, bool $cancellationEvidence = false): array
    {
        if ($store->marketplace !== 'ozon' || ! preg_match('/^[0-9]+(?:-[0-9]+){1,4}$/D', $externalId)) {
            throw new ManagerDataException('Нужен точный номер отправления Ozon.', 422);
        }
        try {
            $response = $this->client($store)->post('https://api-seller.ozon.ru/v3/posting/fbs/get', [
                'posting_number' => $externalId,
                'with' => ['analytics_data' => false, 'barcodes' => false, 'financial_data' => false, 'legal_info' => false, 'product_exemplars' => false, 'related_postings' => false, 'translit' => false],
            ]);
            $raw = $response->json('result');
            if (! $response->successful() || ! is_array($raw) || ($raw['posting_number'] ?? '') !== $externalId) {
                throw new ManagerDataException('Ozon не подтвердил выбранное отправление.');
            }
            $posting = $this->ozon($raw, false);
            if (array_key_exists('order_id', $raw)) $posting['order_id'] = (string) $this->integerId($this->id($raw['order_id']));
            if (array_key_exists('parent_posting_number', $raw)) {
                $parent = $raw['parent_posting_number'];
                if ($parent !== null && (! is_string($parent) || strlen($parent) > 100 || $parent !== '' && ! preg_match('/^[0-9]+(?:-[0-9]+){1,4}$/D', $parent))) throw new ManagerDataException('Ozon не подтвердил родительское отправление.');
                $posting['parent_posting_number'] = $parent;
            }
            usort($posting['items'], fn ($a, $b) => $a['marketplace_product_id'] <=> $b['marketplace_product_id']);
            $this->uniqueProducts($posting['items']);
            $blockers = [];
            foreach (['is_express', 'is_multibox'] as $flag) {
                if (($raw[$flag] ?? null) !== false) {
                    $blockers[] = $flag.'_requires_manual_review';
                }
            }
            if (($raw['integration_type_flow'] ?? null) !== 'ozon') {
                $blockers[] = 'delivery_flow_requires_manual_review';
            }
            if (! in_array('ship', $raw['available_actions'] ?? [], true)) {
                $blockers[] = 'ship_not_available';
            }
            if (array_intersect($raw['available_actions'] ?? [], ['ship_with_additional_info', 'has_weight_products', 'set_cutoff', 'ship_async_in_process', 'ship_async_retry'])) {
                $blockers[] = 'additional_steps_require_manual_review';
            }
            if (($raw['require_blr_traceable_attrs'] ?? null) !== false) {
                $blockers[] = 'traceability_attributes_require_manual_review';
            }
            $fields = ['products_requiring_gtd', 'products_requiring_jw_uin', 'products_requiring_country', 'products_requiring_rnpt', 'products_requiring_mandatory_mark', 'products_requiring_change_country', 'products_requiring_imei', 'products_requiring_weight'];
            foreach ($fields as $field) {
                if (($raw['requirements'][$field] ?? null) !== []) {
                    $blockers[] = $field;
                }
            }
            if (! is_array($raw['requirements'] ?? null) || array_diff(array_keys($raw['requirements']), $fields)) {
                $blockers[] = 'requirements_not_fully_known';
            }
            foreach ($raw['products'] as $item) {
                foreach (['has_imei', 'is_weight_needed', 'is_blr_traceable', 'is_marketplace_buyout'] as $flag) {
                    if (($item[$flag] ?? null) !== false) {
                        $blockers[] = 'product_'.$flag.'_requires_manual_review';
                    }
                }
                // Exemplar values are never overwritten by this command. Completeness is
                // confirmed by explicit requirements/flags, not by deprecated mark arrays.
            }

            return ['posting' => $posting, 'pack_blockers' => array_values(array_unique($blockers)),
                ...($cancellationEvidence ? ['cancellation_reason_id' => isset($raw['cancellation']['cancel_reason_id']) && (is_int($raw['cancellation']['cancel_reason_id']) || is_string($raw['cancellation']['cancel_reason_id'])) && preg_match('/^(0|[1-9][0-9]{0,18})$/D', (string) $raw['cancellation']['cancel_reason_id']) ? (string) $raw['cancellation']['cancel_reason_id'] : null] : [])];
        } catch (ManagerDataException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ManagerDataException('Не удалось проверить отправление Ozon.');
        }
    }

    public function wbSupply(ManagerStore $store, string $supplyId): array
    {
        if ($store->marketplace !== 'wildberries' || ! preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $supplyId)) {
            throw new ManagerDataException('Нужен точный ID поставки WB.', 422);
        }
        try {
            $response = $this->client($store)->get('https://marketplace-api.wildberries.ru/api/v3/supplies/'.rawurlencode($supplyId));
            $row = $response->json();
            if (! $response->successful() || ! is_array($row) || ($row['id'] ?? '') !== $supplyId || ! is_bool($row['done'] ?? null)) {
                throw new ManagerDataException('WB не подтвердил выбранную поставку.');
            }
            $response = $this->client($store)->get('https://marketplace-api.wildberries.ru/api/marketplace/v3/supplies/'.rawurlencode($supplyId).'/order-ids');
            $ids = $response->json('orderIds');
            if (! $response->successful() || ! is_array($ids) || ! array_is_list($ids)) {
                throw new ManagerDataException('WB не подтвердил состав поставки.');
            }
            $ids = $this->ids($ids);
            sort($ids, SORT_STRING);
            if (count($ids) !== count(array_unique($ids))) {
                throw new ManagerDataException('WB вернул повторяющиеся задания поставки.');
            }

            return ['id' => $supplyId, 'done' => $row['done'], 'cargo_type' => in_array($row['cargoType'] ?? null, [1, 2, 3], true) ? $row['cargoType'] : null,
                'cross_border_type' => in_array($row['crossBorderType'] ?? null, [0, 1], true) ? $row['crossBorderType'] : null,
                'is_b2b' => is_bool($row['isB2b'] ?? null) ? $row['isB2b'] : null, 'order_ids' => $ids];
        } catch (ManagerDataException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ManagerDataException('Не удалось проверить поставку WB.');
        }
    }

    public function wbOrderDetails(ManagerStore $store, string $externalId, array $window): array
    {
        $this->integerId($externalId);
        if ($store->marketplace !== 'wildberries') throw new ManagerDataException('Нужен магазин WB.', 422);
        $next = 0; $seen = [];
        for ($page = 0; $page < 100; $page++) {
            try {
                $response = $this->client($store)->get('https://marketplace-api.wildberries.ru/api/v3/orders', ['limit' => 1000, 'next' => $next,
                    'dateFrom' => \Carbon\CarbonImmutable::parse($window['date_from'], 'UTC')->startOfDay()->timestamp,
                    'dateTo' => min(\Carbon\CarbonImmutable::parse($window['date_to'], 'UTC')->endOfDay()->timestamp, now()->utc()->timestamp)]);
                $rows = $response->json('orders'); $cursor = $response->json('next');
                if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows) || count($rows) > 1000 || ! is_int($cursor) || $cursor < 0) throw new \RuntimeException;
                $matches = array_values(array_filter($rows, fn ($row) => is_array($row) && (string) ($row['id'] ?? '') === $externalId));
                if (count($matches) > 1) throw new \RuntimeException;
                if ($matches) return $this->wb($matches[0]);
                if (count($rows) < 1000) break;
                if ($cursor <= $next || isset($seen[$cursor])) throw new \RuntimeException;
                $seen[$cursor] = true; $next = $cursor;
            } catch (ManagerDataException $error) { throw $error; }
            catch (\Throwable) { throw new ManagerDataException('WB не подтвердил данные или продолжение списка заданий.'); }
        }
        throw new ManagerDataException('Задание WB не найдено в выбранном периоде или превышен предел чтения. Уточните даты, не отправляя отмену.', 422);
    }

    public function wbOrderState(ManagerStore $store, string $externalId): string
    {
        $this->id($externalId);
        try {
            $response = $this->client($store)->post('https://marketplace-api.wildberries.ru/api/v3/orders/status', ['orders' => [$this->integerId($externalId)]]);
            $rows = $response->json('orders');
            if (! $response->successful() || ! is_array($rows) || count($rows) !== 1 || (string) ($rows[0]['id'] ?? '') !== $externalId
                || ! in_array($rows[0]['supplierStatus'] ?? null, ['new', 'confirm', 'complete', 'cancel'], true)) {
                throw new ManagerDataException('WB не подтвердил состояние задания.');
            }

            return $rows[0]['supplierStatus'];
        } catch (ManagerDataException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ManagerDataException('Не удалось прочитать состояние задания WB.');
        }
    }

    /** One attempt only. An ambiguous response keeps the operation locked. */
    public function submit(ManagerStore $store, array $plan): array
    {
        try {
            return app(ManagerFbsExecutionPolicy::class)->dispatch($store, $plan['_execution_control'] ?? null, fn () => $this->submitNative($store, $plan));
        } catch (ManagerFbsExecutionStopped|ManagerDataException $e) {
            return ['status' => 'failed', 'acknowledged' => false, 'stopped_before_native' => true, 'control_blocked' => true];
        } catch (\Throwable) {
            return ['status' => 'unknown', 'acknowledged' => false];
        }
    }

    private function submitNative(ManagerStore $store, array $plan): array
    {
        try {
            if ($plan['operation_type'] === 'fbs_cancel') {
                if (! config('services.manager.fbs_cancellations_enabled', false) || ! config('services.manager.fbs_writes_enabled', true)) return ['status' => 'failed', 'acknowledged' => false];
                if ($store->marketplace === 'wildberries') {
                    $this->integerId($plan['external_id']);
                    $response = $this->client($store)->withBody('', 'application/json')->patch('https://marketplace-api.wildberries.ru/api/v3/orders/'.rawurlencode($plan['external_id']).'/cancel');
                    $accepted = $response->status() === 204;
                } elseif ($store->marketplace === 'ozon') {
                    $reason = $this->integerId($plan['cancellation']['reason_id']);
                    $response = $this->client($store)->post('https://api-seller.ozon.ru/v2/posting/fbs/cancel', ['posting_number' => $plan['external_id'], 'cancel_reason_id' => $reason, 'cancel_reason_message' => $plan['cancellation']['comment']]);
                    $accepted = $response->status() === 200 && $response->json('result') === true;
                    if ($response->status() === 200 && $response->json('result') === false) return ['status' => 'failed', 'acknowledged' => false];
                } else { throw new \RuntimeException; }
            } elseif ($plan['operation_type'] === 'fbs_pack' && $store->marketplace === 'ozon') {
                $response = $this->client($store)->post('https://api-seller.ozon.ru/v4/posting/fbs/ship', [
                    'posting_number' => $plan['external_id'], 'packages' => [['products' => array_map(fn ($p) => ['product_id' => $this->integerId($p['marketplace_product_id']), 'quantity' => $p['quantity']], $plan['before']['items'])]],
                    'with' => ['additional_data' => false],
                ]);
                $ids = $response->json('result');
                $accepted = $response->status() === 200 && $ids === [$plan['external_id']];
            } elseif ($plan['operation_type'] === 'fbs_assign' && $store->marketplace === 'wildberries') {
                $response = $this->client($store)->patch('https://marketplace-api.wildberries.ru/api/marketplace/v3/supplies/'.rawurlencode($plan['supply_id']).'/orders', ['orders' => [$this->integerId($plan['external_id'])]]);
                $accepted = $response->status() === 204;
            } else {
                throw new \RuntimeException;
            }
            if ($accepted) {
                return ['status' => 'verifying', 'acknowledged' => true];
            }
            $definite = in_array($response->status(), $plan['operation_type'] === 'fbs_cancel' ? [400, 401, 402, 403, 404, 409, 422] : [400, 401, 403, 404, 422], true);

            return ['status' => $definite ? 'failed' : 'unknown', 'acknowledged' => false];
        } catch (\Throwable) {
            return ['status' => 'unknown', 'acknowledged' => false];
        }
    }

    private function client(ManagerStore $store): PendingRequest
    {
        $client = Http::acceptJson()->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false]);

        return $store->marketplace === 'wildberries' ? $client->withHeaders(['Authorization' => $store->apiKey]) : $client->withHeaders(['Client-Id' => $store->clientId, 'Api-Key' => $store->apiKey]);
    }

    private function integerId(string $id): int
    {
        $this->id($id);
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new ManagerDataException('ID не помещается в точный целочисленный контракт.', 422);
        }

        return $value;
    }

    private function uniqueProducts(array $items): void
    {
        $ids = array_column($items, 'marketplace_product_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new ManagerDataException('Ozon вернул неоднозначный состав товаров.');
        }
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,9})?(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            throw new ManagerDataException('Источник вернул непроверенную дату задания.');
        }

        return $value;
    }

    private function wbRecommendedDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^([0-9]{2})\.([0-9]{2})\.([0-9]{4})$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            throw new ManagerDataException('WB не подтвердил рекомендованную дату доставки.');
        }

        return $parts[3].'-'.$parts[2].'-'.$parts[1];
    }

    private function strings(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn ($v) => ! is_string($v))) {
            throw new ManagerDataException('Непроверенные метаданные задания.');
        }

        return $value;
    }

    private function ids(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ManagerDataException('Непроверенные требования к товарам.');
        }

        return array_map(fn ($id) => $this->id($id), $value);
    }

    private function unique(array $items): void
    {
        $ids = array_column($items, 'external_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new ManagerDataException('Источник вернул повторяющиеся задания.');
        }
    }
}
