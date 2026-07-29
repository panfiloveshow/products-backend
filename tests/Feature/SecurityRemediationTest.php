<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckSellicoPermission;
use App\Models\Integration;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\UnitEconomicsSettings;
use App\Models\WbWebhookConfig;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SecurityRemediationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_workspace_limits_and_webhook_management_require_authentication(): void
    {
        config()->set('services.sellico.skip_permission_check', false);

        $this->getJson('/api/workspaces/101/limits-external')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'missing_credentials');

        $this->getJson('/api/wb-webhook/status?integration_id=1')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'missing_credentials');
    }

    public function test_webhook_status_is_tenant_scoped_and_never_returns_secret(): void
    {
        config()->set('services.sellico.skip_permission_check', true);

        $own = Integration::factory()->wildberries()->create([
            'id' => 4101,
            'work_space_id' => 101,
        ]);
        $foreign = Integration::factory()->wildberries()->create([
            'id' => 4202,
            'work_space_id' => 202,
        ]);

        WbWebhookConfig::create([
            'integration_id' => $own->id,
            'webhook_url' => 'https://example.com/wb',
            'secret_key' => 'must-not-leak',
            'is_active' => true,
        ]);

        $this->withHeader('X-Sellico-Workspace', '101')
            ->getJson("/api/wb-webhook/status?integration_id={$own->id}")
            ->assertOk()
            ->assertJsonMissing(['secret_key' => 'must-not-leak'])
            ->assertJsonPath('data.integration_id', $own->id);

        $this->assertNull(CurrentWorkspace::id(), 'Tenant context must be cleared after the request');

        $this->withHeader('X-Sellico-Workspace', '101')
            ->getJson("/api/wb-webhook/status?integration_id={$foreign->id}")
            ->assertForbidden();
    }

    public function test_cost_price_index_and_suppliers_do_not_cross_workspace_boundary(): void
    {
        config()->set('services.sellico.skip_permission_check', true);

        $own = Integration::factory()->create([
            'id' => 5101,
            'work_space_id' => 101,
        ]);
        $foreign = Integration::factory()->create([
            'id' => 5202,
            'work_space_id' => 202,
        ]);

        UnitEconomicsSettings::withoutGlobalScopes()->create([
            'integration_id' => $own->id,
            'sku' => 'OWN-SKU',
            'cost_price' => 100,
        ]);
        UnitEconomicsSettings::withoutGlobalScopes()->create([
            'integration_id' => $foreign->id,
            'sku' => 'FOREIGN-SKU',
            'cost_price' => 200,
        ]);
        Supplier::withoutGlobalScopes()->create([
            'workspace_id' => 101,
            'name' => 'Own supplier',
        ]);
        Supplier::withoutGlobalScopes()->create([
            'workspace_id' => 202,
            'name' => 'Foreign supplier',
        ]);

        $this->withHeader('X-Sellico-Workspace', '101')
            ->getJson('/api/products/cost-price')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.sku', 'OWN-SKU');

        $this->withHeader('X-Sellico-Workspace', '101')
            ->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonCount(1, 'data.suppliers')
            ->assertJsonPath('data.suppliers.0.name', 'Own supplier');
    }

    public function test_cost_price_bulk_cannot_mutate_foreign_workspace_product(): void
    {
        config()->set('services.sellico.skip_permission_check', true);

        $own = Integration::factory()->create([
            'id' => 6101,
            'work_space_id' => 101,
        ]);
        $foreign = Integration::factory()->create([
            'id' => 6202,
            'work_space_id' => 202,
        ]);

        $ownProduct = Product::factory()->create([
            'integration_id' => $own->id,
            'sku' => 'OWN-MUTATION-SKU',
            'cost_price' => 10,
        ]);
        $foreignProduct = Product::factory()->create([
            'integration_id' => $foreign->id,
            'sku' => 'FOREIGN-MUTATION-SKU',
            'cost_price' => 20,
        ]);

        $this->withHeader('X-Sellico-Workspace', '101')
            ->postJson('/api/products/cost-price/bulk', [
                'integration_id' => $foreign->id,
                'items' => [
                    ['sku' => $foreignProduct->sku, 'cost_price' => 999],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated', 0);

        $this->assertSame(20.0, (float) $foreignProduct->fresh()->cost_price);

        $this->withHeader('X-Sellico-Workspace', '101')
            ->postJson('/api/products/cost-price/bulk', [
                'integration_id' => $own->id,
                'items' => [
                    ['sku' => $ownProduct->sku, 'cost_price' => 111],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertSame(111.0, (float) $ownProduct->fresh()->cost_price);
    }

    public function test_view_permission_is_not_used_for_state_changing_supply_routes(): void
    {
        $permissions = (new \ReflectionClass(CheckSellicoPermission::class))
            ->getConstant('ROUTE_PERMISSIONS');

        foreach ([
            'shipments.update',
            'shipments.addItem',
            'shipments.submit',
            'shipments.approve',
            'shipments.send',
            'shipments.deliver',
            'supply-plans.update',
            'supplies.start-preparing',
            'supplies.ready-to-ship',
            'supplies.ship',
            'postings.assemble',
            'postings.pack',
            'postings.ship',
        ] as $routeName) {
            $this->assertNotSame(
                'auto_supply.view',
                $permissions[$routeName] ?? null,
                "{$routeName} must not be authorized by auto_supply.view"
            );
        }
    }
}
