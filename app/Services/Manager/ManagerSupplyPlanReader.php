<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Http\Controllers\Api\AutoSupplyPlanController;
use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use Illuminate\Http\Request;

/** Readonly projection of the existing planning owner's stored calculation. */
final class ManagerSupplyPlanReader
{
    public function __construct(private ManagerStoreResolver $stores, private AutoSupplyPlanController $plans) {}

    public function read(ManagerIdentity $identity, Request $request, int $integrationId, ?string $planId, int $page, int $limit): array
    {
        $integration = Integration::query()->whereKey($integrationId)->where('work_space_id', $identity->workspaceId)->first();
        if (! $integration || ! in_array($integration->marketplace, ['wildberries', 'ozon'], true)) {
            throw new ManagerDataException('Магазин не найден в выбранном workspace.', 404);
        }
        $plan = $planId === null ? null : AutoSupplyPlan::query()->whereKey($planId)->where('integration_id', $integrationId)->first();
        if ($planId !== null && (! $plan || $plan->marketplace !== $integration->marketplace)) {
            throw new ManagerDataException('План не найден в выбранном магазине.', 404);
        }
        $store = $this->stores->resolve($identity, $integrationId, $integration->marketplace);
        $common = ['schema_version' => 1, 'workspace_id' => (string) $identity->workspaceId, 'integration_id' => (string) $integrationId,
            'integration_name' => $store->name, 'marketplace' => $store->marketplace, 'scope' => 'existing_auto_supply_plans',
            'read_at' => now()->utc()->toIso8601String(), 'data_updated_at' => null, 'partial' => true, 'execution_allowed' => false];
        if ($plan === null) {
            $rows = AutoSupplyPlan::query()->where('integration_id', $integrationId)->where('marketplace', $store->marketplace)
                ->orderByDesc('created_at')->orderByDesc('id')->paginate($limit, ['*'], 'page', $page);

            return [...$common, 'plan' => null, 'items' => array_map(fn ($item) => $this->header($item->toArray()), $rows->items()),
                ...$this->pagination($rows->toArray(), $page, $limit)];
        }

        // Delegate aggregation, selected clusters and quantity explanations to
        // the existing owner. Never call calculate/approve/materialize/execute.
        $copy = $request->duplicate(['page' => $page, 'per_page' => $limit]);
        $response = $this->plans->show($copy, $planId);
        if ($response->getStatusCode() !== 200) {
            throw new ManagerDataException('Не удалось прочитать выбранный план.', $response->getStatusCode());
        }
        $data = $response->getData(true)['data'] ?? null;
        if (! is_array($data) || ($data['plan']['id'] ?? null) !== $planId
            || (string) ($data['plan']['integration_id'] ?? '') !== (string) $integrationId
            || ($data['plan']['marketplace'] ?? null) !== $store->marketplace || ! is_array($data['lines']['data'] ?? null)) {
            throw new ManagerDataException('Источник вернул неподтверждённый план.');
        }

        return [...$common, 'plan' => $this->header($data['plan']),
            'items' => array_map(fn (array $line) => $this->line($line), $data['lines']['data']),
            ...$this->pagination($data['lines'], $page, $limit)];
    }

