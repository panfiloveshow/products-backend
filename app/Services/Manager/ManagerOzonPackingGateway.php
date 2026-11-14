<?php

namespace App\Services\Manager;

use Illuminate\Support\Facades\Http;

final class ManagerOzonPackingGateway
{
    public const TYPE = 'fbs_ozon_pack_group';
    public const MAX_SOURCES = 100;
    public const MAX_PACKAGES = 1000;
    private const ROOT = 'https://api-seller.ozon.ru';

    public function __construct(private ManagerFbsGateway $fbs) {}

    public function selection(array $rows): array
    {
        if (! array_is_list($rows) || ! $rows || count($rows) > self::MAX_SOURCES) $this->bad('Нужны 1–100 явных отправлений Ozon.', 422);
        $out = []; $packageCount = 0; $lines = 0;
        foreach ($rows as $row) {
            $this->keys($row, ['posting_number', 'packages']); $number = $this->number($row['posting_number']);
            if (isset($out[$number])) $this->bad('Отправление повторяется.', 422);
            if (! is_array($row['packages']) || ! array_is_list($row['packages']) || ! $row['packages'] || count($row['packages']) > 100) $this->bad('Нужны 1–100 явных упаковок на отправление.', 422);
            $packages = [];
            foreach ($row['packages'] as $package) {
                $this->keys($package, ['products']);
                if (! is_array($package['products']) || ! array_is_list($package['products']) || ! $package['products'] || count($package['products']) > 100) $this->bad('Нужен полный состав каждой упаковки.', 422);
                $products = [];
                foreach ($package['products'] as $product) {
                    $this->keys($product, ['sku', 'quantity']); $sku = $this->id($product['sku']);
                    if (isset($products[$sku]) || ! is_int($product['quantity']) || $product['quantity'] < 1 || $product['quantity'] > 2147483647) $this->bad('Нужны уникальные SKU и точные положительные количества.', 422);
                    $products[$sku] = ['sku' => $sku, 'quantity' => $product['quantity']];
                    if (++$lines > 10000) $this->bad('В группе допускается до 10000 товарных строк упаковок.', 422);
                }
                ksort($products, SORT_STRING); $packages[] = ['products' => array_values($products)];
                if (++$packageCount > self::MAX_PACKAGES) $this->bad('В группе допускается до 1000 упаковок.', 422);
            }
            $out[$number] = ['posting_number' => $number, 'packages' => $packages];
        }
        return array_values($out);
    }

    public function context(ManagerStore $store, array $rows): array
    {
        $selection = $this->selection($rows); $sources = array_map(fn ($row) => $this->source($store, $row), $selection);
        return $this->assemble($store, $selection, $sources);
    }

    public function onePackageContext(ManagerStore $store, array $numbers): array
    {
        if (! array_is_list($numbers) || ! $numbers || count($numbers) > self::MAX_SOURCES) $this->bad('Нужны 1–100 точных номеров отправлений.', 422);
        $selection = []; $sources = []; $seen = [];
        foreach ($numbers as $number) {
            $number = $this->number($number); if (isset($seen[$number])) $this->bad('Отправление повторяется.', 422); $seen[$number] = true;
            $checked = $this->fbs->ozonPosting($store, $number);
            $row = $this->selection([['posting_number' => $number, 'packages' => [['products' => array_map(fn ($p) => ['sku' => $p['marketplace_product_id'], 'quantity' => $p['quantity']], $checked['posting']['items'])]]]])[0];
            $selection[] = $row; $sources[] = $this->checkedSource($store, $row, $checked);
        }
        return $this->assemble($store, $this->selection($selection), $sources);
    }

    private function assemble(ManagerStore $store, array $selection, array $sources): array
    {
        $targets = []; $units = 0; $packages = 0;
        foreach ($sources as $source) {
            $targets[] = $source['posting']['external_id']; $targets[] = $this->orderTarget($store, $source['posting']['order_id']);
            $units += $source['total_units']; $packages += $source['package_count'];
        }
        $targets = array_values(array_unique($targets)); sort($targets, SORT_STRING);
        $plan = ['operation_type' => self::TYPE, 'sources' => $sources, 'source_count' => count($sources), 'package_count' => $packages, 'total_units' => $units,
            'failure_policy' => 'stop_after_unknown_or_refusal', 'physical_status' => null, 'actual_cost_verified' => false, '_selection' => $selection, '_targets' => $targets];
        if (strlen(json_encode($plan, JSON_THROW_ON_ERROR)) > 3145728) $this->bad('Состав группы превышает допустимый объём.', 422);
        return $plan;
    }

