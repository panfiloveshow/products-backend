<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Fixed Native contracts. The owner must persist a one-shot claim before the beforeSending callback. */
final class ManagerOzonFboGateway
{
    public function __construct(private ManagerSupplyDestinationCatalog $catalog) {}

    /** Exact Ozon lookup; seller articles and product IDs never stand in for SKU. */
    public function verifiedProducts(ManagerStore $store, array $offers): array
    {
        if (! array_is_list($offers) || count($offers) < 1 || count($offers) > 5000) {
            $this->bad(422);
        }
        foreach ($offers as $offer) {
            if (! is_string($offer) || trim($offer) === '' || mb_strlen($offer) > 200) {
                $this->bad(422);
            }
        }
        if (count(array_unique($offers, SORT_STRING)) !== count($offers)) {
            $this->bad(422);
        }
        $out = [];
        $productIds = [];
        $skus = [];
        foreach (array_chunk($offers, 1000) as $batch) {
            $raw = $this->read($store, '/v3/product/info/list', ['offer_id' => $batch]);
            if (! empty($raw['errors'])) {
                $this->bad();
            }
            if (! is_array($raw['items'] ?? null) || ! array_is_list($raw['items']) || count($raw['items']) !== count($batch)) {
                $this->bad();
            }
            foreach ($raw['items'] as $row) {
                if (! is_array($row) || ! is_string($row['offer_id'] ?? null) || ! in_array($row['offer_id'], $batch, true) || isset($out[$row['offer_id']])) {
                    $this->bad();
                }
                $id = $this->id($row['id'] ?? null);
                $sku = $this->id($row['sku'] ?? null);
                if (isset($productIds[$id]) || isset($skus[$sku])) {
                    $this->bad();
                }
                $productIds[$id] = true;
                $skus[$sku] = true;
                $out[$row['offer_id']] = ['offer_id' => $row['offer_id'], 'product_id' => $id, 'sku' => $sku,
                    'name' => $this->optionalText($row['name'] ?? null),
                    'is_archived' => $this->optionalBool($row['is_archived'] ?? null),
                    'is_discounted' => $this->optionalBool($row['is_discounted'] ?? null)];
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    public function clusters(ManagerStore $store): array
    {
        $this->store($store);

        return $this->catalog->readNative($store);
    }

    /** One request, no polling/retry or alternate create endpoint. */
    public function submitDirectDraft(ManagerStore $store, string $macro, array $items, callable $beforeSending): array
    {
        $body = ['cluster_info' => ['items' => $this->items($items), 'macrolocal_cluster_id' => (int) $this->id($macro, 422)], 'deletion_sku_mode' => 'FULL'];

        return $this->submit($store, '/v1/draft/direct/create', $body, 'draft_id', $beforeSending);
    }

    public function submitDirectSupply(ManagerStore $store, string $draft, string $macro, string $warehouse, array $slot, callable $beforeSending): array
    {
        if (array_diff(array_keys($slot), ['from_in_timezone', 'to_in_timezone']) || count($slot) !== 2) {
            $this->bad(422);
        }
        $from = $this->wireTime($slot['from_in_timezone'], 422);
        $to = $this->wireTime($slot['to_in_timezone'], 422);
        if ($from['aware'] !== $to['aware'] || $from['date'] >= $to['date']) {
            $this->bad(422);
        }
        // Timezone and membership in a fresh Native slot list are the owner's preflight.
        $body = ['draft_id' => (int) $this->id($draft, 422), 'selected_cluster_warehouses' => [[
            'macrolocal_cluster_id' => (int) $this->id($macro, 422), 'storage_warehouse_id' => (int) $this->id($warehouse, 422)]],
            'supply_type' => 'DIRECT', 'timeslot' => $slot];

        return $this->submit($store, '/v2/draft/supply/create', $body, 'draft_id', $beforeSending, $draft);
    }

    public function draftInfo(ManagerStore $store, string $draft): array
    {
        $raw = $this->read($store, '/v2/draft/create/info', ['draft_id' => (int) $this->id($draft)]);
        $status = $this->code($raw['status'] ?? null);
        if (! in_array($status, ['UNSPECIFIED', 'SUCCESS', 'IN_PROGRESS', 'FAILED'], true)) {
            $this->bad();
        }
        $clusters = null;
        if (array_key_exists('clusters', $raw)) {
            $clusters = [];
            $seen = [];
            foreach ($this->list($raw['clusters'], 100) as $row) {
                if (! is_array($row)) {
                    $this->bad();
                }
                $macro = $this->id($row['macrolocal_cluster_id'] ?? null);
                if (isset($seen[$macro])) {
                    $this->bad();
                }
                $seen[$macro] = true;
                $warehouses = [];
                foreach ($this->list($row['warehouses'] ?? null, 10000) as $wh) {
                    if (! is_array($wh) || ! is_array($wh['storage_warehouse'] ?? null)) {
                        $this->bad();
                    }
                    $storage = $wh['storage_warehouse'];
                    $id = $this->id($storage['warehouse_id'] ?? null);
                    if (isset($warehouses[$id])) {
                        $this->bad();
                    }
                    $availability = $wh['availability_status'] ?? null;
                    if ($availability !== null && (! is_array($availability) || $availability !== [] && array_is_list($availability))) {
                        $this->bad();
                    }
                    $warehouses[$id] = ['id' => $id, 'name' => $this->optionalText($storage['name'] ?? null),
                        'address' => $this->optionalText($storage['address'] ?? null),
                        'availability_state' => $this->code($availability['state'] ?? null),
                        'invalid_reason' => $this->code($availability['invalid_reason'] ?? null),
                        'bundle_id' => $this->opaque($wh['bundle_id'] ?? null),
                        'restricted_bundle_id' => $this->opaque($wh['restricted_bundle_id'] ?? null),
                        'supply_tags' => $this->codeList($wh['supply_tags'] ?? null)];
                }
                ksort($warehouses, SORT_STRING);
                $clusters[] = ['macrolocal_cluster_id' => $macro, 'name' => $this->optionalText($row['cluster_name'] ?? null),
                    'supply_type' => $this->code($row['supply_type'] ?? null), 'warehouses' => array_values($warehouses)];
            }
        } elseif ($status === 'SUCCESS') {
            $this->bad();
        }

        return ['draft_id' => $draft, 'status' => $status, 'clusters' => $clusters, 'errors' => $this->errors($raw['errors'] ?? null)];
    }

    /** Complete bounded contents of one Native bundle; no missing page becomes an empty result. */
    public function bundle(ManagerStore $store, string $bundle): array
    {
        if ($this->opaque($bundle) === null) {
            $this->bad(422);
        }
        $cursor = '';
        $seen = [];
        $out = [];
        $reported = null;
        for ($page = 0; $page < 50; $page++) {
            $raw = $this->read($store, '/v1/supply-order/bundle', ['bundle_ids' => [$bundle], 'limit' => 100, 'last_id' => $cursor]);
            if (! is_bool($raw['has_next'] ?? null)) {
                $this->bad();
            }
            if (array_key_exists('total_count', $raw)) {
                $count = $raw['total_count'];
                if (! is_int($count) || $count < 0 || $count > 5000 || $reported !== null && $reported !== $count) {
                    $this->bad();
                }
                $reported = $count;
            }
            foreach ($this->list($raw['items'] ?? null, 100) as $row) {
                if (! is_array($row)) {
                    $this->bad();
                }
                $sku = $this->id($row['sku'] ?? null);
                if (isset($out[$sku]) || count($out) >= 5000 || ! is_int($row['quantity'] ?? null) || $row['quantity'] < 0 || $row['quantity'] > 2147483647) {
                    $this->bad();
                }
                $out[$sku] = ['sku' => $sku, 'quantity' => $row['quantity'],
                    'product_id' => isset($row['product_id']) ? $this->id($row['product_id']) : null,
                    'offer_id' => $this->optionalText($row['offer_id'] ?? null),
                    'name' => $this->optionalText($row['name'] ?? null), 'tags' => $this->codeList($row['tags'] ?? null)];
            }
            if ($raw['has_next'] === false) {
                if ($reported !== null && $reported !== count($out)) {
                    $this->bad();
                }
                ksort($out, SORT_STRING);

                return ['bundle_id' => $bundle, 'items' => array_values($out), 'total' => count($out), 'total_reported' => $reported, 'complete' => true];
            }
            $next = $this->opaque($raw['last_id'] ?? null);
            if ($next === null || $next === $cursor || isset($seen[$next])) {
                $this->bad();
            }
            $seen[$next] = true;
            $cursor = $next;
        }
        $this->bad();
    }

    public function creationStatus(ManagerStore $store, string $draft): array
    {
        $raw = $this->read($store, '/v2/draft/supply/create/status', ['draft_id' => (int) $this->id($draft)]);
        $status = $this->code($raw['status'] ?? null);
        if (! in_array($status, ['UNSPECIFIED', 'SUCCESS', 'IN_PROGRESS', 'FAILED'], true)) {
            $this->bad();
        }
        $order = null;
        if (isset($raw['order_id']) && $raw['order_id'] !== 0 && $raw['order_id'] !== '0') {
            $order = $this->id($raw['order_id']);
        }
        $reasons = $this->codeList($raw['error_reasons'] ?? null);
        if ($status === 'SUCCESS' && ($order === null || $reasons !== null && $reasons !== [])) {
            $this->bad();
        }
        if ($status === 'FAILED' && $order !== null) {
            $this->bad();
        }

        return ['draft_id' => $draft, 'status' => $status, 'order_id' => $order, 'error_reasons' => $reasons];
    }

    public function order(ManagerStore $store, string $order): array
    {
        $raw = $this->read($store, '/v3/supply-order/get', ['order_ids' => [(int) $this->id($order)]]);
        $orders = $this->list($raw['orders'] ?? null, 1);
        if (count($orders) !== 1 || ! is_array($orders[0]) || $this->id($orders[0]['order_id'] ?? null) !== $order) {
            $this->bad();
        }
        $row = $orders[0];
        $supplies = [];
        foreach ($this->list($row['supplies'] ?? null, 100) as $supply) {
            if (! is_array($supply) || ! is_array($supply['storage_warehouse'] ?? null)) {
                $this->bad();
            }
            $id = $this->id($supply['supply_id'] ?? null);
            if (isset($supplies[$id])) {
                $this->bad();
            }
            $wh = $supply['storage_warehouse'];
            $flags = $supply['supply_tags'] ?? null;
            if ($flags !== null && (! is_array($flags) || $flags !== [] && array_is_list($flags))) {
                $this->bad();
            }
            $tags = [];
            foreach (['is_ettn_required', 'is_evsd_required', 'is_jewelry', 'is_marking_possible', 'is_marking_required', 'is_utd', 'freeze_stock_for_marking'] as $tag) {
                $tags[$tag] = $this->optionalBool($flags[$tag] ?? null);
            }
            $supplies[$id] = ['supply_id' => $id, 'warehouse_id' => $this->id($wh['warehouse_id'] ?? null),
                'warehouse_name' => $this->optionalText($wh['name'] ?? null), 'warehouse_address' => $this->optionalText($wh['address'] ?? null),
                'bundle_id' => $this->opaque($supply['bundle_id'] ?? null), 'state' => $this->code($supply['state'] ?? null),
                'is_crossdock' => $this->optionalBool($supply['is_crossdock'] ?? null), 'supply_tags' => $tags];
        }
        $slot = $row['timeslot'] ?? null;
        $from = $slot['timeslot']['from'] ?? null;
        $to = $slot['timeslot']['to'] ?? null;
        $timezone = $slot['timezone_info']['iana_name'] ?? null;
        $offset = $slot['timezone_info']['offset'] ?? null;
        if ($slot !== null && ! is_array($slot)) {
            $this->bad();
        }
        foreach (['timeslot', 'timezone_info'] as $key) {
            if (isset($slot[$key]) && (! is_array($slot[$key]) || $slot[$key] !== [] && array_is_list($slot[$key]))) {
                $this->bad();
            }
        }
        if ($from !== null) {
            $from = $this->instant($from);
        }
        if ($to !== null) {
            $to = $this->instant($to);
        }
        if ($timezone !== null && (! is_string($timezone) || ! in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true))) {
            $this->bad();
        }
        if ($offset !== null && (! is_string($offset) || ! preg_match('/^[+-](?:0[0-9]|1[0-4]):[0-5][0-9]$/D', $offset))) {
            $this->bad();
        }
        if (($from === null) !== ($to === null) || $from !== null && $from >= $to) {
            $this->bad();
        }
        $dropoff = $row['dropoff_warehouse'] ?? null;
        if ($dropoff !== null && ! is_array($dropoff)) {
            $this->bad();
        }

        return ['order_id' => $order, 'order_number' => $this->optionalText($row['order_number'] ?? null), 'state' => $this->code($row['state'] ?? null),
            'dropoff_warehouse' => $dropoff === null ? null : ['id' => $this->id($dropoff['warehouse_id'] ?? null),
                'name' => $this->optionalText($dropoff['name'] ?? null), 'address' => $this->optionalText($dropoff['address'] ?? null)],
            'timeslot' => $slot === null ? null : ['from_utc' => $from, 'to_utc' => $to, 'timezone' => $timezone, 'offset' => $offset],
            'supplies' => array_values($supplies)];
    }

    private function list(mixed $value, int $max): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            $this->bad();
        }

        return $value;
    }

