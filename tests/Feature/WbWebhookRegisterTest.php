<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\WbWebhookConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * В WB Seller API нет push/webhook-подписки (push.wildberries.ru не существует):
 * регистрация честно отвечает 501 без сетевого вызова и без записи конфига.
 */
class WbWebhookRegisterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_reports_missing_wb_push_api_without_network_call(): void
    {
        config()->set('services.sellico.skip_permission_check', true);
        Http::fake();

        $integration = Integration::factory()->wildberries()->create([
            'id' => 4301,
            'work_space_id' => 101,
        ]);

        $this->withHeader('X-Sellico-Workspace', '101')
            ->postJson('/api/wb-webhook/register', [
                'integration_id' => $integration->id,
                'webhook_url' => 'https://example.com/wb',
            ])
            ->assertStatus(501)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'не предоставляет API'));

        Http::assertNothingSent();
        $this->assertSame(0, WbWebhookConfig::count());
    }

    public function test_register_keeps_tenant_check(): void
    {
        config()->set('services.sellico.skip_permission_check', true);

        $foreign = Integration::factory()->wildberries()->create([
            'id' => 4302,
            'work_space_id' => 202,
        ]);

        $this->withHeader('X-Sellico-Workspace', '101')
            ->postJson('/api/wb-webhook/register', ['integration_id' => $foreign->id])
            ->assertForbidden();
    }
}