    public function source(ManagerStore $store, array $selection): array
    {
        return $this->checkedSource($store, $selection, $this->fbs->ozonPosting($store, $selection['posting_number']));
    }

    private function checkedSource(ManagerStore $store, array $selection, array $checked): array
    {
        $p = $checked['posting'];
        if ($p['vendor_state'] !== 'awaiting_packaging' || $checked['pack_blockers'] || ! isset($p['order_id']) || $p['warehouse_id'] === null)
            $this->bad('Отправление требует проверки состояния, маркировки, коробок или схемы доставки в разделе заказов.', 422);
        sort($p['available_actions'], SORT_STRING);
        $requirements = (array) $p['requirements']; foreach ($requirements as &$values) sort($values, SORT_STRING); unset($values); ksort($requirements, SORT_STRING); $p['requirements'] = (object) $requirements;
        $order = $this->id($p['order_id']); $quantities = [];
        foreach ($p['items'] as $item) $quantities[$item['marketplace_product_id']] = $item['quantity'];
        $allocated = [];
        foreach ($selection['packages'] as $package) foreach ($package['products'] as $item) $allocated[$item['sku']] = ($allocated[$item['sku']] ?? 0) + $item['quantity'];
        ksort($quantities, SORT_STRING); ksort($allocated, SORT_STRING);
        if ($quantities !== $allocated) $this->bad('Упаковки должны точно распределять все товары и количества исходного отправления.', 422);
        return app(ManagerFbsExecutionPolicy::class)->decorate($store, ['posting' => ['external_id' => $p['external_id'], 'order_id' => $order, 'vendor_state' => $p['vendor_state'], 'substatus' => $p['substatus'],
            'warehouse_id' => $p['warehouse_id'], 'warehouse_name' => $p['warehouse_name'], 'shipment_deadline' => $p['shipment_deadline'], 'items' => $p['items'], 'physical_status' => null],
            'packages' => $selection['packages'], 'package_count' => count($selection['packages']), 'total_units' => array_sum($quantities), 'pack_requirements_verified' => true,
            '_before' => $p, '_order_target' => $this->orderTarget($store, $order)]);
    }

    public function publicPlan(array $p): array
    {
        unset($p['_selection'], $p['_targets']);
        $p['sources'] = array_map(function ($source) { unset($source['_before'], $source['_order_target'], $source['_execution_control']); return $source; }, $p['sources']);
        return $p;
    }

    public function submit(ManagerStore $store, array $source): array
    {
        try {
            return app(ManagerFbsExecutionPolicy::class)->dispatch($store, $source['_execution_control'] ?? null, fn () => $this->submitNative($store, $source));
        } catch (ManagerFbsExecutionStopped|ManagerDataException $e) {
            return ['status' => 'failed', 'acknowledged' => false, 'stopped_before_native' => true, 'control_blocked' => true, 'posting_numbers' => []];
        } catch (\Throwable) {
            return ['status' => 'unknown', 'acknowledged' => false, 'posting_numbers' => []];
        }
    }

