<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Support\Facades\Http;

/** Current carriage API. No automatic retries, confirmation, signing or physical-state inference. */
final class ManagerOzonCarriageGateway
{
    public const MAX_POSTINGS = 1000;

    public function methods(ManagerStore $store, string $day): array
    {
        $this->day($day); $cursor = ''; $seen = []; $methods = []; $bytes = 0;
        for ($page = 0; $page < 10; $page++) {
            $d = $this->read($store, '/v2/carriage/delivery/list', ['filter' => ['departure_date' => $day], 'limit' => 100, 'cursor' => $cursor]);
            if (! is_array($d['methods'] ?? null) || ! array_is_list($d['methods']) || count($d['methods']) > 100 || ! is_bool($d['has_next'] ?? null)) $this->invalid();
            foreach ($d['methods'] as $raw) {
                if (! is_array($raw)) $this->invalid();
                $id = $this->id($raw['delivery_method_id'] ?? null);
                if (isset($methods[$id]) || $this->dateDay($raw['departure_date'] ?? null) !== $day) $this->invalid();
                foreach (['is_rfbs', 'has_entrusted_acceptance'] as $key) if (! is_bool($raw[$key] ?? null)) $this->invalid();
                if (! is_array($raw['errors'] ?? null) || ! is_array($raw['carriages'] ?? null) || ! array_is_list($raw['carriages']) || count($raw['carriages']) > 1001) $this->invalid();
                $messages = [];
                if (! array_is_list($raw['errors']) || count($raw['errors']) > 50) $this->invalid();
                foreach ($raw['errors'] as $e) {
                    if (! is_array($e)) $this->invalid();
                    $severity = strtolower($this->text($e['status'] ?? null, 20));
                    if (! in_array($severity, ['warning', 'info', 'error'], true)) $this->invalid();
                    $messages[] = ['code' => $this->text($e['code'] ?? null, 200), 'severity' => $severity, 'text' => $this->text($e['description'] ?? null, 2000)];
                }
                $carriages = []; $ids = [];
                foreach ($raw['carriages'] as $c) {
                    if (! is_array($c) || ! is_bool($c['all_blr_traceable'] ?? null) || ! is_array($c['available_actions'] ?? null)
                        || ! array_is_list($c['available_actions']) || ! is_int($c['postings_count'] ?? null) || $c['postings_count'] < 0) $this->invalid();
                    $cid = ($c['id'] ?? null) === 0 || ($c['id'] ?? null) === '0' ? '0' : $this->id($c['id'] ?? null);
                    $key = $cid.':'.(int) $c['all_blr_traceable']; if (isset($ids[$key])) $this->invalid(); $ids[$key] = true;
                    $carriages[] = ['carriage_id' => $cid, 'all_blr_traceable' => $c['all_blr_traceable'], 'postings_count' => $c['postings_count'],
                        'can_create' => in_array('create', $c['available_actions'], true), 'vendor_state' => $this->text($c['status'] ?? null, 80, true),
                        'estimated_pickup_fee' => $this->fee($c['pickup_fee'] ?? null)];
                }
                $methods[$id] = ['delivery_method_id' => $id, 'warehouse_id' => $this->id($raw['warehouse_id'] ?? null),
                    'name' => $this->text($raw['delivery_method_name'] ?? null, 500), 'departure_date' => $day,
                    'integration_type' => $this->text($raw['integration_type'] ?? null, 50), 'is_rfbs' => $raw['is_rfbs'],
                    'has_entrusted_acceptance' => $raw['has_entrusted_acceptance'], 'has_errors' => count(array_filter($messages, fn ($e) => $e['severity'] === 'error')) > 0, 'platform_messages' => $messages,
                    'dropoff_address' => isset($raw['dropoff_address']) ? $this->text($raw['dropoff_address'], 1000, true) : null,
                    'dropoff_point_id' => isset($raw['dropoff_point_id']) && (string) $raw['dropoff_point_id'] !== '0' ? $this->id($raw['dropoff_point_id']) : null,
                    'carriages' => $carriages];
                $bytes += strlen(json_encode($methods[$id], JSON_THROW_ON_ERROR));
                if ($bytes > 3145728) throw new ManagerDataException('Полный контекст методов превышает размер проверки в чате.', 422);
            }
            if (! $d['has_next']) { ksort($methods, SORT_STRING); return array_values($methods); }
            $next = $d['cursor'] ?? null;
            if (! is_string($next) || $next === '' || strlen($next) > 4000 || $next === $cursor || isset($seen[$next]) || ! $d['methods']) $this->invalid();
            $seen[$next] = true; $cursor = $next;
        }
        throw new ManagerDataException('Полнота методов доставки не подтверждена в пределах 1000 записей.', 422);
    }

