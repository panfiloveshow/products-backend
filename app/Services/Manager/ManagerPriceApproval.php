<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\ManagerPriceProposal;
use App\Services\UnitEconomics\FinancialInputUsage;

/** An explicit review of one forecast's assumptions, never an API source proof. */
final class ManagerPriceApproval
{
    public function review(ManagerPriceProposal $row): array
    {
        $snapshot = $row->snapshot;
        $evidence = $snapshot['scenario']['financial_evidence'] ?? [];
        $policy = (new ManagerPricePolicy)->evaluate($snapshot['scenario'] ?? [], $row->seller_price, $row->constraints);
        if ($policy['status'] !== 'within_limits' || ($evidence['status'] ?? null) !== 'incomplete'
            || ($snapshot['scenario']['financial_model'] ?? null) !== 'products_main_financials_v1'
            || ($evidence['integration_id'] ?? null) !== (string) $row->integration_id || ($evidence['product_id'] ?? null) !== $row->product_id
            || ($evidence['marketplace'] ?? null) !== $row->marketplace || ($evidence['fulfillment_type'] ?? null) !== $row->fulfillment_type
            || ($evidence['seller_price'] ?? null) !== $row->seller_price) {
            throw new ManagerDataException('Прогноз или ограничения цены не готовы к личному согласованию.', 409);
        }
        $native = (new ManagerPriceNativeChange)->describe($snapshot);
        if ($this->encoded($native) !== $this->encoded($snapshot['native_change'] ?? null)) throw new ManagerDataException('Не подтверждена неизменяемая команда цены.', 409);
        $usage = FinancialInputUsage::fromPreview($evidence['input_usage'] ?? null, $row->marketplace, $row->fulfillment_type);
        if ($usage['source'] !== 'products_main_financials_v1') throw new ManagerDataException('Расчёт не сообщил применение финансовых данных.', 409);
        $items = [];
        foreach (FinancialInputUsage::FIELDS as $field => $unit) {
            $item = $evidence['items'][$field] ?? null;
            $value = $item['value'] ?? null;
            if (! is_array($item) || ($item['unit'] ?? null) !== $unit || ! is_string($item['status'] ?? null)
                || ($usage['items'][$field] !== 'not_applied' && ((! is_int($value) && ! is_float($value)) || ! is_finite($value)
                    || $value < 0 || (in_array($unit, ['cm', 'kg'], true) && $value <= 0)))) {
                throw new ManagerDataException('Сначала заполните отсутствующие расходы и параметры товара в расчёте.', 409);
            }
            $items[] = ['field' => $field, 'value' => $value, 'unit' => $unit, 'status' => $item['status'],
                'source' => $item['source'] ?? null, 'observed_at' => $item['observed_at'] ?? null, 'usage' => $usage['items'][$field]];
        }
        $review = ['version' => 'manager_price_personal_review_v1', 'mode' => 'single_operation_assumptions',
            'source_verified' => false, 'complete_dependency_graph_verified' => false, 'items' => $items,
            'required_confirmations' => ['financial_assumptions_checked', 'exact_price_and_limits_checked'],
            'notice' => 'Проверьте значения, источники и допущения расчёта. Полнота состава расходов ещё не подтверждена. Нули и неподтверждённые источники остаются допущениями. Подтверждение относится только к этой цене и этому предложению; оно не подтверждает данные API и не включает автоматическое управление.'];
        return [...$review, 'hash' => hash('sha256', $this->encoded([$row->fingerprint, $review]))];
    }

