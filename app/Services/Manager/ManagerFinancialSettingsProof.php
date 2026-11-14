<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use App\Models\ManagerFinancialSettingChange;
use App\Models\Product;
use App\Models\UnitEconomicsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/** Evidence of explicit personal input, distinct from a marketplace observation. */
final class ManagerFinancialSettingsProof
{
    public const MAX_AGE_HOURS = 72;

    public function forInputs(Product $product, ?UnitEconomicsSettings $settings, array $inputs, ?string $binding, CarbonImmutable $checkedAt): array
    {
        if ($binding === null || $settings === null || (string) $settings->integration_id !== (string) $product->integration_id
            || $settings->sku !== $product->sku || ! Schema::hasTable('manager_financial_setting_changes')) return [];
        $workspace = Integration::whereKey($product->integration_id)->value('work_space_id');
        if (! $workspace) return [];
        $items = [];
        foreach (ManagerFinancialSettingsPatch::FIELDS as $field => $definition) {
            if ($field === 'cost_price' && ! $settings->hasExplicitCostPrice()) continue;
            $input = $field === 'redemption_rate_override' ? 'redemption_rate' : $field;
            if ($field === 'redemption_rate_override' && ($inputs['redemption_source'] ?? null) !== 'manual') continue;
            try {
                $raw = $inputs[$input] ?? null;
                if (! is_int($raw) && ! is_float($raw) && ! is_string($raw)) continue;
                $value = DecimalMoney::normalize($raw);
                if ($settings->$field === null || DecimalMoney::normalize($settings->$field) !== $value) continue;
            } catch (\Throwable) { continue; }
            $row = ManagerFinancialSettingChange::where('workspace_id', $workspace)->where('integration_id', $product->integration_id)
                ->where('product_id', $product->id)->where('product_sku', $product->sku)->where('marketplace', $product->marketplace)
                ->where('settings_id', $settings->id)->where('credential_binding', $binding)->where('status', 'succeeded')
                ->where('product_binding', app(ManagerFinancialSettingsPatch::class)->productBinding($product))
                ->whereNotNull('applied_at')->where('values->'.$field, $value)->orderByDesc('applied_at')->orderByDesc('id')->first();
            if (! $row || ($row->values[$field] ?? null) !== $value || $row->applied_at->getTimestampMs() > $checkedAt->addMinutes(5)->getTimestampMs()) continue;
            $items[$input] = ['value' => (float) $value, 'unit' => $definition['unit'],
                'status' => $row->applied_at->getTimestampMs() < $checkedAt->subHours(self::MAX_AGE_HOURS)->getTimestampMs() ? 'user_input_stale' : 'user_supplied',
                'source' => 'manager_personal_input', 'observed_at' => $row->applied_at->utc()->toIso8601String()];
        }

        return $items;
    }
}
