<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Http\Controllers\Api\CostPriceController;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsSettings;
use App\Services\UnitEconomics\CalculationFingerprint;
use App\Services\UnitEconomicsCacheService;

final class ManagerPriceService
{
    public function __construct(
        private ManagerStoreResolver $stores,
        private ManagerPriceGateway $gateway,
        private CostPriceController $economics,
        private UnitEconomicsCacheService $calculator,
        private ManagerPricePolicy $policy = new ManagerPricePolicy,
        private ManagerPriceFinancialEvidence $financialEvidence = new ManagerPriceFinancialEvidence,
        private ManagerPriceCalculationSnapshot $calculations = new ManagerPriceCalculationSnapshot,
    ) {}

    public function context(ManagerIdentity $identity, int $integrationId, string $productId, string $scheme): array
    {
        return $this->contextWithStore($identity, $integrationId, $productId, $scheme)[0];
    }

    /** Keep the private credential binding with the exact store used for the vendor snapshot. */
    private function contextWithStore(ManagerIdentity $identity, int $integrationId, string $productId, string $scheme): array
    {
        $integration = Integration::query()->whereKey($integrationId)->where('work_space_id', $identity->workspaceId)->first();
        if (! $integration) {
            throw new ManagerDataException('Магазин не найден в выбранном workspace.', 404);
        }
        $product = Product::query()->whereKey($productId)->where('integration_id', $integrationId)->first();
        if (! $product) {
            throw new ManagerDataException('Товар не найден в выбранном магазине.', 404);
        }
        if (! in_array($product->marketplace, ['wildberries', 'ozon'], true) || $product->marketplace !== $integration->marketplace) {
            throw new ManagerDataException('Не подтверждена площадка магазина и товара.', 422);
        }
        $schemes = $product->marketplace === 'wildberries' ? ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'] : ['FBO', 'FBS', 'RFBS', 'EXPRESS'];
        if (! in_array($scheme, $schemes, true)) {
            throw new ManagerDataException('Схема не поддерживается для выбранной площадки.', 422);
        }
        $store = $this->stores->resolve($identity, $integrationId, $product->marketplace);
        $binding = CalculationFingerprint::productBinding($product);
        $snapshot = $this->gateway->snapshot($store, $product);
        $product->refresh();
        if (! hash_equals($binding, CalculationFingerprint::productBinding($product))) {
            throw new ManagerDataException('Товар изменился во время проверки. Повторите запрос.', 409);
        }
        $calculation = $this->calculations->read($product, $scheme, $this->economics->managerPriceEvidence($product, $scheme));

        return [['workspace_id' => (string) $identity->workspaceId, 'integration_id' => (string) $integrationId,
            'product_id' => (string) $productId, 'integration_name' => $store->name, 'marketplace' => $store->marketplace,
            'sku' => $product->sku, 'fulfillment_type' => $scheme, 'source' => 'Sellico: текущие цены и юнит-экономика',
            'snapshot' => $snapshot, 'economics' => $calculation['economics'], 'calculation_blockers' => $calculation['reasons'],
            'execution_allowed' => false], $store, $calculation, $product];
    }

    public function simulate(ManagerIdentity $identity, int $integrationId, string $productId, string $scheme, string $sellerPrice, ?array $constraints = null): array
    {
        return $this->scenario($identity, $integrationId, $productId, $scheme, $sellerPrice, $constraints);
    }

    /** Internal capture for the proposal ledger; never returned by the price controller. */
    public function forProposal(ManagerIdentity $identity, int $integrationId, string $productId, string $scheme, string $sellerPrice, array $constraints): array
    {
        $context = $this->scenario($identity, $integrationId, $productId, $scheme, $sellerPrice, $constraints, true);
        if (($context['scenario']['status'] ?? null) === 'forecast') $context['native_change'] = (new ManagerPriceNativeChange)->describe($context);

        return $context;
    }