    private function header(array $row): array
    {
        $effective = is_array($row['effective_params_json'] ?? null) ? $row['effective_params_json'] : [];
        $params = is_array($row['params'] ?? null) ? $row['params'] : [];

        return ['id' => (string) $row['id'], 'status' => $this->text($row['status'] ?? null),
            'business_status' => $this->text($row['business_status'] ?? null), 'mode' => $this->text($row['mode'] ?? null),
            'analysis_period_days' => $this->number($effective['analysis_period_days'] ?? null),
            'target_cover_days' => $this->number($effective['target_cover_days'] ?? null),
            'safety_stock_days' => $this->number($effective['safety_stock_days'] ?? null),
            'lead_time_days' => $this->number($effective['lead_time_days'] ?? null),
            'include_in_transit' => is_bool($effective['include_in_transit'] ?? null) ? $effective['include_in_transit'] : null,
            'configured_parameters' => [
                'analysis_period_days' => $this->number($params['analysis_period_days'] ?? $row['horizon_days'] ?? null),
                'target_cover_days' => $this->number($row['target_cover_days'] ?? null),
                'safety_stock_days' => $this->number($row['safety_stock_days'] ?? null),
                'lead_time_days' => $this->number($params['lead_time_days'] ?? null),
                'include_in_transit' => is_bool($params['include_in_transit'] ?? null) ? $params['include_in_transit'] : null,
            ],
            'quality_gate_status' => $this->text($row['quality_gate_status'] ?? null),
            'quality_gate_reasons' => $this->codes($row['quality_gate_reasons'] ?? null),
            'calculation_version' => $this->text($row['algorithm_version'] ?? null),
            // The old owner does not give a distinct calculation timestamp.
            // General model updates/approval must not refresh facts in the chat.
            'calculated_at' => null, 'last_changed_at' => $this->text($row['updated_at'] ?? null),
            'review_path' => '/products/shipments/auto-plans/'.(string) $row['id']];
    }

    private function line(array $row): array
    {
        $explanation = is_array($row['quantity_explanation'] ?? null) ? $row['quantity_explanation'] : [];
        $numbers = [];
        foreach (['daily_demand', 'target_cover_days', 'safety_stock', 'stock_now', 'in_transit', 'needed_before_caps', 'needed_after_caps', 'qty_rounded'] as $field) {
            $numbers[$field] = $this->number($explanation[$field] ?? null);
        }

        return ['row_id' => (string) $row['id'], 'offer_id' => $this->text($row['offer_id'] ?? null), 'sku' => $this->text($row['sku'] ?? null),
            'product_name' => $this->text($row['product_name'] ?? null), 'destination_id' => $this->text($row['destination_id'] ?? null),
            'destination' => $this->text($row['destination'] ?? $row['warehouse_name'] ?? null),
            'qty_recommended' => $this->number($row['qty_recommended'] ?? null), 'qty_rounded' => $this->number($row['qty_rounded'] ?? null),
            'current_stock' => $this->number($row['current_stock'] ?? null), 'in_transit' => $this->number($row['in_transit'] ?? null),
            'daily_demand' => $this->number($row['demand_daily'] ?? null), 'is_excluded' => is_bool($row['is_excluded'] ?? null) ? $row['is_excluded'] : null,
            'review_status' => $this->text($row['review_status'] ?? null), 'confidence' => $this->text($row['confidence'] ?? null),
            'confidence_reasons' => $this->codes($row['confidence_reasons'] ?? null),
            'demand_source' => $this->text($row['demand_source'] ?? null), 'stock_source' => $this->text($row['stock_source'] ?? null),
            'not_recommended_reason' => $this->text($row['not_recommended_reason'] ?? null),
            'quantity_explanation' => [...$numbers, 'caps_applied' => $this->codes($explanation['caps_applied'] ?? null)]];
    }

    private function pagination(array $pageData, int $page, int $limit): array
    {
        if (($pageData['current_page'] ?? null) !== $page || ($pageData['per_page'] ?? null) !== $limit
            || ! is_int($pageData['total'] ?? null) || ! is_int($pageData['last_page'] ?? null)) {
            throw new ManagerDataException('Источник не подтвердил страницу плана.');
        }
        $more = $page < $pageData['last_page'];

        return ['page' => $page, 'limit' => $limit, 'total' => $pageData['total'], 'has_more' => $more, 'next_page' => $more ? $page + 1 : null];
    }

    private function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
            && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 1_000_000_000 ? (float) $value : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && mb_strlen($value) <= 500 ? $value : null;
    }

    private function codes(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 100) return null;
        foreach ($value as $code) {
            if (! is_string($code) || ! preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $code)) return null;
        }

        return $value;
    }
}
