<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Supply;
use App\Models\SupplyItem;
use App\Models\SupplyPackage;
use App\Models\SupplyPackageItem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SupplyPackageTenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_package_rejects_supply_item_from_another_workspace(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        [$ownSupply, $ownPackage, $ownItem] = $this->supplyFixture(101, 9401, 'OWN');
        [, , $foreignItem] = $this->supplyFixture(202, 9402, 'FOREIGN');

        $this->withHeader('X-Sellico-Workspace', '101')
            ->postJson("/api/supplies/{$ownSupply->id}/packages/{$ownPackage->id}/items", [
                'supply_item_id' => $foreignItem->id,
                'sku' => $foreignItem->sku,
                'quantity' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supply_item_id');

        $this->assertDatabaseMissing('supply_package_items', [
            'package_id' => $ownPackage->id,
            'supply_item_id' => $foreignItem->id,
        ]);

        $this->withHeader('X-Sellico-Workspace', '101')
            ->postJson("/api/supplies/{$ownSupply->id}/packages/{$ownPackage->id}/items", [
                'supply_item_id' => $ownItem->id,
                'sku' => $ownItem->sku,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.supply_item_id', $ownItem->id);

        $this->assertSame(2, $ownItem->fresh()->packed_qty);
    }

    public function test_existing_cross_supply_reference_is_excluded_from_packed_totals(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        [, $ownPackage] = $this->supplyFixture(101, 9411, 'OWN-POISON');
        [$foreignSupply, $foreignPackage, $foreignItem] = $this->supplyFixture(202, 9412, 'FOREIGN-TOTAL');

        SupplyPackageItem::create([
            'package_id' => $ownPackage->id,
            'supply_item_id' => $foreignItem->id,
            'sku' => $foreignItem->sku,
            'quantity' => 7,
        ]);

        $this->withHeader('X-Sellico-Workspace', '202')
            ->postJson("/api/supplies/{$foreignSupply->id}/packages/{$foreignPackage->id}/items", [
                'supply_item_id' => $foreignItem->id,
                'sku' => $foreignItem->sku,
                'quantity' => 3,
            ])
            ->assertCreated();

        $this->assertSame(3, $foreignItem->fresh()->packed_qty);
    }

    /**
     * @return array{Supply, SupplyPackage, SupplyItem}
     */
    private function supplyFixture(int $workspaceId, int $integrationId, string $sku): array
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => $integrationId,
            'work_space_id' => $workspaceId,
        ]);
        $supply = Supply::create([
            'integration_id' => $integration->id,
            'crm_number' => "SUP-{$integrationId}",
            'status' => Supply::STATUS_DRAFT,
        ]);
        $package = $supply->packages()->create([
            'sequence_number' => 1,
            'status' => SupplyPackage::STATUS_DRAFT,
        ]);
        $item = $supply->items()->create([
            'sku' => $sku,
            'product_name' => "{$sku} product",
            'planned_qty' => 20,
            'packed_qty' => 0,
        ]);

        return [$supply, $package, $item];
    }
}