    public function context(ManagerStore $store, array $input): array
    {
        $type = $input['operation_type'] ?? null;
        if (! in_array($type, ['fbs_carriage_create', 'fbs_carriage_approve'], true)) throw new ManagerDataException('Нужен точный тип действия с отгрузкой.', 422);
        $create = $type === 'fbs_carriage_create';
        if ($create) {
            $at = $input['departure_at'] ?? null;
            if (! is_string($at) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $at) || strtotime($at) === false
                || ! is_bool($input['all_blr_traceable'] ?? null) || ($input['carriage_id'] ?? null) !== null) throw new ManagerDataException('Нужны дата со временем/часовым поясом и точный вид отгрузки.', 422);
            $day = $this->day(substr($at, 0, 10));
            $parsedAt = new \DateTimeImmutable($at);
            if ($parsedAt->format('Y-m-d\TH:i:s') !== substr($at, 0, 19) || abs($parsedAt->getOffset()) > 14 * 3600) throw new ManagerDataException('Нужны действительные время и часовой пояс отгрузки.', 422);
            $methodId = $this->id($input['delivery_method_id'] ?? null); $trace = $input['all_blr_traceable']; $carriage = null;
        } else {
            $carriage = $this->carriage($store, $this->id($input['carriage_id'] ?? null));
            if ($carriage['vendor_state'] !== 'new') throw new ManagerDataException('Отдельное подтверждение доступно только для новой перевозки.', 422);
            $day = $carriage['departure_date']; $methodId = $carriage['delivery_method_id']; $trace = $carriage['all_blr_traceable']; $at = null;
            if (($input['departure_at'] ?? null) !== null || ($input['delivery_method_id'] ?? null) !== null || ($input['all_blr_traceable'] ?? null) !== null) throw new ManagerDataException('Параметры подтверждения берутся из существующей перевозки.', 422);
        }
        $method = $this->method($store, $day, $methodId);
        if ($method['is_rfbs'] || $method['integration_type'] !== 'ozon' || $method['has_errors']) throw new ManagerDataException('Ozon не подтвердил доступную FBS-отгрузку этого метода.', 422);
        $matches = array_values(array_filter($method['carriages'], fn ($r) => $r['carriage_id'] === ($create ? '0' : $carriage['carriage_id']) && $r['all_blr_traceable'] === $trace));
        if (count($matches) !== 1 || ($create && ! $matches[0]['can_create']) || (! $create && $matches[0]['vendor_state'] !== 'new')) throw new ManagerDataException('Ozon не подтвердил выбранную отгрузку или возможность создания.', 422);
        $candidate = $matches[0];
        if ($candidate['postings_count'] < 1 || $candidate['postings_count'] > self::MAX_POSTINGS) throw new ManagerDataException('Нужен проверенный состав из 1–1000 отправлений.', 422);
        $ready = $this->ready($store, $method, $trace);
        $postings = $create ? $ready : $this->members($store, $carriage['carriage_id'], $candidate['postings_count'], $trace);
        if (count($postings) !== $candidate['postings_count']) throw new ManagerDataException('Число готовых отправлений не совпадает с отгрузкой Ozon. Полнота не подтверждена.', 422);
        if (! $create) {
            $readyMap = array_column($ready, null, 'external_id');
            foreach ($postings as $p) if ($p['vendor_state'] !== 'awaiting_deliver' || ! isset($readyMap[$p['external_id']]) || $this->composition([$p]) !== $this->composition([$readyMap[$p['external_id']]])) throw new ManagerDataException('Готовность или состав отправлений перевозки изменились.', 422);
            foreach ($postings as &$p) {
                $order = $readyMap[$p['external_id']]['order_id'] ?? null;
                if ($order === null || isset($p['order_id']) && $p['order_id'] !== $order) $this->invalid();
                $p['order_id'] = $order;
            }
            unset($p);
            if ($carriage['warehouse_id'] !== $method['warehouse_id']) $this->invalid();
        }
        $containers = $input['containers_count'] ?? null;
        if (! $create && ($method['has_entrusted_acceptance'] ? ! is_int($containers) || $containers < 1 || $containers > 2147483647 : $containers !== null)) throw new ManagerDataException('Грузовые места нужно указать только при подтверждённой доверительной приёмке.', 422);
        if ($create && $containers !== null) throw new ManagerDataException('Количество мест согласуется отдельно при подтверждении созданной перевозки.', 422);
        $orders = [];
        foreach ($postings as $p) {
            if (! isset($p['order_id'])) $this->invalid();
            $orders[] = ManagerFbsPublicationGuard::ozonOrderTarget($this->id($store->clientId), $p['order_id']);
        }
        $orders = array_values(array_unique($orders)); sort($orders, SORT_STRING);
        // Creating a draft does not pass a containers_count even when entrusted acceptance is configured.
        return app(ManagerFbsExecutionPolicy::class)->decorate($store, ['operation_type' => $type, 'method' => $this->methodBasis($method), 'carriage_id' => $create ? null : $carriage['carriage_id'],
            'partition' => $create ? null : $carriage['partition'],
            'departure_at' => $at, 'all_blr_traceable' => $trace, 'containers_count' => $containers,
            'estimated_pickup_fee' => $candidate['estimated_pickup_fee'], 'actual_fee_verified' => false,
            'automatic_membership' => $create, 'postings' => $postings, 'posting_count' => count($postings),
            'total_units' => array_sum(array_map(fn ($p) => array_sum(array_column($p['items'], 'quantity')), $postings)), '_order_targets' => $orders]);
    }

    public function observe(ManagerStore $store, array $plan, string $id): array
    {
        $c = $this->carriage($store, $id); $method = $this->method($store, $c['departure_date'], $c['delivery_method_id']);
        $rows = array_values(array_filter($method['carriages'], fn ($r) => $r['carriage_id'] === $id && $r['all_blr_traceable'] === $c['all_blr_traceable']));
        if (count($rows) !== 1) $this->invalid();
        $members = $this->members($store, $id, $rows[0]['postings_count'], $c['all_blr_traceable']);
        $same = $this->composition($members) === $this->composition($plan['postings']) && $c['delivery_method_id'] === $plan['method']['delivery_method_id']
            && $c['warehouse_id'] === $plan['method']['warehouse_id'] && $c['departure_date'] === $plan['method']['departure_date'] && $c['all_blr_traceable'] === $plan['all_blr_traceable'];
        if ($plan['operation_type'] === 'fbs_carriage_approve' && $c['partition'] !== $plan['partition']) $same = false;
        $confirmed = in_array($c['vendor_state'], ['formed', 'sended', 'received', 'closed'], true)
            && (! $plan['method']['has_entrusted_acceptance'] || $c['containers_count'] === $plan['containers_count']);
        return ['composition_verified' => $same, 'vendor_state' => $c['vendor_state'], 'observed_posting_count' => count($members),
            'carriage_created_verified' => $same && $c['vendor_state'] === 'new', 'confirmation_verified' => $same && $confirmed,
            'carriage_partition' => $c['partition'], 'observed_at' => now()->utc()->toRfc3339String()];
    }

    public function submit(ManagerStore $store, array $plan): array
    {
        try {
            return app(ManagerFbsExecutionPolicy::class)->dispatch($store, $plan['_execution_control'] ?? null, fn () => $this->submitNative($store, $plan));
        } catch (ManagerFbsExecutionStopped|ManagerDataException $e) {
            return ['status' => 'failed', 'acknowledged' => false, 'stopped_before_native' => true, 'control_blocked' => true, 'carriage_id' => null];
        } catch (\Throwable) {
            return ['status' => 'unknown', 'acknowledged' => false, 'carriage_id' => null];
        }
    }

    private function submitNative(ManagerStore $store, array $plan): array
    {
        $create = $plan['operation_type'] === 'fbs_carriage_create';
        $path = $create ? '/v1/carriage/create' : '/v1/carriage/approve';
        $body = $create ? ['delivery_method_id' => (int) $plan['method']['delivery_method_id'], 'departure_date' => $plan['departure_at'], 'all_blr_traceable' => $plan['all_blr_traceable']]
            : ['carriage_id' => (int) $plan['carriage_id'], ...($plan['containers_count'] === null ? [] : ['containers_count' => $plan['containers_count']])];
        try {
            $r = $this->client($store)->post('https://api-seller.ozon.ru'.$path, $body);
            if (in_array($r->status(), [400, 401, 403, 404, 409, 422], true)) return ['status' => 'failed', 'acknowledged' => false, 'carriage_id' => null];
            if ($r->status() !== 200 || strlen($r->body()) > 65536) throw new \RuntimeException;
            if ($create) { $d = json_decode($r->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); $id = $this->id($d['carriage_id'] ?? null); }
            else { if (! in_array(trim($r->body()), ['', '{}'], true)) throw new \RuntimeException; $id = $plan['carriage_id']; }
            return ['status' => 'verifying', 'acknowledged' => true, 'carriage_id' => $id];
        } catch (\Throwable) { return ['status' => 'unknown', 'acknowledged' => false, 'carriage_id' => null]; }
    }

    public function composition(array $postings): array
    {
        return array_map(fn ($p) => ['external_id' => $p['external_id'], 'items' => array_map(fn ($i) => ['sku' => $i['sku'], 'offer_id' => $i['offer_id'], 'quantity' => $i['quantity']], $p['items'])], $postings);
    }

    private function ready(ManagerStore $store, array $method, bool $trace): array
    {
        $cursor = ''; $seen = []; $rows = []; $items = 0; $bytes = 0;
        for ($page = 0; $page < 10; $page++) {
            $d = $this->read($store, '/v4/posting/fbs/list', ['filter' => ['since' => now()->utc()->subDays(364)->startOfDay()->toRfc3339String(), 'to' => now()->utc()->toRfc3339String(),
                'statuses' => ['awaiting_deliver'], 'delivery_method_ids' => [$method['delivery_method_id']], 'warehouse_ids' => [$method['warehouse_id']], 'integration_type_flow' => ['ozon'], 'is_blr_traceable' => $trace],
                'limit' => 100, 'cursor' => $cursor, 'sort_dir' => 'ASC', 'with' => ['analytics_data' => false, 'financial_data' => false, 'barcodes' => false, 'legal_info' => false]]);
            if (! is_array($d['postings'] ?? null) || ! array_is_list($d['postings']) || count($d['postings']) > 100 || ! is_bool($d['has_next'] ?? null)) $this->invalid();
            foreach ($d['postings'] as $p) {
                if (! is_array($p) || ($p['status'] ?? null) !== 'awaiting_deliver' || $this->id($p['delivery_method']['id'] ?? null) !== $method['delivery_method_id']
                    || $this->id($p['delivery_method']['warehouse_id'] ?? null) !== $method['warehouse_id']) $this->invalid();
                $one = $this->posting($p); if (isset($rows[$one['external_id']])) $this->invalid();
                $bytes += strlen(json_encode($one, JSON_THROW_ON_ERROR)); if ($bytes > 3145728) throw new ManagerDataException('Полный состав превышает размер проверки в чате. Частичная отправка не разрешается.', 422);
                $rows[$one['external_id']] = $one; $items += count($one['items']); if ($items > 10000) throw new ManagerDataException('Слишком большой состав группы для проверки в чате.', 422);
            }
            if (! $d['has_next']) { ksort($rows, SORT_STRING); return array_values($rows); }
            $next = $d['cursor'] ?? null;
            if (! is_string($next) || $next === '' || strlen($next) > 4000 || $next === $cursor || isset($seen[$next]) || ! $d['postings']) $this->invalid();
            $seen[$next] = true; $cursor = $next;
        }
        throw new ManagerDataException('Полнота готовых отправлений не подтверждена в пределах 1000 записей.', 422);
    }

    private function members(ManagerStore $store, string $id, int $expected, bool $trace): array
    {
        $d = $this->read($store, '/v2/posting/fbs/act/get-postings', ['id' => (int) $id]); $rows = $d['result'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== $expected || ! $rows || count($rows) > self::MAX_POSTINGS) $this->invalid();
        $out = [];
        foreach ($rows as $p) {
            if (! is_array($p) || $this->id($p['id'] ?? null) !== $id || ($p['is_blr_traceable'] ?? null) !== $trace || (isset($p['seller_error']) && $p['seller_error'] !== '')) $this->invalid();
            $one = $this->posting($p); if (isset($out[$one['external_id']])) $this->invalid(); $out[$one['external_id']] = $one;
        }
        ksort($out, SORT_STRING); return array_values($out);
    }

    private function posting(array $p): array
    {
        $id = $this->text($p['posting_number'] ?? null, 100);
        if (! preg_match('/^[0-9]+(?:-[0-9]+){1,4}$/D', $id) || ! is_array($p['products'] ?? null) || ! array_is_list($p['products']) || ! $p['products'] || count($p['products']) > 1000) $this->invalid();
        $items = [];
        foreach ($p['products'] as $item) {
            if (! is_array($item) || ! is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || $item['quantity'] > 2147483647) $this->invalid();
            $sku = $this->id($item['sku'] ?? null); if (isset($items[$sku])) $this->invalid();
            $items[$sku] = ['sku' => $sku, 'offer_id' => $this->text($item['offer_id'] ?? null, 1000), 'name' => isset($item['name']) ? $this->text($item['name'], 4000, true) : null, 'quantity' => $item['quantity']];
        }
        ksort($items, SORT_STRING);
        return ['external_id' => $id, 'vendor_state' => $this->text($p['status'] ?? null, 80), 'items' => array_values($items),
            ...(array_key_exists('order_id', $p) ? ['order_id' => $this->id($p['order_id'])] : [])];
    }

    private function carriage(ManagerStore $store, string $id): array
    {
        $d = $this->read($store, '/v1/carriage/get', ['carriage_id' => (int) $id]);
        if ($this->id($d['carriage_id'] ?? null) !== $id || ($d['integration_type'] ?? null) !== 'ozon' || ! is_bool($d['all_blr_traceable'] ?? null)
            || ! is_int($d['containers_count'] ?? null) || $d['containers_count'] < 0 || ! is_bool($d['is_partial'] ?? null)
            || ! is_bool($d['has_postings_for_next_carriage'] ?? null) || ! is_int($d['partial_num'] ?? null) || $d['partial_num'] < 0 || $d['partial_num'] > 2147483647) $this->invalid();
        return ['carriage_id' => $id, 'delivery_method_id' => $this->id($d['delivery_method_id'] ?? null), 'warehouse_id' => $this->id($d['warehouse_id'] ?? null),
            'departure_date' => $this->dateDay($d['departure_date'] ?? null), 'vendor_state' => $this->text($d['status'] ?? null, 80),
            'all_blr_traceable' => $d['all_blr_traceable'], 'containers_count' => $d['containers_count'],
            'partition' => ['is_partial' => $d['is_partial'], 'partial_num' => $d['partial_num'], 'has_postings_for_next_carriage' => $d['has_postings_for_next_carriage']]];
    }

    private function method(ManagerStore $store, string $day, string $id): array
    {
        $rows = array_values(array_filter($this->methods($store, $day), fn ($m) => $m['delivery_method_id'] === $id));
        if (count($rows) !== 1) throw new ManagerDataException('Метод доставки не найден в полной выборке Ozon.', 422);
        return $rows[0];
    }

    private function methodBasis(array $m): array { unset($m['carriages'], $m['has_errors'], $m['is_rfbs'], $m['integration_type']); return $m; }
    private function fee(mixed $v): ?array
    {
        if ($v === null) return null;
        if (! is_array($v) || ! is_string($v['currency_code'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $v['currency_code']) || ! is_numeric($v['value'] ?? null)
            || ! is_finite((float) $v['value']) || (float) $v['value'] < 0) $this->invalid();
        return ['amount_approx' => is_string($v['value']) ? $v['value'] : json_encode($v['value'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'currency' => $v['currency_code'], 'final_amount_verified' => false];
    }
    private function read(ManagerStore $store, string $path, array $body): array
    {
        try {
            $r = $this->client($store)->post('https://api-seller.ozon.ru'.$path, $body);
            if (! $r->successful() || strlen($r->body()) > 4194304) $this->invalid();
            $d = json_decode($r->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($d) || array_is_list($d)) $this->invalid();
            return $d;
        } catch (ManagerDataException $e) { throw $e; }
        catch (\Throwable) { throw new ManagerDataException('Ozon не подтвердил текущий контекст перевозки.'); }
    }
    private function client(ManagerStore $s)
    {
        if ($s->marketplace !== 'ozon' || ! $s->clientId) $this->invalid();
        return Http::acceptJson()->withHeaders(['Api-Key' => $s->apiKey, 'Client-Id' => $s->clientId])->connectTimeout(3)->timeout(15)
            ->withOptions(['allow_redirects' => false, 'progress' => static function ($total, $downloaded) { if ($total > 4194304 || $downloaded > 4194304) throw new \RuntimeException; }]);
    }
    private function id(mixed $v): string
    {
        $s = is_string($v) || is_int($v) ? (string) $v : '';
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $s) || (strlen($s) === 19 && strcmp($s, (string) PHP_INT_MAX) > 0)) $this->invalid(); return $s;
    }
    private function text(mixed $v, int $max, bool $empty = false): string
    {
        if (! is_string($v) || (! $empty && trim($v) === '') || mb_strlen($v) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $v)) $this->invalid(); return $v;
    }
    private function dateDay(mixed $v): string { if (! is_string($v) || strlen($v) > 40) $this->invalid(); return $this->day(substr($v, 0, 10)); }
    private function day(string $v): string { $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v); if (! $d || $d->format('Y-m-d') !== $v) $this->invalid(); return $v; }
    private function invalid(): never { throw new ManagerDataException('Состав, идентификаторы или полнота перевозки Ozon не подтверждены.'); }
}
