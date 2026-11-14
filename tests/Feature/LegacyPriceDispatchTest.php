<?php

namespace Tests\Feature;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Domains\Ozon\Api\ProductsApi;
use App\Services\Marketplace\PriceDispatchAuthorization;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

class LegacyPriceDispatchTest extends TestCase
{
    private const GATE = 'https://ads.internal.test/internal/v1/prices/dispatch-authorizations';
    private mixed $grantMutation = null;
    private array $signedContracts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.price_dispatch.url' => self::GATE, 'services.price_dispatch.secret' => str_repeat('test-only-price-key-', 3),
            'services.price_dispatch.legacy_enabled' => true]);
        Http::preventStrayRequests();
    }

    public function test_retired_senders_refuse_before_the_gate_and_the_marketplace_on_both_platforms(): void
    {
        Http::fake();
        foreach ([null, false, 'true', 1] as $setting) {
            config(['services.price_dispatch.legacy_enabled' => $setting]);
            $ozon = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => [['product_id' => 101, 'price' => '123.45']]]);
            $import = (new OzonClient('555', 'test-native-key', ''))->post('/v3/product/import', ['items' => [['offer_id' => 'isolated-offer', 'price' => '123.45']]]);
            $wb = $this->wb()->pricesPost('/api/v2/upload/task', ['data' => [['nmID' => 101, 'price' => 123, 'discount' => 0]]]);
            foreach ([$ozon, $import, $wb] as $result) {
                $this->assertSame('not_dispatched', $result['_sellico_price_dispatch']['status']);
                $this->assertSame('price_dispatch_retired', $result['_sellico_price_dispatch']['denial']['code']);
                $this->assertStringContainsString('лично согласованные', $result['_sellico_price_dispatch']['denial']['message']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_a_retired_answer_from_the_shared_executor_has_its_own_safe_denial(): void
    {
        Http::fake([self::GATE => Http::response(['data' => null, 'error' => 'price_dispatch_retired'], 409)]);
        $result = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => [['product_id' => 101, 'price' => '123.45']]]);
        $this->assertSame('price_dispatch_retired', $result['_sellico_price_dispatch']['denial']['code']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => $r->url() !== self::GATE);
    }

    public function test_stopped_account_prevents_direct_ozon_price_and_catalog_imports(): void
    {
        Http::fake(fn ($r) => $r->url() === self::GATE
            ? Http::response(['error' => 'price_execution_stopped'], 409)
            : Http::response(['result' => ['task_id' => 123, 'ok' => true]]));
        $client = new OzonClient('555', 'test-native-key', '');
        $client->post('/v1/product/import/prices', ['prices' => [['product_id' => 101, 'price' => '123.45']]]);
        $client->post('/v3/product/import', ['items' => [['offer_id' => 'isolated-offer', 'price' => '123.45']]]);
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://api-seller.ozon.ru/'));
        Http::assertSentCount(2);
    }

    public function test_stopped_account_prevents_wb_price_upload_through_generic_post(): void
    {
        Http::fake(fn ($r) => $r->url() === self::GATE
            ? Http::response(['error' => 'price_execution_stopped'], 409)
            : Http::response(['error' => false, 'data' => ['id' => 321]]));
        $payload = rtrim(strtr(base64_encode(json_encode(['sid' => '11111111-2222-3333-4444-555555555555'])), '+/', '-_'), '=');
        $client = new WildberriesClient('e30.'.$payload.'.test-signature');
        $client->post('/api/v2/upload/task', ['data' => [['nmID' => 101, 'price' => 123, 'discount' => 0]]], 'https://discounts-prices-api.wildberries.ru');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'discounts-prices-api.wildberries.ru'));
        Http::assertSentCount(1);
    }

    public function test_active_and_unbound_holds_have_safe_denials_and_no_native_request(): void
    {
        $sequence = Http::sequence();
        foreach (['price_dispatch_blocked_by_active_write', 'price_dispatch_binding_unknown'] as $code) {
            $sequence->push(['data' => null, 'error' => $code], 409)->push(['data' => null, 'error' => $code], 409);
        }
        Http::fake([self::GATE => $sequence]);
        foreach (['price_dispatch_blocked_by_active_write', 'price_dispatch_binding_unknown'] as $code) {
            $ozon = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => [['product_id' => 101, 'price' => '123.45']]]);
            $wb = $this->wb()->pricesPost('/api/v2/upload/task', ['data' => [['nmID' => 101, 'price' => 123, 'discount' => 0]]]);
            foreach ([$ozon, $wb] as $result) {
                $this->assertSame('not_dispatched', $result['_sellico_price_dispatch']['status']);
                $denial = $result['_sellico_price_dispatch']['denial'];
                $this->assertSame($code, $denial['code']);
                $this->assertStringContainsString('заблокирована', $denial['message']);
                $this->assertStringNotContainsString('test-native-key', json_encode($denial));
            }
        }
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($r) => $r->url() !== self::GATE);
    }

    public function test_unknown_or_malformed_denial_does_not_expose_upstream_content(): void
    {
        Http::fake([self::GATE => Http::sequence()
            ->push(['error' => 'private-upstream-marker'], 409)
            ->push(['error' => ['code' => 'price_dispatch_blocked_by_active_write', 'message' => 'private-upstream-marker']], 409)]);
        for ($i = 0; $i < 2; $i++) {
            $result = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => [['product_id' => 101, 'price' => '123.45']]]);
            $this->assertSame('not_dispatched', $result['_sellico_price_dispatch']['status']);
            $this->assertArrayNotHasKey('denial', $result['_sellico_price_dispatch']);
            $this->assertStringNotContainsString('private-upstream-marker', json_encode($result));
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => $r->url() !== self::GATE);
    }

    public function test_public_price_write_result_preserves_the_fixed_hold_message(): void
    {
        Http::fake([self::GATE => Http::response(['data' => null, 'error' => 'price_dispatch_blocked_by_active_write'], 409)]);
        $result = (new ProductsApi(new OzonClient('555', 'test-native-key', '')))->updatePrices([['product_id' => 101, 'price' => '123.45']]);
        $this->assertFalse($result['success']);
        $this->assertSame('not_dispatched', $result['status']);
        $this->assertStringContainsString('незавершённая ценовая команда', $result['error']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => $r->url() !== self::GATE);
    }

    public function test_a_denial_exception_after_native_io_begins_stays_unknown_and_is_not_retried(): void
    {
        $native = 0;
        Http::fake(function ($r) use (&$native) {
            if ($r->url() === self::GATE) return Http::response(['data' => $this->grant($r)]);
            $native++;
            throw \App\Services\Marketplace\PriceDispatchDenied::fromServiceError('price_dispatch_blocked_by_active_write');
        });
        $ozon = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => [['product_id' => 101, 'price' => '123.45']]]);
        $wb = $this->wb()->pricesPost('/api/v2/upload/task', ['data' => [['nmID' => 101, 'price' => 123, 'discount' => 0]]]);
        $this->assertSame(2, $native);
        foreach ([$ozon, $wb] as $result) {
            $this->assertSame('submit_unknown', $result['_sellico_price_dispatch']['status']);
            $this->assertArrayNotHasKey('denial', $result['_sellico_price_dispatch']);
        }
    }

    private function grant($r): array
    {
        $request = json_decode($r->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('v1='.hash_hmac('sha256', "legacy-price-dispatch-v1\n".$r->body(), str_repeat('test-only-price-key-', 3)), $r->header('X-Sellico-Price-Authorization')[0] ?? null);
        $this->assertFalse($r->hasHeader('Authorization'));
        $this->assertStringNotContainsString('test-native-key', $r->body());
        $this->assertSame('products', $request['origin']);
        $this->assertSame('POST', $request['method']);
        $this->signedContracts[$request['marketplace']] = ['body' => $r->body(), 'signature' => $r->header('X-Sellico-Price-Authorization')[0]];
        $issued = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $grant = ['version' => 'legacy_price_dispatch_grant_v1', 'origin' => 'products', 'command_id' => $request['command_id'],
            'marketplace' => $request['marketplace'], 'method' => 'POST', 'endpoint' => $request['endpoint'], 'payload_sha256' => $request['payload_sha256'],
            'authorized' => true, 'scope' => 'one_legacy_price_request', 'control_version' => 0,
            'issued_at' => $issued->format('Y-m-d\TH:i:s.u\Z'), 'expires_at' => $issued->modify('+5 seconds')->format('Y-m-d\TH:i:s.u\Z'), 'valid_for_ms' => 5000];
        return $this->grantMutation ? ($this->grantMutation)($grant) : $grant;
    }

    private function wb(): WildberriesClient
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['sid' => '11111111-2222-3333-4444-555555555555'])), '+/', '-_'), '=');
        return new WildberriesClient('e30.'.$payload.'.test-signature');
    }

    public function test_exact_signed_authorizations_bind_the_actual_native_wire_bytes_for_both_markets(): void
    {
        $native = [];
        Http::fake(function ($r) use (&$native) {
            if ($r->url() === self::GATE) return Http::response(['data' => $this->grant($r)]);
            $native[] = $r;
            return Http::response(str_contains($r->url(), 'ozon.ru') ? ['result' => [['product_id' => 101, 'updated' => true, 'errors' => []]]] : ['error' => false, 'data' => ['id' => 321]]);
        });
        $ozon = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => [['product_id' => 101, 'price' => '123.45']], 'label' => 'Явная цена']);
        $wb = $this->wb()->pricesPost('/api/v2/upload/task', ['data' => [['nmID' => 101, 'price' => 123, 'discount' => 0]]]);
        $this->assertSame('receipt_received', $ozon['_sellico_price_dispatch']['status']);
        $this->assertSame('receipt_received', $wb['_sellico_price_dispatch']['status']);
        $this->assertCount(2, $native);
        foreach (['ozon', 'wb'] as $i => $market) {
            $request = json_decode($this->signedContracts[$market]['body'], true);
            $this->assertSame(hash('sha256', $native[$i]->body()), $request['payload_sha256']);
            $expected = $market === 'wb' ? 'wb:'.hash('sha256', "wb\0".'11111111-2222-3333-4444-555555555555') : 'ozon:'.hash('sha256', 'ozon-seller-price:555');
            $this->assertSame($expected, $request['account_key']);
            if ($dir = getenv('SELYA_LEGACY_PRICE_PHP_CONTRACT_DIR')) {
                if (! is_dir($dir)) mkdir($dir, 0700, true);
                file_put_contents($dir.'/legacy_price_dispatch_'.$market.'.json', json_encode($this->signedContracts[$market], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            }
        }
        Http::assertSentCount(4);
    }

    public function test_malformed_or_different_grants_never_reach_the_marketplace(): void
    {
        Http::fake(fn ($r) => $r->url() === self::GATE ? Http::response(['data' => $this->grant($r)]) : Http::response(['result' => []]));
        $changes = ['authorized' => 'true', 'command_id' => 'different', 'marketplace' => 'wb', 'method' => 'GET', 'endpoint' => '/other', 'payload_sha256' => str_repeat('0', 64), 'scope' => 'personal_price_approval', 'control_version' => 2, 'valid_for_ms' => 5001, 'issued_at' => '2026-02-30T00:00:00Z', 'expires_at' => '2026-01-01T00:00:00Z'];
        foreach ($changes as $key => $value) {
            $this->grantMutation = fn ($g) => array_replace($g, [$key => $value]);
            $result = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => [['product_id' => 101, 'price' => '123.45']]]);
            $this->assertSame('not_dispatched', $result['_sellico_price_dispatch']['status'], $key);
        }
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://api-seller.ozon.ru/'));
        Http::assertSentCount(count($changes));
    }

    public function test_missing_configuration_and_unverifiable_credentials_block_writes_without_network(): void
    {
        Http::fake();
        config(['services.price_dispatch.secret' => '']);
        $a = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => []]);
        $b = (new OzonClient('555', 'test-native-key', 'oauth-token'))->post('/v3/product/import', ['items' => []]);
        $c = (new WildberriesClient('opaque-token'))->pricesPost('/api/v2/upload/task', ['data' => []]);
        foreach ([$a, $b, $c] as $result) $this->assertSame('not_dispatched', $result['_sellico_price_dispatch']['status']);
        Http::assertNothingSent();
    }

    public function test_price_mutation_has_no_native_retry_after_rate_limit_or_lost_response(): void
    {
        $mode = 'rate'; $native = 0; $gate = 0;
        Http::fake(function ($r) use (&$mode, &$native, &$gate) {
            if ($r->url() === self::GATE) { $gate++; return Http::response(['data' => $this->grant($r)]); }
            $native++;
            if ($mode === 'lost') throw new ConnectionException('isolated lost native response');
            return Http::response(['message' => 'rate limited'], 429);
        });
        $a = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => []]);
        $b = $this->wb()->pricesPost('/api/v2/upload/task', ['data' => []]);
        $this->assertSame('rejected', $a['_sellico_price_dispatch']['status']);
        $this->assertSame('rejected', $b['_sellico_price_dispatch']['status']);
        $mode = 'lost';
        $a = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['prices' => []]);
        $b = $this->wb()->pricesPost('/api/v2/upload/task', ['data' => []]);
        $this->assertSame('submit_unknown', $a['_sellico_price_dispatch']['status']);
        $this->assertSame('submit_unknown', $b['_sellico_price_dispatch']['status']);
        $this->assertSame(4, $native); $this->assertSame(4, $gate);
    }

    public function test_price_and_import_reads_work_without_a_dispatch_credential(): void
    {
        config(['services.price_dispatch.url' => '', 'services.price_dispatch.secret' => '']);
        Http::fake(fn () => Http::response(['result' => ['items' => []], 'data' => ['listGoods' => []]]));
        $ozon = new OzonClient('555', 'test-native-key', '');
        $this->assertIsArray($ozon->post('/v5/product/info/prices', ['limit' => 1]));
        $this->assertIsArray($ozon->post('/v1/product/import/info', ['task_id' => 123]));
        $wb = new WildberriesClient('opaque-read-only-key');
        $this->assertIsArray($wb->pricesGet('/api/v2/list/goods/filter', ['limit' => 1]));
        $this->assertIsArray($wb->pricesPost('/api/v2/list/goods/filter', ['limit' => 1]));
        Http::assertSentCount(4);
    }

    public function test_unsupported_wb_price_methods_and_late_raw_flags_do_not_bypass_the_gate(): void
    {
        Http::fake(fn ($r) => Http::response(['error' => 'price_execution_stopped'], 409));
        $wb = $this->wb();
        $base = 'https://discounts-prices-api.wildberries.ru';
        $this->assertNull($wb->put('/api/v2/upload/task', [], $base));
        $this->assertNull($wb->patch('/api/v2/upload/task', [], $base));
        $this->assertNull($wb->delete('/api/v2/upload/task', $base));
        Http::assertNothingSent();
        $r = (new OzonClient('555', 'test-native-key', ''))->post('/v1/product/import/prices', ['manager_approval' => ['execution_control_version' => 2], 'prices' => []]);
        $this->assertSame('not_dispatched', $r['_sellico_price_dispatch']['status']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ozon.ru'));
    }

    public function test_a_stop_between_import_and_price_is_reported_as_partial_without_more_native_commands(): void
    {
        $gates = 0;
        Http::fake(function ($r) use (&$gates) {
            if ($r->url() === self::GATE) return ++$gates === 1 ? Http::response(['data' => $this->grant($r)]) : Http::response(['error' => 'price_execution_stopped'], 409);
            return Http::response(['result' => ['task_id' => 123]]);
        });
        $result = (new ProductsApi(new OzonClient('555', 'test-native-key', '')))->exportProduct(['offer_id' => 'isolated', 'price' => '123.45', 'currency_code' => 'RUB', 'stock' => 5], 301);
        $this->assertFalse($result['success']); $this->assertSame('partial', $result['status']);
        $this->assertFalse($result['completion_verified']); $this->assertNull($result['imported']);
        $this->assertSame('not_dispatched', $result['steps']['prices']['status']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v1/product/import/prices') || str_ends_with($r->url(), '/v2/products/stocks'));
    }

    public function test_native_http_rejection_is_checked_after_a_valid_authorization(): void
    {
        Http::fake(fn ($r) => $r->url() === self::GATE ? Http::response(['data' => $this->grant($r)]) : Http::response(['message' => 'explicit rejection'], 403));
        $api = new ProductsApi(new OzonClient('555', 'test-native-key', ''));
        $a = $api->importProducts([['offer_id' => 'isolated', 'price' => '123.45']]);
        $b = $api->updatePrices([['product_id' => 101, 'price' => '123.45']]);
        $this->assertFalse($a['success']); $this->assertSame('failed', $a['status']);
        $this->assertFalse($b['success']); $this->assertSame('failed', $b['status']);
        Http::assertSentCount(4);
    }

    public function test_native_dispatch_checks_the_local_deadline_again_immediately_before_sending(): void
    {
        foreach ([
            ['_local_deadline_ns' => hrtime(true) - 1, '_local_deadline_wall' => microtime(true) + 5.0],
            ['_local_deadline_ns' => hrtime(true) + 5000000000, '_local_deadline_wall' => microtime(true) - 1.0],
        ] as $expired) {
            try { PriceDispatchAuthorization::assertFresh($expired); $this->fail('An expired dispatch permission cannot start a native request'); }
            catch (\DomainException $error) { $this->assertStringContainsString('истекло', $error->getMessage()); }
        }
    }
}
