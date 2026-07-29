<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\OzonClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonClientAuthenticationTest extends TestCase
{
    public function test_oauth_uses_bearer_without_legacy_headers(): void
    {
        Http::fake(['api-seller.ozon.ru/*' => Http::response(['ok' => true])]);

        (new OzonClient(null, null, 'oauth-token'))->post('/v3/product/info/list', [
            'limit' => 1,
        ]);

        Http::assertSent(fn ($request): bool =>
            $request->hasHeader('Authorization', 'Bearer oauth-token')
            && ! $request->hasHeader('Client-Id')
            && ! $request->hasHeader('Api-Key')
        );
    }

    public function test_api_key_auth_remains_supported(): void
    {
        Http::fake(['api-seller.ozon.ru/*' => Http::response(['ok' => true])]);

        (new OzonClient('seller-client', 'seller-key'))->post(
            '/v3/product/info/list',
            ['limit' => 1]
        );

        Http::assertSent(fn ($request): bool =>
            $request->hasHeader('Client-Id', 'seller-client')
            && $request->hasHeader('Api-Key', 'seller-key')
            && ! $request->hasHeader('Authorization')
        );
    }
}
