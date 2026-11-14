<?php

namespace App\Services;

use App\Domains\Locality\Jobs\SyncFinanceTransactionsJob;
use App\Jobs\SyncUnitEconomicsJob;
use App\Models\Integration;
use App\Models\InventoryAlert;
use App\Services\Ozon\OzonActualRatesService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IntegrationCredentialMirrorService
{
    public function __construct(private SellicoApiService $sellico, private IntegrationAccessService $access) {}

    public function sync(Integration $integration, string $version): bool
    {
        if ($integration->marketplace !== 'ozon') {
            return false;
        }
        if (data_get($integration->settings, 'sellico_credentials_version') === $version) {
            return false;
        }
        $remote = $this->sellico->getIntegrationById((int) $integration->id, $integration->work_space_id);
        $data = $remote['integration'] ?? [];
        if (! ($remote['success'] ?? false) || (int) ($data['id'] ?? 0) !== (int) $integration->id
            || ! is_array($remote['credentials'] ?? null)
            || IntegrationAccessService::extractRemoteWorkspaceIdFromSellicoPayload($data) !== (int) $integration->work_space_id) {
            throw new RuntimeException('Canonical integration credentials are unavailable or belong to another workspace');
        }
        $credentials = $this->access->sanitizeStoredCredentials($remote['credentials']);
        foreach (['client_id', 'campaign_id', 'business_id'] as $key) {
            if (isset($credentials[$key])) {
                $credentials[$key] = (string) $credentials[$key];
            }
        }
        ksort($credentials);

        return DB::transaction(function () use ($integration, $version, $credentials, $data) {
            $current = Integration::withoutGlobalScopes()->whereKey($integration->id)->lockForUpdate()->first();
            if (! $current || data_get($current->settings, 'sellico_credentials_version') === $version) {
                return false;
            }
            if ((int) $current->work_space_id !== (int) $integration->work_space_id) {
                throw new RuntimeException('Integration workspace changed while mirroring credentials');
            }
            $previous = $this->access->sanitizeStoredCredentials($current->credentials ?? []);
            foreach (['client_id', 'campaign_id', 'business_id'] as $key) {
                if (isset($previous[$key])) {
                    $previous[$key] = (string) $previous[$key];
                }
            }
            ksort($previous);
            $next = array_replace($previous, $credentials);
            foreach (['api_key', 'client_id'] as $key) {
                if (array_key_exists($key, $data) && ($data[$key] === null || $data[$key] === '')) {
                    unset($next[$key]);
                }
            }
            if (($next['api_key'] ?? null) !== ($previous['api_key'] ?? null)
                || ($next['client_id'] ?? null) !== ($previous['client_id'] ?? null)) {
                foreach (['oauth_access_token', 'oauth_refresh_token', 'expires_at', 'scopes'] as $key) {
                    unset($next[$key]);
                }
            }
            ksort($next);
            $changed = $previous !== $next;
            $settings = is_array($current->settings) ? $current->settings : [];
            $settings['sellico_credentials_version'] = $version;
            if ($changed) {
                $current->fill([
                    'credentials' => $next,
                    'credential_type' => ! empty($next['oauth_access_token']) ? 'oauth' : 'api_key',
                    'credentials_expires_at' => $next['expires_at'] ?? null,
                    'credential_scopes' => $next['scopes'] ?? null,
                    'credential_health' => 'unknown',
                    'credential_last_checked_at' => null,
                    'last_validation_at' => null,
                    'last_validation_status' => null,
                    'last_validation_error' => null,
                ]);
                if ($current->marketplace === 'ozon') {
                    $settings['ozon_premium_plus_required'] = false;
                    OzonActualRatesService::forgetIntegration((int) $current->id);
                }
            }
            $current->settings = $settings;
            $current->save();
            if ($changed) {
                InventoryAlert::withoutGlobalScopes()->where('integration_id', $current->id)
                    ->where('sku', '__OZON_CREDENTIAL__')->where('is_resolved', false)
                    ->update(['is_resolved' => true, 'resolved_at' => now()]);
            }
            if ($changed && $current->is_active && $current->auto_sync_enabled && $current->marketplace === 'ozon') {
                Bus::chain([
                    (new SyncFinanceTransactionsJob((int) $current->id, 30))->onQueue('locality'),
                    (new SyncUnitEconomicsJob((int) $current->id))->onQueue('unit-economics'),
                ])->dispatch();
            }

            return $changed;
        });
    }
}
