<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Http\Controllers\Api\UnitEconomicsCacheController;
use App\Models\Integration;
use App\Models\ManagerFinancialSettingChange;
use App\Models\Product;
use App\Models\UnitEconomicsSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManagerFinancialSettingsService
{
    public function __construct(private ManagerStoreResolver $stores, private ManagerFinancialSettingsPatch $patch,
        private UnitEconomicsCacheController $owner) {}

    public function prepare(ManagerIdentity $identity, int $integrationId, string $productId, array $values): array
    {
        $this->permission($identity, true); $this->available();
        $values = $this->patch->normalize($values);
        [$product, $store] = $this->scope($identity, $integrationId, $productId);
        $row = new ManagerFinancialSettingChange(['id' => (string) Str::uuid(), 'workspace_id' => $identity->workspaceId,
            'integration_id' => $integrationId, 'created_by' => $identity->userId, 'marketplace' => $store->marketplace,
            'integration_name' => $store->name, 'product_id' => $product->id, 'product_sku' => $product->sku,
            'product_name' => mb_substr((string) $product->name, 0, 500), 'credential_binding' => $store->binding,
            'product_binding' => $this->productBinding($product), 'values' => $values,
            'baseline' => $this->baseline($this->settings($integrationId, $product->sku)), 'status' => 'awaiting_confirmation',
            'expires_at' => now()->utc()->addMinutes(5), 'events' => []]);
        $row->fingerprint = $this->fingerprint($row);
        $this->event($row, 'prepared');

        return $this->expose($row);
    }

    public function get(ManagerIdentity $identity, string $id): array
    {
        $row = $this->owned($identity, $id);
        $this->scope($identity, (int) $row->integration_id, $row->product_id);
        if ($row->status === 'awaiting_confirmation' && $row->expires_at->isPast()) {
            DB::transaction(function () use ($id) {
                $locked = ManagerFinancialSettingChange::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'awaiting_confirmation' && $locked->expires_at->isPast()) $this->event($locked, 'expired', ['status' => 'expired']);
            });
        }

        return $this->expose($row->fresh());
    }

    public function approve(ManagerIdentity $identity, string $id, string $fingerprint): array
    {
        $this->permission($identity, true); $this->available();
        $row = $this->owned($identity, $id);
        [$product, $store] = $this->scope($identity, (int) $row->integration_id, $row->product_id);
        DB::transaction(function () use ($row, $identity, $product, $store, $fingerprint) {
            $row = ManagerFinancialSettingChange::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $this->integrity($row, $fingerprint);
            if ($row->status !== 'awaiting_confirmation') return;
            if ($row->expires_at->isPast()) { $this->event($row, 'expired', ['status' => 'expired']); return; }
            $lockedProduct = Product::whereKey($product->id)->where('integration_id', $row->integration_id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($row->credential_binding, $store->binding) || ! hash_equals($row->product_binding, $this->productBinding($lockedProduct))) {
                $this->event($row, 'source_changed', ['status' => 'cancelled', 'message_code' => 'source_changed']); return;
            }
            $settings = $this->settings((int) $row->integration_id, $row->product_sku, true);
            if ($this->baseline($settings) !== $this->canonicalBaseline($row->baseline)) {
                $this->event($row, 'settings_changed', ['status' => 'cancelled', 'message_code' => 'settings_changed']); return;
            }
            $saved = $this->owner->saveManagerFinancialSettings($identity, $lockedProduct, $this->patch->normalize($row->values), $settings);
            foreach ($row->values as $field => $value) {
                if (DecimalMoney::normalize($saved->$field) !== $value) throw new ManagerDataException('Владелец не подтвердил сохранённое значение.', 409);
            }
            $this->event($row, 'settings_saved', ['status' => 'succeeded', 'settings_id' => $saved->id, 'applied_at' => now()->utc()]);
            // Settings/provenance commit together. A failed cache refresh must
            // not roll them back or trigger another settings write on retry.
            DB::afterCommit(function () use ($row) {
                try {
                    $this->owner->refreshManagerFinancialSettings((int) $row->integration_id, $row->product_sku);
                    $code = 'calculator_returned';
                } catch (\Throwable) { $code = 'unconfirmed'; }
                DB::transaction(function () use ($row, $code) {
                    $locked = ManagerFinancialSettingChange::whereKey($row->id)->lockForUpdate()->firstOrFail();
                    $this->event($locked, 'cache_refresh_'.$code, ['recalculation_status' => $code]);
                });
            });
        });

        return $this->get($identity, $id);
    }

    public function cancel(ManagerIdentity $identity, string $id, string $fingerprint): array
    {
        $row = $this->owned($identity, $id); $this->scope($identity, (int) $row->integration_id, $row->product_id);
        DB::transaction(function () use ($row, $fingerprint) {
            $row = ManagerFinancialSettingChange::whereKey($row->id)->lockForUpdate()->firstOrFail(); $this->integrity($row, $fingerprint);
            if ($row->status === 'awaiting_confirmation') $this->event($row, 'cancelled', ['status' => 'cancelled']);
        });

        return $this->get($identity, $id);
    }

    private function scope(ManagerIdentity $identity, int $integrationId, string $productId): array
    {
        $integration = Integration::whereKey($integrationId)->where('work_space_id', $identity->workspaceId)->first();
        $product = Product::whereKey($productId)->where('integration_id', $integrationId)->first();
        if (! $integration || ! $product || $product->marketplace !== $integration->marketplace
            || ! in_array($integration->marketplace, ['wildberries', 'ozon'], true) || ! is_string($product->sku) || $product->sku === '') {
            throw new ManagerDataException('Товар не найден в выбранном магазине.', 404);
        }

        return [$product, $this->stores->resolve($identity, $integrationId, $integration->marketplace)];
    }

    private function settings(int $integrationId, string $sku, bool $lock = false): ?UnitEconomicsSettings
    {
        $query = UnitEconomicsSettings::where('integration_id', $integrationId)->where('sku', $sku);
        if ($lock) $query->lockForUpdate();
        $rows = $query->limit(2)->get();
        if ($rows->count() > 1) throw new ManagerDataException('Найдено несколько строк настроек SKU. Сначала устраните неоднозначность.', 409);

        return $rows->first();
    }

    private function baseline(?UnitEconomicsSettings $settings): array
    {
        $values = [];
        foreach (ManagerFinancialSettingsPatch::FIELDS as $field => $_) $values[$field] = $settings?->$field === null ? null : DecimalMoney::normalize($settings->$field);

        return ['settings_id' => $settings?->id === null ? null : (string) $settings->id, 'values' => $values,
            'explicit_cost_price' => $settings?->explicit_cost_price];
    }

    private function productBinding(Product $product): string
    {
        return $this->patch->productBinding($product);
    }

    private function canonicalBaseline(array $baseline): array
    {
        if (array_diff(array_keys($baseline), ['settings_id', 'values', 'explicit_cost_price']) !== [] || ! array_key_exists('settings_id', $baseline)
            || ($baseline['settings_id'] !== null && ! preg_match('/^[1-9][0-9]*$/D', (string) $baseline['settings_id']))
            || ! is_array($baseline['values'] ?? null) || count($baseline['values']) !== count(ManagerFinancialSettingsPatch::FIELDS)) {
            throw new ManagerDataException('Исходные настройки не подтверждены.', 409);
        }
        $values = [];
        foreach (ManagerFinancialSettingsPatch::FIELDS as $field => $_) {
            if (! array_key_exists($field, $baseline['values'])) throw new ManagerDataException('Исходные настройки неполны.', 409);
            $value = $baseline['values'][$field];
            $values[$field] = $value === null ? null : $this->patch->normalize([$field => $value])[$field];
        }

        $explicitCost = $baseline['explicit_cost_price'] ?? null;
        if ($explicitCost !== null) $explicitCost = $this->patch->normalize(['cost_price' => $explicitCost])['cost_price'];

        return ['settings_id' => $baseline['settings_id'] === null ? null : (string) $baseline['settings_id'], 'values' => $values,
            'explicit_cost_price' => $explicitCost];
    }

    private function fingerprint(ManagerFinancialSettingChange $row): string
    {
        return hash('sha256', json_encode(['manager_financial_settings_v1', $row->id, (string) $row->workspace_id, (string) $row->created_by,
            (string) $row->integration_id, $row->product_id, $row->product_sku, $row->marketplace, $row->credential_binding, $row->product_binding,
            $this->canonicalBaseline($row->baseline), $this->patch->normalize($row->values)], JSON_THROW_ON_ERROR));
    }

    private function integrity(ManagerFinancialSettingChange $row, string $fingerprint): void
    {
        if (! hash_equals($row->fingerprint, $fingerprint) || ! hash_equals($row->fingerprint, $this->fingerprint($row))) throw new ManagerDataException('Состав предложения изменился.', 409);
    }

    private function owned(ManagerIdentity $identity, string $id): ManagerFinancialSettingChange
    {
        $this->permission($identity, false);
        $row = ManagerFinancialSettingChange::whereKey($id)->where('workspace_id', $identity->workspaceId)->where('created_by', $identity->userId)->first();
        if (! $row) throw new ManagerDataException('Предложение не найдено.', 404);

        return $row;
    }

    private function permission(ManagerIdentity $identity, bool $write): void
    {
        if ($write && ! $identity->personalConfirmed) throw new ManagerDataException('CRM должна явно подтвердить личный аккаунт для ввода финансовых данных.', 403);
        foreach (['products.view', 'unit_economics.view', ...($write ? ['unit_economics.settings.edit'] : [])] as $permission) {
            if (! $identity->permits($permission)) throw new ManagerDataException('Недостаточно прав на финансовые настройки.', 403);
        }
    }

    private function available(): void
    {
        if (! config('services.manager.financial_settings_enabled', false)) throw new ManagerDataException('Изменение финансовых настроек Сели остановлено.', 503);
        if (! \Illuminate\Support\Facades\Schema::hasColumn('unit_economics_settings', 'explicit_cost_price')) {
            throw new ManagerDataException('Сохранение финансовых данных ожидает обновления схемы.', 503);
        }
    }

    private function event(ManagerFinancialSettingChange $row, string $event, array $changes = []): void
    {
        $row->forceFill([...$changes, 'events' => [...($row->events ?? []), ['event' => $event, 'at' => now()->utc()->toIso8601String()]]])->save();
    }

    private function expose(ManagerFinancialSettingChange $row): array
    {
        $diff = [];
        $values = $this->patch->normalize($row->values);
        foreach ($values as $field => $value) $diff[] = ['field' => $field, ...ManagerFinancialSettingsPatch::FIELDS[$field], 'before' => $row->baseline['values'][$field], 'after' => $value];

        return ['schema_version' => 1, 'operation_type' => 'financial_settings', 'id' => $row->id, 'workspace_id' => (string) $row->workspace_id,
            'integration_id' => (string) $row->integration_id, 'integration_name' => $row->integration_name, 'marketplace' => $row->marketplace,
            'product_id' => $row->product_id, 'product_sku' => $row->product_sku, 'product_name' => $row->product_name,
            'values' => $values, 'diff' => $diff, 'fingerprint' => $row->fingerprint, 'status' => $row->status, 'message_code' => $row->message_code,
            'result' => $row->status !== 'succeeded' ? null : ['settings_saved' => true, 'price_changed' => false,
                'recalculation_verified' => false, 'recalculation_status' => $row->recalculation_status],
            'price_execution_allowed' => false, 'created_at' => $row->created_at->utc()->toIso8601String(), 'expires_at' => $row->expires_at->toIso8601String()];
    }
}
