<?php

declare(strict_types=1);

namespace App\Services\Manager;

/** An explicit calculation recipe. No seller shipment is authorized by it. */
final class ManagerSupplyPlanParameters
{
    public const VERSION = 'manager_parameters_v1';
    private const KEYS = ['mode', 'analysis_period_days', 'horizon_days', 'min_cover_days', 'target_cover_days', 'max_cover_days',
        'safety_stock_days', 'lead_time_days', 'turnover_limit_days', 'budget_limit', 'include_in_transit', 'skip_negative_profit', 'destination_ids'];

    public function normalize(string $marketplace, array $input): array
    {
        if (! in_array($marketplace, ['wildberries', 'ozon'], true) || array_diff(array_keys($input), self::KEYS) !== []
            || array_diff(self::KEYS, array_keys($input)) !== []) $this->invalid();
        if (! in_array($input['mode'], ['anti_oos', 'balanced', 'cash_safe', 'protect_oos', 'improve_locality', 'max_profit', 'post_promo_careful'], true)) $this->invalid();
        foreach (['analysis_period_days', 'horizon_days'] as $field) {
            if (! is_int($input[$field]) || ! in_array($input[$field], [7, 14, 28, 30, 56, 60, 90], true)) $this->invalid();
        }
        foreach (['min_cover_days', 'target_cover_days', 'max_cover_days'] as $field) {
            if (! is_int($input[$field]) || $input[$field] < 1 || $input[$field] > $input['horizon_days']) $this->invalid();
        }
        if ($input['min_cover_days'] > $input['target_cover_days'] || $input['target_cover_days'] > $input['max_cover_days']) $this->invalid();
        foreach (['safety_stock_days', 'lead_time_days'] as $field) {
            if (! is_int($input[$field]) || $input[$field] < 0 || $input[$field] > 30) $this->invalid();
        }
        if ($input['turnover_limit_days'] !== null && (! is_int($input['turnover_limit_days']) || $input['turnover_limit_days'] < 1 || $input['turnover_limit_days'] > 365)) $this->invalid();
        foreach (['include_in_transit', 'skip_negative_profit'] as $field) {
            if (! is_bool($input[$field])) $this->invalid();
        }
        $budget = $input['budget_limit'];
        if ($budget !== null) {
            if (! is_string($budget) || ! preg_match('/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D', $budget)) $this->invalid();
            $minor = DecimalMoney::minor($budget);
            // The old planner treats zero as unlimited. Do not disguise a zero
            // purchasing budget as an unlimited one in an approved recipe.
            if ($minor <= 0) $this->invalid();
            $budget = DecimalMoney::format($minor);
        }
        $destinations = $input['destination_ids'];
        if (! is_array($destinations) || ! array_is_list($destinations) || count($destinations) < 1 || count($destinations) > 50) $this->invalid();
        foreach ($destinations as $id) {
            if (! is_string($id) || ! preg_match($marketplace === 'ozon' ? '/^cluster:[1-9][0-9]{0,8}$/D' : '/^[1-9][0-9]{0,17}$/D', $id)) $this->invalid();
        }
        if (count(array_unique($destinations)) !== count($destinations)) $this->invalid();
        sort($destinations, SORT_STRING);

        return ['mode' => $input['mode'], 'analysis_period_days' => $input['analysis_period_days'], 'horizon_days' => $input['horizon_days'],
            'min_cover_days' => $input['min_cover_days'], 'target_cover_days' => $input['target_cover_days'], 'max_cover_days' => $input['max_cover_days'],
            'safety_stock_days' => $input['safety_stock_days'], 'lead_time_days' => $input['lead_time_days'], 'turnover_limit_days' => $input['turnover_limit_days'],
            'budget_limit' => $budget, 'include_in_transit' => $input['include_in_transit'], 'skip_negative_profit' => $input['skip_negative_profit'], 'destination_ids' => $destinations];
    }

    public function ownerRequest(int $integrationId, string $marketplace, array $recipe): array
    {
        $recipe = $this->normalize($marketplace, $recipe);
        $destinations = $recipe['destination_ids']; unset($recipe['destination_ids']);

        return ['integration_id' => $integrationId, ...$recipe,
            ...($marketplace === 'ozon' ? ['cluster_ids' => array_map(fn ($id) => (int) substr($id, 8), $destinations)] : ['warehouse_ids' => $destinations]),
            'use_latest_constraint_file' => false, 'use_auto_constraints' => true,
            'demand_seasonality_multiplier' => 1.0, 'trend_multiplier' => 1.0];
    }

    private function invalid(): never
    {
        throw new ManagerDataException('Нужны все явные параметры расчёта, направления и положительный бюджет либо null для отсутствия ограничения.', 422);
    }
}
