<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\AutoSupplyPlan;
use App\Models\ManagerOzonFboOperation as Operation;
use App\Models\Product;
use App\Services\AutoSupplyPlanning\OzonPlanMaterializationService;

/** The calculator owns quantities; Native Ozon owns SKU, destinations and availability. */
final class ManagerOzonFboPlan
{
    public const KEYS = ['operation_type', 'plan_id', 'native_cluster_id', 'draft_operation_id', 'warehouse_id', 'date_from', 'date_to', 'slot_key'];

    public function __construct(private ManagerOzonFboGateway $gateway, private OzonPlanMaterializationService $materializer) {}

    public function build(ManagerIdentity $who, ManagerStore $store, array $selection): array
    {
        if (count($selection) !== count(self::KEYS) || array_diff(array_keys($selection), self::KEYS)) {
            $this->bad();
        }
        $type = $selection['operation_type'];
        if (! in_array($type, ['fbo_ozon_draft_create', 'fbo_ozon_supply_create'], true)) {
            $this->bad();
        }
        $parent = null;
        if ($type === 'fbo_ozon_supply_create') {
            foreach (['plan_id', 'native_cluster_id'] as $k) {
                if ($selection[$k] !== null) {
                    $this->bad();
                }
            }
            $parent = Operation::whereKey($selection['draft_operation_id'])->where('workspace_id', $who->workspaceId)->where('user_id', $who->userId)
                ->where('integration_id', $store->integrationId)->where('operation_type', 'fbo_ozon_draft_create')->where('status', 'succeeded')->first();
            if (! $parent || $parent->store_binding !== $store->binding || $parent->store_name !== $store->name || ! $parent->native_draft_id
                || ($parent->result['draft_created_verified'] ?? false) !== true) {
                throw new ManagerDataException('Нужен проверенный личный черновик этого магазина.', 409);
            }
            $parent = app(ManagerOzonFboService::class)->get($who, $parent->id);
            $planId = $parent->plan['plan_id'];
            $nativeCluster = $parent->plan['cluster']['native_cluster_id'];
        } else {
            foreach (['draft_operation_id', 'warehouse_id', 'date_from', 'date_to', 'slot_key'] as $k) {
                if ($selection[$k] !== null) {
                    $this->bad();
                }
            }
            $planId = $selection['plan_id'];
            $nativeCluster = $selection['native_cluster_id'];
        }
        $plan = AutoSupplyPlan::whereKey($planId)->where('integration_id', $store->integrationId)->where('marketplace', 'ozon')->first();
        if (! $plan) {
            throw new ManagerDataException('План не найден в выбранном магазине.', 404);
        }
        $localVersion = $this->localVersion($plan);
        $check = $this->materializer->approvalCheck($plan);
        if (! $check['allowed'] || $plan->business_status !== AutoSupplyPlan::BUSINESS_STATUS_APPROVED || ! $plan->approval_fingerprint
            || ! hash_equals($plan->approval_fingerprint, (string) $check['fingerprint']) || $plan->materialized_at !== null
            || ($plan->params['supply_method'] ?? 'direct') !== 'direct') {
            throw new ManagerDataException('Нужен актуальный утверждённый прямой план без другого исполнения. Проверьте план в разделе поставок.', 409);
        }
        $quality = $plan->plan_quality_audit;
        if (($quality['acceptance_gates']['can_create_ozon_draft'] ?? null) !== true) {
            throw new ManagerDataException('Не подтверждён допуск плана к черновику Ozon.', 422);
        }
        $clusters = array_values(array_filter($this->gateway->clusters($store), fn ($c) => $c['native_cluster_id'] === $nativeCluster));
        if (count($clusters) !== 1 || $clusters[0]['macrolocal_cluster_id'] === null) {
            throw new ManagerDataException('Ozon не подтвердил макрокластер назначения.', 422);
        }
        $cluster = $clusters[0];
        unset($cluster['warehouses']);
        $selected = $plan->params['cluster_ids'] ?? [];
        if ($selected !== [] && ! in_array((string) $nativeCluster, array_map('strval', $selected), true)) {
            $this->bad();
        }
        $lines = $plan->lines()->where('cluster_id', $nativeCluster)->where('qty_rounded', '>', 0)->where('is_excluded', false)
            ->where(fn ($q) => $q->where('is_cluster_split', true)->orWhere('destination_type', 'cluster'))->orderBy('id')->get();
        if ($lines->isEmpty() || $lines->count() > 5000) {
            $this->bad();
        }
        $offers = $lines->map(fn ($l) => $l->offer_id)->all();
        $native = $this->gateway->verifiedProducts($store, $offers);
        $items = [];
        $units = 0;
        foreach ($lines as $line) {
            $product = Product::where('integration_id', $store->integrationId)->where('marketplace', 'ozon')->where('sku', $line->sku)->first();
            $n = $native[$line->offer_id];
            $storedSku = $product?->ozon_data['sku'] ?? null;
            if (! is_int($storedSku) && ! is_string($storedSku) || (string) $storedSku !== $n['sku'] || $n['is_archived'] === true || $n['is_discounted'] === true
                || ! is_int($line->qty_rounded) || $line->qty_rounded < 1 || $line->qty_rounded > 2147483647) {
                throw new ManagerDataException('Товар или SKU плана не совпадает с актуальным Ozon.', 409);
            }
            $items[] = ['row_id' => (string) $line->id, 'offer_id' => $n['offer_id'], 'product_id' => $n['product_id'], 'sku' => $n['sku'],
                'name' => $n['name'], 'quantity' => $line->qty_rounded];
            $units += $line->qty_rounded;
        }
        $base = ['operation_type' => $type, 'plan_id' => (string) $plan->id, 'cluster' => $cluster, 'items' => $items, 'total_units' => $units,
            'draft_id' => $parent?->native_draft_id, 'warehouse' => null, 'slot' => null, 'financial_verified' => false, 'physical_status' => null,
            'warnings' => ['planning_inputs_require_review', 'economics_and_final_acceptance_cost_unverified'],
            '_selection' => $selection, '_approval' => $plan->approval_fingerprint, '_facts' => $plan->validation_json['facts_hash'],
            '_account' => $store->clientId, '_parent' => $parent?->id, '_local_version' => $localVersion];
        if ($parent && ($parent->plan['items'] !== $items || $parent->plan['cluster'] !== $cluster || $parent->plan['_approval'] !== $base['_approval'] || $parent->plan['_facts'] !== $base['_facts'])) {
            throw new ManagerDataException('План или исходные факты изменились после создания черновика.', 409);
        }
        if ($parent) {
            $warehouse = $this->warehouse($store, $parent->native_draft_id, $base, $selection['warehouse_id']);
            $calendar = $this->gateway->timeslots($store, $parent->native_draft_id, $cluster['macrolocal_cluster_id'], $warehouse['id'], $selection['date_from'], $selection['date_to']);
            $slots = array_values(array_filter($calendar['slots'] ?? [], fn ($v) => $v['slot_key'] === $selection['slot_key'] && $v['eligible_time']));
            if (! $calendar['quoted'] || count($slots) !== 1) {
                throw new ManagerDataException('Выбранное время больше не подтверждено Ozon.', 409);
            }
            $base['warehouse'] = $warehouse;
            $base['slot'] = $slots[0];
        }

        if ($localVersion !== $this->localVersion($plan->fresh())) {
            throw new ManagerDataException('План изменился во время проверки.', 409);
        }

        return $base;
    }

