<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Support\Facades\Http;

/** Fixed Seller API routes. Each submit performs exactly one native request. */
final class ManagerWbSupplyGateway
{
    public const TYPES = ['fbs_wb_supply_create', 'fbs_wb_supply_assign', 'fbs_wb_supply_shipping', 'fbs_wb_supply_boxes', 'fbs_wb_supply_deliver'];
    public const KEYS = ['operation_type', 'supply_id', 'name', 'order_ids', 'date_from', 'date_to', 'city', 'office_types', 'shipping_date', 'shipping_point_id', 'shipping_type', 'box_count'];
    private const ROOT = 'https://marketplace-api.wildberries.ru';

    public function selection(array $v): array
    {
        try { return $this->parseSelection($v); }
        catch (\Throwable) { throw new ManagerDataException('Нужны точные поддерживаемые параметры одного шага WB.', 422); }
    }
    private function parseSelection(array $v): array
    {
        if (count($v) !== count(self::KEYS) || array_diff(array_keys($v), self::KEYS) || ! in_array($v['operation_type'] ?? null, self::TYPES, true)) $this->bad(422);
        $kind = substr($v['operation_type'], strlen('fbs_wb_supply_'));
        $allowed = ['operation_type', ...($kind === 'create' ? ['name'] : ['supply_id', 'date_from', 'date_to']), ...match ($kind) {
            'assign' => ['order_ids'], 'shipping' => ['city', 'office_types', 'shipping_date', 'shipping_point_id', 'shipping_type'],
            'boxes' => ['box_count'], 'deliver' => ['city', 'office_types'], default => [],
        }];
        foreach ($v as $key => $value) if (! in_array($key, $allowed, true) && $value !== null) $this->bad(422);
        if ($kind === 'create') $this->text($v['name'], 128);
        else { $this->opaque($v['supply_id']); $this->window($v['date_from'], $v['date_to']); }
        if ($kind === 'assign') $v['order_ids'] = $this->ids($v['order_ids'], 100, false);
        if ($kind === 'shipping' || $kind === 'deliver') {
            $this->text($v['city'], 200);
            if (! is_array($v['office_types']) || ! array_is_list($v['office_types']) || ! $v['office_types'] || count($v['office_types']) > 5
                || count($v['office_types']) !== count(array_unique($v['office_types'])) || array_diff($v['office_types'], ['sc', 'sw', 'pp', 'pfc', 'gpp'])) $this->bad(422);
            sort($v['office_types'], SORT_STRING);
        }
        if ($kind === 'shipping') { $this->day($v['shipping_date']); $v['shipping_point_id'] = $this->id($v['shipping_point_id']); if (! in_array($v['shipping_type'], ['selfShipping', 'transportCompany'], true)) $this->bad(422); }
        if ($kind === 'boxes' && (! is_int($v['box_count']) || $v['box_count'] < 1 || $v['box_count'] > 1000)) $this->bad(422);
        return array_replace(array_fill_keys(self::KEYS, null), $v);
    }

    public function listing(ManagerStore $s, string $cursor = '0'): array
    {
        $cursor = $cursor === '0' ? '0' : $this->id($cursor);
        $d = $this->read($s, 'GET', '/api/v3/supplies', ['limit' => 100, 'next' => (int) $cursor]);
        $rows = $this->list($d['supplies'] ?? null, 100); $next = ($d['next'] ?? null) === 0 ? '0' : $this->id($d['next'] ?? null); $out = [];
        foreach ($rows as $row) { $one = $this->normalizeSupply($row); if (isset($out[$one['id']])) $this->bad(); $out[$one['id']] = $one; }
        return ['supplies' => array_values($out), 'next_cursor' => $next, 'requested_cursor' => $cursor, 'limit' => 100, 'completeness' => 'not_proven'];
    }