    private function scenario(ManagerIdentity $identity, int $integrationId, string $productId, string $scheme, string $sellerPrice, ?array $constraints, bool $capture = false): array
    {
        $constraints = $this->policy->normalize($constraints);
        $target = DecimalMoney::minor($sellerPrice);
        if ($target <= 0) {
            throw new ManagerDataException('Нужна положительная цена продавца.', 422);
        }
        [$context, $financialStore, $calculation, $product] = $this->contextWithStore($identity, $integrationId, $productId, $scheme);
        $evidence = $context['economics'];
        if ($capture) {
            $field = $product->marketplace === 'wildberries' ? 'wb_data' : 'ozon_data';
            $context['_proposal_binding'] = ['credential_binding' => $financialStore->binding,
                'product_binding' => CalculationFingerprint::productBinding($product), 'calculation_identity' => $calculation['identity'],
                'price_version' => $product->{$field}['price_version'] ?? null];
        }
        $reasons = $calculation['reasons'];
        $snapshot = $context['snapshot'];
        if ($context['marketplace'] === 'wildberries') {
            $prices = array_unique(array_column($snapshot['sizes'], 'seller_price'));
            if (count($prices) !== 1 || $snapshot['editable_size_price']) {
                $reasons[] = 'size_specific_price_requires_separate_scenario';
            }
            if ($snapshot['club_discount_percent'] !== 0) {
                $reasons[] = 'club_price_requires_separate_scenario';
            }
            if (($snapshot['wholesale_context_complete'] ?? false) !== true) {
                $reasons[] = 'incomplete_wholesale_context';
            } elseif ($snapshot['wholesale_discount_threshold'] !== []) {
                $reasons[] = 'wholesale_tiers_require_separate_scenario';
            }
            $livePrice = count($prices) === 1 ? reset($prices) : null;
        } else {
            $livePrice = $snapshot['seller_price'];
            if (! $snapshot['promotion_context_complete']) {
                $reasons[] = 'incomplete_promotion_context';
            }
            if ($snapshot['auto_action_enabled'] || $snapshot['auto_add_to_actions'] || ($snapshot['marketing_actions']['actions'] ?? []) !== []
                || ($snapshot['marketing_actions']['ozon_actions_exist'] ?? false) || $snapshot['marketing_seller_price'] !== $livePrice) {
                $reasons[] = 'promotion_context_requires_separate_scenario';
            }
        }
        if ($livePrice === null || ($evidence !== null && DecimalMoney::minor($evidence['price'] ?? 0) !== DecimalMoney::minor($livePrice))) {
            $reasons[] = 'economics_price_differs_from_live';
        }
        // The legacy display calculator has defaults. A manager forecast cannot
        // quietly use those when real monetary inputs have not been supplied.
        $actual = UnitEconomics::query()->where('integration_id', $integrationId)->where('sku', $product->sku)
            ->where('marketplace', $product->marketplace)->where('fulfillment_type', $scheme)->where('is_actual_scheme', true)->get();
        $settings = UnitEconomicsSettings::query()->where('integration_id', $integrationId)->where('sku', $product->sku)->first();
        if ($actual->count() !== 1) {
            $reasons[] = 'ambiguous_actual_scheme';
        }
        $calculated = $actual->count() === 1 ? $actual->first() : null;
        foreach (['tax_percent', 'vat_percent', 'drr_percent', 'our_share_percent'] as $field) {
            if ($settings?->$field === null && $calculated?->$field === null) {
                $reasons[] = 'missing_'.$field;
            }
        }
        foreach (['acquiring_percent', 'redemption_rate'] as $field) {
            if ($calculated?->$field === null) {
                $reasons[] = 'missing_'.$field;
            }
        }
        if ($reasons !== []) {
            return $this->blocked($context, $sellerPrice, $reasons, $constraints);
        }

        $preview = $this->calculator->previewPrice($product, $scheme, $target / 100);
        // Nonzero WB VAT/share needs the main owner's seller-cost finalization,
        // rather than a bare domain-calculator result or an older adapter.
        if ($product->marketplace === 'wildberries' && ((float) ($settings?->vat_percent ?? $calculated?->vat_percent ?? 0) !== 0.0
            || (float) ($settings?->our_share_percent ?? $calculated?->our_share_percent ?? 0) !== 0.0)
            && ($preview['financial_model'] ?? null) !== 'products_main_financials_v1') {
            return $this->blocked($context, $sellerPrice, ['wb_tax_configuration_requires_supported_model'], $constraints);
        }
        if (! CalculationFingerprint::matches($calculation['identity'], $preview['before']['_calculation_identity'] ?? null, $product)) {
            return $this->blocked($context, $sellerPrice, ['calculation_inputs_changed_requires_sync'], $constraints);
        }
        // Neither a source refresh nor a remapped physical product may be
        // attached to the earlier live snapshot while the preview is running.
        $fresh = $product->fresh();
        $field = $product->marketplace === 'wildberries' ? 'wb_data' : 'ozon_data';
        if (! $fresh || ! hash_equals(CalculationFingerprint::productBinding($product), CalculationFingerprint::productBinding($fresh))
            || ($fresh->{$field}['price_version'] ?? null) !== ($product->{$field}['price_version'] ?? null)) {
            return $this->blocked($context, $sellerPrice, ['calculation_inputs_changed_requires_sync'], $constraints);
        }
        unset($preview['before']['_calculation_identity'], $preview['after']['_calculation_identity']);
        // Reconstructing the current price must agree with the authoritative
        // cached calculation before changing a price in the same model.
        foreach (['price', 'total_costs', 'net_profit', 'margin_percent'] as $field) {
            if (! isset($preview['before'][$field]) || ! is_finite((float) $preview['before'][$field]) || abs((float) $preview['before'][$field] - (float) $evidence[$field]) > 0.02) {
                return $this->blocked($context, $sellerPrice, ['calculation_drift_requires_sync'], $constraints);
            }
        }
        foreach (['price', 'total_costs', 'net_profit', 'margin_percent'] as $field) {
            if (! isset($preview['after'][$field]) || ! is_finite((float) $preview['after'][$field])) {
                return $this->blocked($context, $sellerPrice, ['invalid_calculation_result'], $constraints);
            }
        }
        foreach (['before', 'after'] as $side) {
            if (abs($preview[$side]['price'] - $preview[$side]['total_costs'] - $preview[$side]['net_profit']) > 0.021) {
                return $this->blocked($context, $sellerPrice, ['expense_reconciliation_failed'], $constraints);
            }
        }
        // The financial owner includes seller adjustments; the chat does not
        // maintain another price formula or turn defaults into verified facts.
        $financialEvidence = $this->financialEvidence->describe($product, $scheme, $preview, $settings, $financialStore->binding);
        unset($preview['inputs'], $preview['before']['financial_input_usage'], $preview['after']['financial_input_usage']);
        try {
            $assessment = $this->policy->evaluate($preview, $sellerPrice, $constraints);
        } catch (ManagerDataException) {
            return $this->blocked($context, $sellerPrice, ['calculation_target_or_money_mismatch'], $constraints);
        }

        return [...$context, 'scenario' => ['status' => 'forecast', 'seller_price' => DecimalMoney::format($target), ...$preview,
            'assumptions' => ['Тарифы, выкуп, габариты, рекламная доля и налоговые настройки сохраняются из текущего расчёта. Прогноз не гарантирует будущие продажи.'],
            'blockers' => [], 'policy' => $assessment, 'financial_evidence' => $financialEvidence, 'execution_allowed' => false]];
    }

    private function blocked(array $context, string $price, array $reasons, ?array $constraints = null): array
    {
        return [...$context, 'scenario' => ['status' => 'blocked', 'seller_price' => DecimalMoney::normalize($price), 'before' => null, 'after' => null,
            'blockers' => array_values(array_unique($reasons)), 'policy' => $this->policy->unavailable($constraints), 'execution_allowed' => false]];
    }
}