    private function code(mixed $value): ?string
    {
        if ($value !== null && (! is_string($value) || ! preg_match('/^[A-Z][A-Z0-9_]{0,119}$/D', $value))) {
            $this->bad();
        }

        return $value;
    }

    private function codeList(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return array_map(fn ($item) => $this->code($item) ?? $this->bad(), $this->list($value, 5000));
    }

    private function opaque(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            $this->bad();
        }

        return $value;
    }

    private function errors(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $out = [];
        foreach ($this->list($value, 5000) as $row) {
            if (! is_array($row)) {
                $this->bad();
            }
            $items = null;
            if (isset($row['items_validation'])) {
                $items = [];
                foreach ($this->list($row['items_validation'], 5000) as $group) {
                    if (! is_array($group)) {
                        $this->bad();
                    }
                    $rejected = [];
                    foreach ($this->list($group['rejected_items'] ?? null, 5000) as $item) {
                        if (! is_array($item)) {
                            $this->bad();
                        }
                        $rejected[] = ['sku' => $this->id($item['sku'] ?? null), 'reasons' => $this->codeList($item['reasons'] ?? null)];
                    }
                    $items[] = ['macrolocal_cluster_id' => $this->id($group['macrolocal_cluster_id'] ?? null), 'rejected_items' => $rejected];
                }
            }
            $out[] = ['message_code' => $this->code($row['error_message'] ?? null), 'reasons' => $this->codeList($row['error_reasons'] ?? null),
                'sku_ids' => isset($row['skus']) ? array_map(fn ($sku) => $this->id($sku), $this->list($row['skus'], 5000)) : null,
                'items_validation' => $items];
        }

        return $out;
    }

    private function instant(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $this->bad();
        }
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                $this->bad();
            }

            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        } catch (\Throwable) {
            $this->bad();
        }
    }

    public function timeslots(ManagerStore $store, string $draft, string $macro, string $warehouse, string $dateFrom, string $dateTo): array
    {
        foreach ([$dateFrom, $dateTo] as $day) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
            if (! $date || $date->format('Y-m-d') !== $day) {
                $this->bad(422);
            }
        }
        $from = new \DateTimeImmutable($dateFrom, new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable($dateTo, new \DateTimeZone('UTC'));
        if ($to < $from || $from->diff($to)->days > 27) {
            $this->bad(422);
        }
        $body = ['draft_id' => (int) $this->id($draft), 'date_from' => $dateFrom, 'date_to' => $dateTo, 'supply_type' => 'DIRECT',
            'selected_cluster_warehouses' => [['macrolocal_cluster_id' => (int) $this->id($macro), 'storage_warehouse_id' => (int) $this->id($warehouse)]]];
        $raw = $this->read($store, '/v2/draft/timeslot/info', $body);
        $reason = $this->code($raw['error_reason'] ?? null);
        $base = ['draft_id' => $draft, 'macrolocal_cluster_id' => $macro, 'warehouse_id' => $warehouse, 'date_from' => $dateFrom, 'date_to' => $dateTo];
        if ($reason !== null && $reason !== 'UNSPECIFIED') {
            return [...$base, 'quoted' => false, 'error_reason' => $reason, 'timezone' => null, 'native_current_time' => null, 'slots' => null];
        }
        $calendar = $raw['result']['drop_off_warehouse_timeslots'] ?? null;
        if (! is_array($calendar)) {
            $this->bad();
        }
        $timezone = $this->zone($calendar['warehouse_timezone'] ?? null);
        $zone = new \DateTimeZone($timezone);
        $current = $calendar['current_time_in_timezone'] ?? null;
        if ($current !== null) {
            $this->wireTime($current);
        }
        $slots = [];
        foreach ($this->list($calendar['days'] ?? null, 28) as $day) {
            if (! is_array($day)) {
                $this->bad();
            }
            $sourceDate = $day['date_in_timezone'] ?? null;
            if ($sourceDate !== null && (! is_string($sourceDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2})?$/D', $sourceDate))) {
                $this->bad();
            }
            foreach ($this->list($day['timeslots'] ?? null, 5000) as $slot) {
                if (! is_array($slot) || count($slots) >= 5000) {
                    $this->bad();
                }
                $fromRaw = $slot['from_in_timezone'] ?? null;
                $toRaw = $slot['to_in_timezone'] ?? null;
                $from = $this->resolveTime($fromRaw, $zone);
                $to = $this->resolveTime($toRaw, $zone);
                $localDate = substr($from['local'], 0, 10);
                if ($localDate < $dateFrom || $localDate > $dateTo || $sourceDate !== null && substr($sourceDate, 0, 10) !== $localDate) {
                    $this->bad();
                }
                $key = hash('sha256', json_encode([$draft, $macro, $warehouse, $timezone, $fromRaw, $toRaw], JSON_THROW_ON_ERROR));
                if (isset($slots[$key])) {
                    $this->bad();
                }
                $valid = $from['utc'] !== null && $to['utc'] !== null && $from['utc'] < $to['utc'];
                $slots[$key] = ['slot_key' => $key, 'from_in_timezone' => $fromRaw, 'to_in_timezone' => $toRaw, 'timezone' => $timezone,
                    'from_local' => $from['local'], 'to_local' => $to['local'], 'from_utc' => $from['utc'], 'to_utc' => $to['utc'],
                    'from_resolution' => $from['resolution'], 'to_resolution' => $to['resolution'],
                    'eligible_time' => $valid && $to['utc'] > now('UTC')->format('Y-m-d\TH:i:s.u\Z')];
            }
        }
        ksort($slots, SORT_STRING);

        return [...$base, 'quoted' => true, 'error_reason' => null, 'timezone' => $timezone, 'native_current_time' => $current, 'slots' => array_values($slots)];
    }

    private function zone(mixed $value): string
    {
        if (! is_string($value)) {
            $this->bad();
        }
        foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $zone) {
            if (strcasecmp($zone, $value) === 0) {
                return $zone;
            }
        }
        $this->bad();
    }

    private function wireTime(mixed $value, int $status = 503): array
    {
        if (! is_string($value)) {
            $this->bad($status);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value)) {
            $parts = explode('.', $value, 2);
            $canonical = $parts[0].'.'.str_pad($parts[1] ?? '', 6, '0');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u', $canonical, new \DateTimeZone('UTC'));
            if (! $date || $date->format('Y-m-d\TH:i:s.u') !== $canonical) {
                $this->bad($status);
            }

            return ['aware' => false, 'date' => $date];
        }
        try {
            return ['aware' => true, 'date' => new \DateTimeImmutable($this->instant($value))];
        } catch (ManagerDataException) {
            $this->bad($status);
        }
    }

    /** Never silently pick a DST fold or move a nonexistent local time. */
    private function resolveTime(mixed $value, \DateTimeZone $zone): array
    {
        $parsed = $this->wireTime($value);
        if ($parsed['aware']) {
            return ['utc' => $parsed['date']->format('Y-m-d\TH:i:s.u\Z'),
                'local' => $parsed['date']->setTimezone($zone)->format('Y-m-d\TH:i:s.u'), 'resolution' => 'explicit_offset'];
        }
        $wall = $parsed['date'];
        $local = $wall->format('Y-m-d\TH:i:s.u');
        $offsets = [];
        foreach ($zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800) ?: [] as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }
        $candidates = [];
        foreach (array_keys($offsets) as $offset) {
            $candidate = $wall->modify(sprintf('%+d seconds', -$offset));
            if ($candidate->setTimezone($zone)->format('Y-m-d\TH:i:s.u') === $local) {
                $candidates[$candidate->format('Y-m-d\TH:i:s.u\Z')] = true;
            }
        }
        $count = count($candidates);

        return ['utc' => $count === 1 ? array_key_first($candidates) : null, 'local' => $local,
            'resolution' => $count === 1 ? 'unique' : ($count === 0 ? 'nonexistent' : 'ambiguous')];
    }

    private function items(array $items): array
    {
        if (! array_is_list($items) || $items === [] || count($items) > 5000) {
            $this->bad(422);
        }
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || count($item) !== 2 || array_diff(array_keys($item), ['sku', 'quantity'])
                || ! is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || $item['quantity'] > 2147483647) {
                $this->bad(422);
            }
            $sku = $this->id($item['sku'] ?? null, 422);
            if (isset($seen[$sku])) {
                $this->bad(422);
            }
            $seen[$sku] = true;
            $out[] = ['sku' => (int) $sku, 'quantity' => $item['quantity']];
        }

        return $out;
    }

    private function submit(ManagerStore $store, string $endpoint, array $body, string $idField, callable $beforeSending, ?string $expectedId = null): array
    {
        $started = false;
        try {
            $response = $this->pending($store)->beforeSending(function () use ($beforeSending, &$started) {
                $beforeSending();
                $started = true;
            })->post('https://api-seller.ozon.ru'.$endpoint, $body);
            $raw = $this->json($response);
            if (! $response->successful()) {
                $definite = in_array($response->status(), [400, 401, 403, 404, 409, 422], true) && is_int($raw['code'] ?? null) && $raw['code'] > 0;

                return ['status' => $definite ? 'failed' : 'unknown', 'acknowledged' => false, 'native_id' => null, 'native_attempted' => $started];
            }
            if (! array_key_exists($idField, $raw)) {
                $this->bad();
            }
            if ($raw[$idField] === 0 && is_array($raw['errors'] ?? null) && $raw['errors'] !== []) {
                return ['status' => 'failed', 'acknowledged' => false, 'native_id' => null, 'native_attempted' => $started];
            }
            $id = $this->id($raw[$idField]);
            if ($expectedId !== null && $id !== $expectedId) {
                $this->bad();
            }

            if (! empty($raw['error_reasons'])) {
                return ['status' => 'unknown', 'acknowledged' => false, 'native_id' => $id, 'native_attempted' => $started, 'response_has_errors' => true];
            }

            // A draft id acknowledges only this command; acceptance/order contents require readback.
            return ['status' => 'verifying', 'acknowledged' => true, 'native_id' => $id, 'native_attempted' => $started];
        } catch (\Throwable) {
            return ['status' => $started ? 'unknown' : 'failed', 'acknowledged' => false, 'native_id' => null,
                'native_attempted' => $started, 'stopped_before_native' => ! $started];
        }
    }

    private function read(ManagerStore $store, string $endpoint, array $body): array
    {
        $response = $this->pending($store)->post('https://api-seller.ozon.ru'.$endpoint, $body);
        if (in_array($response->status(), [401, 403], true)) {
            $this->bad(403);
        }
        if ($response->status() === 429) {
            $this->bad(429);
        }
        if (! $response->successful()) {
            $this->bad();
        }
        $raw = $this->json($response);
        if (! empty($raw['code']) || ! empty($raw['error'])) {
            $this->bad();
        }

        return $raw;
    }

    private function pending(ManagerStore $store): PendingRequest
    {
        $this->store($store);

        return Http::withHeaders(['Client-Id' => $store->clientId, 'Api-Key' => $store->apiKey])->acceptJson()->asJson()->timeout(30)
            ->connectTimeout(5)->withOptions(['allow_redirects' => false]);
    }

    private function store(ManagerStore $store): void
    {
        if ($store->marketplace !== 'ozon' || $store->apiKey === '' || $store->clientId === null) {
            $this->bad(422);
        }
        $this->id($store->clientId, 422);
    }

    private function json(Response $response): array
    {
        if (strlen($response->body()) > 32_000_000) {
            $this->bad();
        }
        $raw = $response->json();
        if (! is_array($raw) || array_is_list($raw)) {
            $this->bad();
        }

        return $raw;
    }

    private function id(mixed $value, int $status = 503): string
    {
        if (! (is_int($value) || is_string($value)) || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->bad($status);
        }

        return (string) $value;
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value !== null && (! is_string($value) || trim($value) === '' || mb_strlen($value) > 500)) {
            $this->bad();
        }

        return $value;
    }

    private function optionalBool(mixed $value): ?bool
    {
        if ($value !== null && ! is_bool($value)) {
            $this->bad();
        }

        return $value;
    }

    private function bad(int $status = 503): never
    {
        throw new ManagerDataException('Не подтверждён точный контракт поставки Ozon.', $status);
    }
}