    public function supply(ManagerStore $s, string $id): array
    {
        $this->opaque($id); $d = $this->normalizeSupply($this->read($s, 'GET', '/api/v3/supplies/'.rawurlencode($id)));
        if ($d['id'] !== $id) $this->bad(); return $d;
    }
    public function members(ManagerStore $s, string $id): array
    {
        return $this->ids($this->read($s, 'GET', '/api/marketplace/v3/supplies/'.rawurlencode($this->opaque($id)).'/order-ids')['orderIds'] ?? null, 1000);
    }
    public function boxes(ManagerStore $s, string $id): array
    {
        $rows = $this->list($this->read($s, 'GET', '/api/v3/supplies/'.rawurlencode($this->opaque($id)).'/trbx')['trbxes'] ?? null, 1000); $ids = [];
        foreach ($rows as $r) { $one = $this->opaque($r['id'] ?? null); if (in_array($one, $ids, true)) $this->bad(); $ids[] = $one; }
        sort($ids, SORT_STRING); return $ids;
    }
    public function points(ManagerStore $s, string $id, string $city, array $types, string $day): array
    {
        $this->opaque($id); $this->text($city, 200); $this->day($day);
        if (! $types || count($types) > 5 || array_diff($types, ['sc', 'sw', 'pp', 'pfc', 'gpp']) || count($types) !== count(array_unique($types))) $this->bad(422);
        sort($types, SORT_STRING);
        $rows = $this->list($this->read($s, 'GET', '/api/marketplace/v3/fbs/supplies/'.rawurlencode($id).'/shipping-points',
            ['city' => $city, 'officeType' => implode(',', $types), 'shippingDt' => $day])['shippingPoints'] ?? null, 10000); $out = []; $bytes = 0;
        foreach ($rows as $r) {
            if (! is_array($r) || ! is_bool($r['available'] ?? null) || ! is_bool($r['fulfillment'] ?? null) || ! in_array($r['officeType'] ?? null, $types, true)) $this->bad();
            $pid = $this->id($r['id'] ?? null); if (isset($out[$pid])) $this->bad();
            $out[$pid] = ['id' => $pid, 'name' => $this->text($r['name'] ?? null, 1000), 'address' => $this->text($r['address'] ?? null, 2000),
                'city' => $this->text($r['city'] ?? null, 200), 'office_type' => $r['officeType'], 'available' => $r['available'], 'fulfillment' => $r['fulfillment']];
            $bytes += strlen(json_encode($out[$pid], JSON_THROW_ON_ERROR)); if ($bytes > 3145728) $this->bad(422);
        }
        ksort($out, SORT_STRING); return array_values($out);
    }