    private function submitNative(ManagerStore $store, array $source): array
    {
        $unknown = ['status' => 'unknown', 'acknowledged' => false, 'posting_numbers' => []];
        try {
            if (! config('services.manager.fbs_writes_enabled', true) || ! config('services.manager.ozon_packing_enabled', false) || ! config('services.manager.ozon_packing_outbox_enabled', false)) return array_replace($unknown, ['status' => 'failed', 'stopped_before_native' => true]);
            $body = ['posting_number' => $source['posting']['external_id'], 'packages' => array_map(fn ($package) => ['products' => array_map(fn ($p) => [
                'product_id' => (int) $this->id($p['sku']), 'quantity' => $p['quantity']], $package['products'])], $source['packages']), 'with' => ['additional_data' => false]];
            $response = Http::acceptJson()->connectTimeout(5)->timeout(45)->withOptions(['allow_redirects' => false])
                ->withHeaders(['Client-Id' => $store->clientId, 'Api-Key' => $store->apiKey])->post(self::ROOT.'/v4/posting/fbs/ship', $body);
            if (in_array($response->status(), [400, 401, 403, 404, 422], true)) return array_replace($unknown, ['status' => 'failed']);
            if ($response->status() !== 200 || strlen($response->body()) > 1048576) return $unknown;
            $data = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); $ids = $data['result'] ?? null;
            if (! is_array($ids) || ! array_is_list($ids) || ! $ids || count($ids) > 1000) return $unknown;
            foreach ($ids as $id) $this->number($id);
            $unique = array_values(array_unique($ids)); sort($unique, SORT_STRING);
            if (count($ids) !== $source['package_count'] || count($unique) !== count($ids)) return array_replace($unknown, ['posting_numbers' => $unique]);
            return ['status' => 'verifying', 'acknowledged' => true, 'posting_numbers' => $unique];
        } catch (\Throwable) { return $unknown; }
    }

    public function observe(ManagerStore $store, array $source, array $receipt): array
    {
        $observed = []; $complete = true; $scope = true;
        foreach ($receipt['posting_numbers'] as $number) {
            try {
                $p = $this->fbs->ozonPosting($store, $number)['posting'];
                $observed[] = ['external_id' => $p['external_id'], 'order_id' => $p['order_id'] ?? null, 'warehouse_id' => $p['warehouse_id'],
                    'vendor_state' => $p['vendor_state'], 'substatus' => $p['substatus'], 'parent_posting_number' => $p['parent_posting_number'] ?? null, 'items' => $p['items'], 'physical_status' => null];
                if (($p['order_id'] ?? null) !== $source['posting']['order_id'] || $p['warehouse_id'] !== $source['posting']['warehouse_id']) $scope = false;
                if ($p['external_id'] !== $source['posting']['external_id']) {
                    if (($p['parent_posting_number'] ?? '') === '') $complete = false;
                    elseif ($p['parent_posting_number'] !== $source['posting']['external_id']) $scope = false;
                }
            } catch (ManagerDataException) { $complete = false; }
        }
        $expected = array_map(fn ($p) => $this->packageKey(array_column($p['products'], 'quantity', 'sku')), $source['packages']);
        $actual = array_map(fn ($p) => $this->packageKey(array_column($p['items'], 'quantity', 'marketplace_product_id')), $observed);
        sort($expected, SORT_STRING); sort($actual, SORT_STRING);
        $composition = $complete && $scope && count($observed) === $source['package_count'] && $expected === $actual;
        foreach ($observed as &$p) $p['assembly_verified'] = $receipt['acknowledged'] && $composition && $p['vendor_state'] === 'awaiting_deliver' && $p['substatus'] !== 'ship_failed';
        unset($p);
        $failed = $composition && (bool) array_filter($observed, fn ($p) => $p['substatus'] === 'ship_failed');
        $ready = $composition && ! $failed && ! array_filter($observed, fn ($p) => $p['vendor_state'] !== 'awaiting_deliver');
        $outcome = ! $receipt['acknowledged'] ? 'unknown' : (! $scope || $complete && ! $composition ? 'unknown' : ($failed ? 'failed' : ($ready ? 'succeeded' : 'verifying')));
        return ['status' => $outcome, 'fields_verified' => $receipt['acknowledged'] && $ready, 'composition_verified' => $composition,
            'observed_postings' => $observed, 'observed_at' => now()->utc()->toRfc3339String()];
    }

    private function packageKey(array $quantities): string { ksort($quantities, SORT_STRING); return json_encode($quantities, JSON_THROW_ON_ERROR); }

    public function orderTarget(ManagerStore $store, string $order): string { return ManagerFbsPublicationGuard::ozonOrderTarget($this->id($store->clientId), $this->id($order)); }

    public function number(mixed $v): string
    {
        if (! is_string($v) || strlen($v) > 100 || ! preg_match('/^[0-9]+(?:-[0-9]+){1,4}$/D', $v)) $this->bad('Нужен точный номер отправления Ozon.', 422);
        return $v;
    }
    private function id(mixed $v): string
    {
        if ((! is_string($v) && ! is_int($v)) || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $v) || strlen((string) $v) === 19 && strcmp((string) $v, '9223372036854775807') > 0) $this->bad('Ozon не подтвердил точный идентификатор.');
        return (string) $v;
    }
    private function keys(mixed $v, array $keys): void
    {
        if (! is_array($v) || count($v) !== count($keys) || array_diff(array_keys($v), $keys)) $this->bad('Нужен точный состав параметров группы.', 422);
    }
    private function bad(string $message = 'Ozon не подтвердил состав группы.', int $status = 503): never { throw new ManagerDataException($message, $status); }
}