    public function warehouse(ManagerStore $store, string $draft, array $plan, string $warehouseId): array
    {
        $info = $this->gateway->draftInfo($store, $draft);
        if ($info['status'] !== 'SUCCESS' || ! empty($info['errors'])) {
            throw new ManagerDataException('Черновик ещё не рассчитан или отклонён.', 409);
        }
        $matches = [];
        foreach ($info['clusters'] ?? [] as $cluster) {
            if ($cluster['macrolocal_cluster_id'] !== $plan['cluster']['macrolocal_cluster_id'] || $cluster['supply_type'] !== 'DIRECT') {
                continue;
            }
            foreach ($cluster['warehouses'] as $wh) {
                if ($wh['id'] === $warehouseId) {
                    $matches[] = $wh;
                }
            }
        }
        if (count($matches) !== 1 || $matches[0]['availability_state'] !== 'FULL_AVAILABLE' || ! in_array($matches[0]['invalid_reason'], [null, 'UNSPECIFIED'], true) || $matches[0]['bundle_id'] === null) {
            throw new ManagerDataException('Полная приёмка выбранного состава складом не подтверждена.', 409);
        }
        $wh = $matches[0];
        $accepted = $this->gateway->bundle($store, $wh['bundle_id']);
        if (! $this->sameItems($plan['items'], $accepted['items'])) {
            throw new ManagerDataException('Ozon изменил состав или количество. Нужен новый план.', 409);
        }
        if ($wh['restricted_bundle_id'] !== null && $this->gateway->bundle($store, $wh['restricted_bundle_id'])['items'] !== []) {
            throw new ManagerDataException('В черновике есть отклонённые товары.', 409);
        }

        return $wh;
    }

    public function localVersion(AutoSupplyPlan $plan): string
    {
        $lines = $plan->lines()->orderBy('id')->get()->map(fn ($line) => $line->getAttributes())->all();
        $products = Product::where('integration_id', $plan->integration_id)->where('marketplace', 'ozon')
            ->whereIn('sku', array_column($lines, 'sku'))->orderBy('id')->get(['id', 'sku', 'ozon_data', 'updated_at'])->map(fn ($p) => $p->getAttributes())->all();

        return hash('sha256', self::canonical([$plan->getAttributes(), $lines, $products]));
    }

    public function sameItems(array $expected, array $observed): bool
    {
        $map = function ($rows) {
            $out = [];
            foreach ($rows as $r) {
                if (isset($out[$r['sku']])) {
                    return null;
                } $out[$r['sku']] = $r['quantity'];
            } ksort($out, SORT_STRING);

            return $out;
        };

        return $map($expected) !== null && $map($expected) === $map($observed);
    }

    public static function canonical(mixed $value): string
    {
        $n = function ($v) use (&$n) {
            if (! is_array($v)) {
                return $v;
            } if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($n, $v);
        };

        return json_encode($n($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function publicPlan(array $plan): array
    {
        return array_filter($plan, fn ($key) => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    private function bad(): never
    {
        throw new ManagerDataException('Нужен точный выбор из плана и ответа Ozon.', 422);
    }
}