    public function context(ManagerStore $s, array $input): array
    {
        $v = $this->selection($input); $kind = substr($v['operation_type'], strlen('fbs_wb_supply_'));
        if ($kind === 'create') {
            $this->listing($s);
            return ['operation_type' => $v['operation_type'], 'name' => $v['name'], 'supply_before' => null, 'selected_order_ids' => [],
                'orders' => [], 'source_supplies' => [], 'box_ids' => [], 'shipping_choice' => null, 'box_count' => null, 'order_count' => 0, 'total_units' => 0,
                'metadata_ready' => null, 'validation_complete' => null, 'physical_status' => null, 'actual_cost_verified' => false,
                'name_uniqueness_verified' => false, '_metadata_hash' => null];
        }
        $supply = $this->supply($s, $v['supply_id']); $beforeIds = $this->members($s, $supply['id']);
        if ($supply['done'] || $supply['scan_at'] !== null) throw new ManagerDataException('Нужна открытая и ещё не отсканированная поставка WB.', 422);
        $selected = $v['order_ids'] ?? [];
        if (array_intersect($selected, $beforeIds)) throw new ManagerDataException('Выбранное задание уже закреплено за этой поставкой.', 422);
        $ids = array_values(array_unique([...$beforeIds, ...$selected])); sort($ids, SORT_STRING);
        if (! $ids || count($ids) > 1000) throw new ManagerDataException('Для шага нужен полный состав из 1–1000 заданий; добавление — до 100 за один запрос.', 422);
        $orders = $this->orders($s, $ids, $v['date_from'], $v['date_to']); $sources = []; $sourceIds = []; $family = null;
        foreach ($orders as &$order) {
            $order['selected'] = in_array($order['external_id'], $selected, true);
            $f = [$order['warehouse_id'], $order['cargo_type'], $order['cross_border_type'], $order['is_b2b']];
            if ($family === null) $family = $f; elseif ($family !== $f) throw new ManagerDataException('WB требует один склад, габаритный тип, B2B и трансграничный тип.', 422);
            if ($order['wb_status'] !== 'waiting' || ! in_array($order['supplier_status'], ['new', 'confirm'], true)) throw new ManagerDataException('WB не подтвердил готовое к этому шагу состояние всех заданий.', 422);
            if (in_array($order['external_id'], $beforeIds, true)) {
                if ($order['supply_id'] !== $supply['id'] || $order['supplier_status'] !== 'confirm') $this->bad(422);
            } elseif ($order['supply_id'] !== null) $sourceIds[] = $order['supply_id'];
            elseif ($order['supplier_status'] !== 'new') $this->bad(422);
        }
        unset($order);
        if ($beforeIds && [$supply['cargo_type'], $supply['cross_border_type'], $supply['is_b2b']] !== array_slice($family, 1)) $this->bad(422);
        foreach (array_unique($sourceIds) as $sourceId) {
            $source = $this->supply($s, $sourceId); $sourceMembers = $this->members($s, $sourceId);
            if ($source['done'] || $source['scan_at'] !== null) throw new ManagerDataException('Перемещение из закрытой или отсканированной поставки требует отдельной проверки повторной отгрузки.', 422);
            foreach ($orders as $order) if ($order['supply_id'] === $sourceId && ! in_array($order['external_id'], $sourceMembers, true)) $this->bad();
            $sources[] = ['supply' => $source, 'order_ids' => $sourceMembers];
        }
        usort($sources, fn ($a, $b) => strcmp($a['supply']['id'], $b['supply']['id']));
        [$metadata, $metadataHash, $ready, $complete] = $this->metadata($s, $ids);
        foreach ($orders as &$order) $order['metadata'] = $metadata[$order['external_id']]; unset($order);
        $boxes = $this->boxes($s, $supply['id']); $choice = null;
        if ($kind === 'shipping' || $kind === 'deliver') {
            $shipping = $kind === 'shipping' ? ['date' => $v['shipping_date'], 'point_id' => $v['shipping_point_id'], 'type' => $v['shipping_type']] : $supply['shipping'];
            if ($shipping['date'] === null || $shipping['point_id'] === null || $shipping['type'] === null) throw new ManagerDataException('Сначала нужно согласовать параметры отгрузки WB.', 422);
            $matches = array_values(array_filter($this->points($s, $supply['id'], $v['city'], $v['office_types'], $shipping['date']), fn ($p) => $p['id'] === $shipping['point_id']));
            if (count($matches) !== 1 || ! $matches[0]['available']) throw new ManagerDataException('WB не подтвердил доступный пункт для этой поставки и даты.', 422);
            $choice = $shipping + ['point' => $matches[0], 'query_city' => $v['city'], 'office_types' => $v['office_types']];
            if ($kind === 'deliver' && (! $ready || $choice['point']['office_type'] === 'pp' && ! $boxes)) throw new ManagerDataException('Передача требует допустимых статусов маркировки и грузомест для ПВЗ.', 422);
        }
        $plan = ['operation_type' => $v['operation_type'], 'name' => null, 'supply_before' => $supply, 'selected_order_ids' => $selected, 'orders' => $orders,
            'source_supplies' => $sources, 'box_ids' => $boxes, 'shipping_choice' => $choice, 'box_count' => $v['box_count'], 'order_count' => count($orders), 'total_units' => count($orders),
            'metadata_ready' => $ready, 'validation_complete' => $complete, 'physical_status' => null, 'actual_cost_verified' => false, 'name_uniqueness_verified' => false, '_metadata_hash' => $metadataHash];
        if (strlen(json_encode($plan, JSON_THROW_ON_ERROR)) > 3145728) throw new ManagerDataException('Полный состав не помещается в проверку; частичная отправка не разрешена.', 422);
        return $plan;
    }

