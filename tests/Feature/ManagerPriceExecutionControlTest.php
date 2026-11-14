<?php

namespace Tests\Feature;

use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerPriceControlClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ManagerPriceExecutionControlTest extends TestCase
{
    private bool $fakeInstalled = false;
    private array $responseData = [];
    private int $responseStatus = 200;
    private function context(string $market = 'wildberries'): array
    {
        return ['workspace_id' => '8', 'integration_id' => '62500', 'marketplace' => $market === 'wildberries' ? 'wb' : 'ozon',
            'scope' => 'configured_price_account', 'account_proof' => ['resource_id' => '10001', 'resource_label' => '<script>untrusted()</script>',
                'source' => $market === 'wildberries' ? 'WB Prices API' : 'Ozon Seller API', 'observed_at' => now()->utc()->toIso8601String()],
            'control' => ['mode' => 'legacy', 'version' => 0, 'changed_at' => null], 'context_fingerprint' => str_repeat('a', 64),
            'account_key' => 'must-not-leak-account', 'credential_digest' => 'must-not-leak-digest'];
    }

    private function fake(array $raw, int $status = 200): void
    {
        $this->responseData = $raw; $this->responseStatus = $status;
        if ($this->fakeInstalled) return;
        $this->fakeInstalled = true;
        config(['services.manager.price_executor_url' => 'https://ads.test/api/v1']); Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());
            $this->assertStringStartsWith('https://ads.test/api/v1/manager/prices/execution-controls/context?', $request->url());
            $this->assertSame(['Bearer personal-control-session'], $request->header('Authorization'));
            return Http::response(['data' => $this->responseData], $this->responseStatus);
        });
    }

    private function capture(string $market = 'wildberries'): array
    {
        return (new ManagerPriceControlClient)->capture(new ManagerIdentity(8, 42, [], true), 'personal-control-session', 62500,
            ['marketplace' => $market, 'native_change' => ['resource_id' => '10001']]);
    }

    public function test_wb_and_ozon_capture_only_the_original_scoped_executor_version_without_secrets(): void
    {
        foreach (['wildberries', 'ozon'] as $market) {
            $raw = $this->context($market); $this->fake($raw);
            $result = $this->capture($market);
            $this->assertSame('price_execution_context_v1', $result['version']);
            $this->assertSame(0, $result['control']['version']); $this->assertNull($result['control']['changed_at']);
            $this->assertSame($market, $result['marketplace']); $this->assertSame('10001', $result['resource_id']);
            $this->assertStringNotContainsString('must-not-leak', json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('<script>', json_encode($result, JSON_THROW_ON_ERROR));
            Http::assertSent(fn ($r) => $r->header('X-Sellico-Workspace') === ['8'] && str_contains($r->url(), 'integration_id=62500') && str_contains($r->url(), 'resource_id=10001'));
        }
    }

    public function test_wrong_scope_types_calendar_or_version_never_become_a_legacy_permission(): void
    {
        foreach ([
            fn ($r) => [...$r, 'workspace_id' => '9'], fn ($r) => [...$r, 'integration_id' => '999'],
            fn ($r) => [...$r, 'marketplace' => 'ozon'], fn ($r) => [...$r, 'scope' => 'another_scope'],
            fn ($r) => [...$r, 'account_proof' => [...$r['account_proof'], 'resource_id' => 10001]],
            fn ($r) => [...$r, 'account_proof' => [...$r['account_proof'], 'source' => 'cached estimate']],
            fn ($r) => [...$r, 'account_proof' => [...$r['account_proof'], 'observed_at' => '2026-02-30T12:00:00Z']],
            fn ($r) => [...$r, 'control' => ['mode' => 'legacy', 'version' => 1, 'changed_at' => null]],
            fn ($r) => [...$r, 'control' => ['mode' => 'legacy', 'version' => false, 'changed_at' => null]],
            fn ($r) => [...$r, 'control' => ['mode' => 'manual_only', 'version' => 0, 'changed_at' => null]],
            fn ($r) => [...$r, 'control' => ['mode' => 'manual_only', 'version' => 2, 'changed_at' => 'unknown']],
            fn ($r) => [...$r, 'context_fingerprint' => 'not-a-fingerprint'],
        ] as $mutate) {
            $this->fake($mutate($this->context()));
            try { $this->capture(); $this->fail('Unverified source must not authorize a price command.'); }
            catch (ManagerDataException $error) { $this->assertSame(503, $error->httpStatus); }
        }
    }

    public function test_stopped_and_unavailable_sources_fail_closed_and_manual_opening_preserves_its_version(): void
    {
        $raw = $this->context(); $raw['control'] = ['mode' => 'stopped', 'version' => 1, 'changed_at' => '2026-11-05T00:00:00Z']; $this->fake($raw);
        try { $this->capture(); $this->fail('A stop must prevent executable preparation.'); } catch (ManagerDataException $e) { $this->assertSame(409, $e->httpStatus); }
        foreach ([403, 503] as $status) { $this->fake($this->context(), $status); try { $this->capture(); $this->fail('Unavailable source must not be legacy.'); } catch (ManagerDataException $e) { $this->assertSame($status, $e->httpStatus); } }
        $raw['control'] = ['mode' => 'manual_only', 'version' => 2, 'changed_at' => '2026-11-05T00:00:00Z']; $this->fake($raw);
        $this->assertSame(2, $this->capture()['control']['version']);
    }

    public function test_contexts_emitted_by_the_real_go_service_have_the_same_owner_resource_and_version_contract(): void
    {
        $dir = getenv('SELYA_PRICE_EXECUTION_CONTROL_CONTRACT_DIR') ?: __DIR__.'/../Fixtures/price-execution-control';
        foreach (['wb' => 'wildberries', 'ozon' => 'ozon'] as $prefix => $market) {
            foreach (['initial-context' => 0, 'manual-context' => 2] as $name => $version) {
                $file = $dir.'/'.$prefix.'-'.$name.'.json';
                $this->assertFileExists($file); $raw = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR)['data']; $this->fake($raw);
                $result = (new ManagerPriceControlClient)->capture(new ManagerIdentity(3, 7, [], true), 'personal-control-session', 28,
                    ['marketplace' => $market, 'native_change' => ['resource_id' => '101']]);
                $this->assertSame($version, $result['control']['version']); $this->assertSame($raw['context_fingerprint'], $result['context_fingerprint']);
            }
        }
    }
}