    public function command(ManagerPriceProposal $row, ManagerStore $store, array $review): array
    {
        if (($row->binding['credential_binding'] ?? null) !== $store->binding) throw new ManagerDataException('Подключение магазина изменилось.', 409);
        $native = $row->snapshot['native_change']; $before = $native['before'];
        $digest = "manager-price-executor-credentials-v1\0";
        foreach ([$store->marketplace, $store->marketplace === 'wildberries' ? '' : (string) $store->clientId, $store->apiKey] as $v) $digest .= strlen($v).':'.$v;
        $command = ['version' => 'manager_price_approval_v1', 'operation_id' => $row->id, 'product_id' => $row->product_id,
            'workspace_id' => (string) $row->workspace_id, 'user_id' => (string) $row->created_by, 'integration_id' => (string) $row->integration_id,
            'proposal_fingerprint' => $row->fingerprint, 'financial_review_hash' => $review['hash'], 'credential_binding' => $store->binding,
            'executor_credentials_digest' => hash('sha256', $digest), 'marketplace' => $row->marketplace === 'wildberries' ? 'wb' : 'ozon',
            'seller_price_cents' => DecimalMoney::minor($row->seller_price), 'issued_at' => now()->utc()->toIso8601String(), 'expires_at' => $row->expires_at->utc()->toIso8601String()];
        $context = $row->snapshot['price_execution_control'] ?? null;
        if (! is_array($context) || ($context['version'] ?? null) !== 'price_execution_context_v1' || ($context['integration_id'] ?? null) !== (string) $row->integration_id
            || ($context['marketplace'] ?? null) !== $row->marketplace || ($context['resource_id'] ?? null) !== $native['resource_id']
            || ! is_int($context['control']['version'] ?? null) || $context['control']['version'] < 0 || $context['control']['version'] > 9007199254740991) {
            throw new ManagerDataException('Исходное предложение не содержит проверенной версии ограничения цены. Подготовьте новое.', 409);
        }
        $command['execution_control_version'] = $context['control']['version'];
        if ($row->marketplace === 'wildberries') {
            $command['wb_base_price_rub'] = intdiv(DecimalMoney::minor($native['after']['base_price']), 100);
            $command['before_wb'] = ['nm_id' => $this->id($native['resource_id']), 'vendor_code' => '',
                'discount_percent' => $before['discount_percent'], 'club_discount_percent' => $before['club_discount_percent'],
                'editable_size_price' => $before['editable_size_price'], 'wholesale_conditions' => [],
                'sizes' => array_map(fn ($s) => ['size_id' => $this->id($s['size_id']), 'price_cents' => DecimalMoney::minor($s['base_price']),
                    'discounted_price_cents' => DecimalMoney::minor($s['seller_price']), 'club_price_cents' => DecimalMoney::minor($s['club_price'])], $before['sizes'])];
        } else {
            $command['before_ozon'] = ['product_id' => $this->id($native['resource_id']), 'offer_id' => $before['offer_id'],
                'price_cents' => DecimalMoney::minor($before['seller_price']), 'old_price_cents' => DecimalMoney::minor($before['old_price']), 'min_price_cents' => DecimalMoney::minor($before['min_price']),
                'conditions' => ['marketing_price_cents' => DecimalMoney::minor($before['marketing_seller_price']), 'auto_action_enabled' => false, 'auto_add_to_actions' => false, 'has_actions' => false]];
        }
        return $command;
    }

    public function encoded(mixed $v): string
    {
        if (is_array($v)) { if (! array_is_list($v)) ksort($v, SORT_STRING); foreach ($v as $key => $value) $v[$key] = $this->stable($value); }
        return json_encode($v, JSON_THROW_ON_ERROR);
    }
    public function encodedCommand(array $command): string
    {
        foreach (['issued_at', 'expires_at'] as $field) $command[$field] = \Carbon\CarbonImmutable::parse($command[$field])->utc()->format('Y-m-d\TH:i:s.u\Z');
        return $this->encoded($command);
    }
    private function stable(mixed $v): mixed { if (! is_array($v)) return $v; if (! array_is_list($v)) ksort($v, SORT_STRING); foreach ($v as $key => $value) $v[$key] = $this->stable($value); return $v; }
    private function id(string $id): int { $v = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); if ($v === false) throw new ManagerDataException('Идентификатор площадки выходит за пределы точной команды.', 422); return $v; }
}