    public function submit(ManagerStore $s, array $p): array
    {
        try {
            return app(ManagerFbsExecutionPolicy::class)->dispatch($s, $p['_execution_control'] ?? null, fn () => $this->submitNative($s, $p));
        } catch (ManagerFbsExecutionStopped|ManagerDataException $e) {
            return ['status' => 'failed', 'acknowledged' => false, 'stopped_before_native' => true, 'control_blocked' => true, 'supply_id' => $p['supply_before']['id'] ?? null, 'created_box_ids' => null];
        } catch (\Throwable) {
            return ['status' => 'unknown', 'acknowledged' => false, 'supply_id' => $p['supply_before']['id'] ?? null, 'created_box_ids' => null];
        }
    }

    private function submitNative(ManagerStore $s, array $p): array
    {
        $kind = substr($p['operation_type'], strlen('fbs_wb_supply_')); $id = $p['supply_before']['id'] ?? null;
        [$method, $path, $body, $status] = match ($kind) {
            'create' => ['POST', '/api/v3/supplies', ['name' => $p['name']], 201],
            'assign' => ['PATCH', '/api/marketplace/v3/supplies/'.rawurlencode($id).'/orders', ['orders' => array_map('intval', $p['selected_order_ids'])], 204],
            'shipping' => ['PATCH', '/api/marketplace/v3/fbs/supplies/shipping-method', ['data' => [['supplyId' => $id, 'shippingDt' => $p['shipping_choice']['date'],
                'shippingPointId' => (int) $p['shipping_choice']['point_id'], 'shippingType' => $p['shipping_choice']['type']]]], 200],
            'boxes' => ['POST', '/api/v3/supplies/'.rawurlencode($id).'/trbx', ['amount' => $p['box_count']], 201],
            'deliver' => ['PATCH', '/api/v3/supplies/'.rawurlencode($id).'/deliver', null, 204],
            default => throw new ManagerDataException('Неподдерживаемый шаг поставки.', 422),
        };
        $unknown = ['status' => 'unknown', 'acknowledged' => false, 'supply_id' => $id, 'created_box_ids' => null];
        try {
            $client = $this->client($s);
            $r = $body === null ? $client->withBody('', 'application/json')->send($method, self::ROOT.$path) : $client->send($method, self::ROOT.$path, ['json' => $body]);
            if (in_array($r->status(), [400, 401, 402, 403, 404, 422], true)) return array_replace($unknown, ['status' => 'failed']);
            if ($r->status() !== $status || strlen($r->body()) > 1048576) return $unknown;
            if ($status === 204) return ['status' => 'verifying', 'acknowledged' => true, 'supply_id' => $id, 'created_box_ids' => null];
            $d = json_decode($r->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); $boxIds = null;
            if ($kind === 'create') $id = $this->opaque($d['id'] ?? null);
            if ($kind === 'boxes') {
                $boxIds = $this->opaqueIds($d['trbxIds'] ?? null, 1000);
                if (count($boxIds) !== $p['box_count'] || array_intersect($boxIds, $p['box_ids'])) return $unknown;
            }
            if ($kind === 'shipping') {
                $rows = $this->list($d['results'] ?? null, 1);
                if (count($rows) !== 1 || ($rows[0]['supplyId'] ?? null) !== $id) return $unknown;
                $row = $rows[0];
                if (($row['success'] ?? null) !== true || isset($row['error'])) {
                    if (! isset($row['success']) && is_array($row['error'] ?? null) && in_array($row['error']['code'] ?? null, [400, 404, 409], true))
                        return ['status' => 'failed', 'acknowledged' => false, 'supply_id' => $id, 'created_box_ids' => null];
                    return $unknown;
                }
            }
            return ['status' => 'verifying', 'acknowledged' => true, 'supply_id' => $id, 'created_box_ids' => $boxIds];
        } catch (\Throwable) { return $unknown; }
    }

    public function observe(ManagerStore $s, array $p, string $id, ?array $createdBoxes, array $selection): array
    {
        $actual = $this->supply($s, $id); $members = $this->members($s, $id); $kind = substr($p['operation_type'], strlen('fbs_wb_supply_'));
        $same = $actual['name'] === ($kind === 'create' ? $p['name'] : $p['supply_before']['name']);
        $expected = array_column($p['orders'], 'external_id'); sort($expected, SORT_STRING);
        $sameMembers = $members === $expected; $verified = false;
        if ($kind === 'create') $verified = $same && ! $actual['done'] && ! $members;
        else {
            $orders = $this->orders($s, $members, $selection['date_from'], $selection['date_to']);
            $compose = fn ($rows) => array_map(fn ($r) => array_intersect_key($r, array_flip(['external_id', 'warehouse_id', 'cargo_type', 'cross_border_type', 'is_b2b', 'nm_id', 'article', 'barcodes', 'quantity'])), $rows);
            $sameMembers = $sameMembers && $compose($orders) === $compose($p['orders']);
            $states = count(array_filter($orders, fn ($r) => $r['supply_id'] === $id && $r['supplier_status'] === ($kind === 'deliver' ? 'complete' : 'confirm'))) === count($orders);
            $boxes = $this->boxes($s, $id);
            $same = $same && $sameMembers && $states;
            if ($kind === 'assign') {
                foreach ($p['source_supplies'] as $source) {
                    $remaining = array_values(array_diff($source['order_ids'], $p['selected_order_ids']));
                    if ($this->members($s, $source['supply']['id']) !== $remaining) $same = false;
                }
                $verified = $same && ! $actual['done'];
            }
            if ($kind === 'shipping') {
                $want = array_intersect_key($p['shipping_choice'], array_flip(['date', 'point_id', 'type']));
                $verified = $same && ! $actual['done'] && $actual['shipping'] === $want && $boxes === $p['box_ids'];
            }
            if ($kind === 'boxes') {
                $want = $createdBoxes === null ? null : [...$p['box_ids'], ...$createdBoxes];
                if ($want !== null) sort($want, SORT_STRING);
                $verified = $same && ! $actual['done'] && $want !== null && $boxes === $want;
            }
            if ($kind === 'deliver') $verified = $same && $actual['done'] && $actual['closed_at'] !== null && $boxes === $p['box_ids']
                && $actual['shipping'] === $p['supply_before']['shipping'];
        }
        return ['fields_verified' => $verified, 'membership_verified' => $sameMembers, 'observed_supply' => $actual, 'observed_order_count' => count($members),
            'observed_at' => now()->utc()->toRfc3339String(), 'physical_status' => null, 'actual_cost_verified' => false];
    }

    public function document(ManagerStore $s, string $supply, string $kind, ?string $box): array
    {
        $this->opaque($supply);
        if ($kind === 'supply_qr' && $box === null) {
            $d = $this->read($s, 'GET', '/api/v3/supplies/'.rawurlencode($supply).'/barcode', ['type' => 'png']);
            if (($d['barcode'] ?? null) !== $supply) $this->bad();
        } elseif ($kind === 'box_label' && $box !== null) {
            $this->opaque($box);
            $rows = $this->list($this->read($s, 'POST', '/api/v3/supplies/'.rawurlencode($supply).'/trbx/stickers?type=png', ['trbxIds' => [$box]])['stickers'] ?? null, 1);
            if (count($rows) !== 1 || ! is_array($rows[0])) $this->bad(); $d = $rows[0];
            $this->text($d['barcode'] ?? null, 1000);
        } else $this->bad(422);
        if (! is_string($d['file'] ?? null)) $this->bad();
        $bytes = base64_decode($d['file'], true);
        if (! is_string($bytes) || strlen($bytes) > 1048576 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) $this->bad();
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] !== 580 || $size[1] !== 400 || ($size['mime'] ?? '') !== 'image/png') $this->bad();
        return ['bytes' => $bytes, 'mime' => 'image/png'];
    }

    private function orders(ManagerStore $s, array $ids, string $from, string $to): array
    {
        $this->window($from, $to); $out = []; $cursor = 0; $seen = []; $wanted = array_fill_keys($ids, true);
        if (! $ids) return [];
        for ($page = 0; $page < 100; $page++) {
            $d = $this->read($s, 'GET', '/api/v3/orders', ['limit' => 1000, 'next' => $cursor,
                'dateFrom' => \Carbon\CarbonImmutable::parse($from, 'UTC')->startOfDay()->timestamp, 'dateTo' => min(\Carbon\CarbonImmutable::parse($to, 'UTC')->endOfDay()->timestamp, now()->utc()->timestamp)]);
            $rows = $this->list($d['orders'] ?? null, 1000);
            if (! is_int($d['next'] ?? null) || $d['next'] < 0) $this->bad();
            foreach ($rows as $r) {
                $id = $this->id($r['id'] ?? null); if (! isset($wanted[$id])) continue;
                if (isset($out[$id]) || ($r['deliveryType'] ?? null) !== 'fbs' || ! in_array($r['cargoType'] ?? null, [1, 2, 3], true)
                    || ! in_array($r['crossBorderType'] ?? null, [0, 1], true) || ! is_bool($r['options']['isB2B'] ?? null)) $this->bad();
                $created = $this->clock($r['createdAt'] ?? null);
                if (substr($created, 0, 10) < $from || substr($created, 0, 10) > $to) $this->bad();
                $out[$id] = ['external_id' => $id, 'supply_id' => isset($r['supplyId']) && $r['supplyId'] !== '' ? $this->opaque($r['supplyId']) : null,
                    'created_at' => $created, 'warehouse_id' => $this->id($r['warehouseId'] ?? null), 'cargo_type' => $r['cargoType'], 'cross_border_type' => $r['crossBorderType'],
                    'is_b2b' => $r['options']['isB2B'], 'nm_id' => $this->id($r['nmId'] ?? null), 'article' => $this->text($r['article'] ?? null, 1000, true),
                    'barcodes' => $this->texts($r['skus'] ?? null, 100, 1000), 'quantity' => 1];
            }
            if (count($out) === count($wanted)) break;
            if (count($rows) < 1000) break;
            $next = $d['next']; if ($next === $cursor || isset($seen[$next])) $this->bad();
            $seen[$next] = true; $cursor = $next;
        }
        if (count($out) !== count($wanted)) throw new ManagerDataException('Не найден весь состав поставки в выбранном окне до 30 дней за последние 3 месяца.', 422);
        foreach (array_chunk($ids, 1000) as $batch) {
            $rows = $this->list($this->read($s, 'POST', '/api/v3/orders/status', ['orders' => array_map('intval', $batch)])['orders'] ?? null, 1000); $found = [];
            foreach ($rows as $r) {
                $id = $this->id($r['id'] ?? null);
                if (! in_array($id, $batch, true) || isset($found[$id]) || ! in_array($r['supplierStatus'] ?? null, ['new', 'confirm', 'complete', 'cancel'], true)) $this->bad();
                $found[$id] = true; $out[$id]['supplier_status'] = $r['supplierStatus']; $out[$id]['wb_status'] = $this->text($r['wbStatus'] ?? null, 100);
            }
            if (count($found) !== count($batch)) $this->bad();
        }
        ksort($out, SORT_STRING); return array_values($out);
    }

    private function metadata(ManagerStore $s, array $ids): array
    {
        $out = []; $raw = []; $ready = true; $complete = true;
        $allowed = ['imei' => ['imeiMaySell', 'imeiSoldB2B', 'deadlineExceeded'], 'uin' => ['uinMaySell', 'deadlineExceeded'],
            'sgtin' => ['sgtinIntroduced', 'sgtinSoldB2B', 'deadlineExceeded'], 'gtin' => [], 'expiration' => [], 'customsDeclaration' => []];
        foreach (array_chunk($ids, 100) as $batch) {
            $rows = $this->list($this->read($s, 'POST', '/api/marketplace/v3/orders/meta', ['orders' => array_map('intval', $batch)])['orders'] ?? null, 100);
            foreach ($rows as $r) {
                $id = $this->id($r['id'] ?? null); if (! in_array($id, $batch, true) || isset($out[$id])) $this->bad();
                $items = $this->list($r['metaDetails'] ?? null, 20); $one = []; $private = [];
                foreach ($items as $m) {
                    $key = $m['key'] ?? null; $value = $m['value'] ?? null; $decision = $this->text($m['decision'] ?? null, 200);
                    if (! is_string($key) || ! array_key_exists($key, $allowed) || isset($one[$key]) || ($value !== null && (! is_string($value) || strlen($value) > 8192))) $this->bad();
                    $present = $value !== null && trim($value) !== '' && strtolower(trim($value)) !== 'null';
                    $valid = $decision === 'optional' || ($present && in_array($decision, ['filled', ...$allowed[$key]], true));
                    if (! $valid) $ready = false; if (! $valid || $decision === 'deadlineExceeded') $complete = false;
                    $one[$key] = ['key' => $key, 'decision' => $decision, 'value_present' => $present, 'native_allows_dispatch' => $valid];
                    $private[$key] = [$value, $decision];
                }
                ksort($one, SORT_STRING); ksort($private, SORT_STRING); $out[$id] = array_values($one); $raw[$id] = $private;
            }
        }
        if (count($out) !== count($ids)) $this->bad();
        ksort($raw, SORT_STRING); return [$out, hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)), $ready, $complete];
    }

    private function normalizeSupply(mixed $r): array
    {
        if (! is_array($r) || ! is_bool($r['done'] ?? null) || ! array_key_exists('scanDt', $r) || ! array_key_exists('closedAt', $r)) $this->bad();
        $nullableId = fn ($v) => $v === null || $v === 0 || $v === '0' ? null : $this->id($v);
        $cargo = $r['cargoType'] ?? null; if (! in_array($cargo, [null, 0, 1, 2, 3], true)) $this->bad();
        if (! in_array($r['crossBorderType'] ?? null, [null, 0, 1], true) || (! is_bool($r['isB2b'] ?? null) && ($r['isB2b'] ?? null) !== null)
            || (! is_bool($r['spotAvailable'] ?? null) && ($r['spotAvailable'] ?? null) !== null) || ! in_array($r['shippingType'] ?? null, [null, 'selfShipping', 'transportCompany'], true)) $this->bad();
        return ['id' => $this->opaque($r['id'] ?? null), 'name' => $this->text($r['name'] ?? null, 128, true), 'done' => $r['done'],
            'created_at' => $this->clock($r['createdAt'] ?? null), 'closed_at' => $r['closedAt'] === null ? null : $this->clock($r['closedAt']),
            'scan_at' => $r['scanDt'] === null ? null : $this->clock($r['scanDt']), 'cargo_type' => $cargo ?: null, 'cross_border_type' => $r['crossBorderType'] ?? null,
            'is_b2b' => $r['isB2b'] ?? null, 'destination_office_id' => $nullableId($r['destinationOfficeId'] ?? null), 'spot_available' => $r['spotAvailable'] ?? null,
            'shipping' => ['date' => isset($r['shippingDt']) ? $this->day($r['shippingDt']) : null, 'point_id' => $nullableId($r['shippingPointId'] ?? null), 'type' => $r['shippingType'] ?? null]];
    }
    private function read(ManagerStore $s, string $method, string $path, array $data = []): array
    {
        try {
            $r = $this->client($s)->send($method, self::ROOT.$path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
            if ($r->status() !== 200 || strlen($r->body()) > 4194304) $this->bad();
            $d = json_decode($r->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($d)) $this->bad(); return $d;
        } catch (ManagerDataException $e) { throw $e; }
        catch (\Throwable) { throw new ManagerDataException('WB не подтвердил контекст поставки.'); }
    }
    private function client(ManagerStore $s): \Illuminate\Http\Client\PendingRequest
    {
        if ($s->marketplace !== 'wildberries') $this->bad(422);
        return Http::withHeaders(['Authorization' => $s->apiKey])->acceptJson()->connectTimeout(3)->timeout(20)->withOptions(['allow_redirects' => false]);
    }
    private function window(mixed $from, mixed $to): void
    {
        $this->day($from); $this->day($to); $a = \Carbon\CarbonImmutable::parse($from, 'UTC'); $b = \Carbon\CarbonImmutable::parse($to, 'UTC');
        if ($a->gt($b) || $a->lt(now()->utc()->subMonthsNoOverflow(3)->startOfDay()) || $b->gt(now()->utc()->startOfDay()) || $a->diffInDays($b) > 29) $this->bad(422);
    }
    private function day(mixed $v): string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) $this->bad(422);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v); if (! $d || $d->format('Y-m-d') !== $v) $this->bad(422); return $v;
    }
    private function clock(mixed $v): string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,9})?(?:Z|[+-]\d\d:\d\d)$/D', $v) || strtotime($v) === false) $this->bad(); return $v;
    }
    private function list(mixed $v, int $max): array { if (! is_array($v) || ! array_is_list($v) || count($v) > $max) $this->bad(); return $v; }
    private function ids(mixed $v, int $max, bool $empty = true): array
    {
        $ids = array_map(fn ($i) => $this->id($i), $this->list($v, $max)); if ((! $empty && ! $ids) || count(array_unique($ids)) !== count($ids)) $this->bad(422);
        sort($ids, SORT_STRING); return $ids;
    }
    private function opaqueIds(mixed $v, int $max): array
    {
        $ids = array_map(fn ($i) => $this->opaque($i), $this->list($v, $max)); if (count(array_unique($ids)) !== count($ids)) $this->bad(); sort($ids, SORT_STRING); return $ids;
    }
    private function texts(mixed $v, int $max, int $len): array
    {
        $rows = array_map(fn ($i) => $this->text($i, $len), $this->list($v, $max)); if (! $rows || count(array_unique($rows)) !== count($rows)) $this->bad(); sort($rows, SORT_STRING); return $rows;
    }
    private function text(mixed $v, int $max, bool $empty = false): string
    {
        if (! is_string($v) || (! $empty && trim($v) === '') || mb_strlen($v) > $max || preg_match('/[\x00-\x1f\x7f]/', $v)) $this->bad(); return $v;
    }
    private function opaque(mixed $v): string { if (! is_string($v) || ! preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $v)) $this->bad(422); return $v; }
    private function id(mixed $v): string
    {
        if (! is_int($v) && ! is_string($v)) $this->bad(); $v = (string) $v;
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $v) || (strlen($v) === 19 && strcmp($v, '9223372036854775807') > 0)) $this->bad(); return $v;
    }
    private function bad(int $status = 503): never { throw new ManagerDataException('Состав, параметры или полнота данных поставки WB не подтверждены.', $status); }
}
