<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * IDOR/BOLA: пользователь чужого workspace не должен иметь доступ к плану
 * автопоставок другого workspace ни на одном из экшенов по {id}.
 */
class AutoSupplyPlanWorkspaceAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const OWNER_WORKSPACE = 1;

    private const OTHER_WORKSPACE = 2;

    private function makePlanForOwner(): AutoSupplyPlan
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => 7001,
            'work_space_id' => self::OWNER_WORKSPACE,
        ]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-A',
            'offer_id' => 'OFF-A',
            'product_name' => 'Owner product',
            'warehouse_id' => 'w1',
            'warehouse_name' => 'WH1',
            'destination_type' => 'all',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        return $plan;
    }

    public function test_other_workspace_is_denied_on_every_plan_endpoint(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $plan = $this->makePlanForOwner();

        // [HTTP-метод, хвост URL]. destroy идёт последним: ему всё равно
        // должны отказать (403), но порядок защищает план на случай регресса.
        $endpoints = [
            ['POST', '/calculate'],
            ['GET', '/accuracy'],
            ['GET', '/lines'],
            ['GET', '/simulate?offer_id=OFF-A'],
            ['GET', '/clusters'],
            ['PUT', '/lines/1'],
            ['GET', '/export/ozon'],
            ['GET', '/export/wb'],
            ['GET', '/export/ozon-matrix'],
            ['GET', '/export/ozon-by-warehouse'],
            ['DELETE', ''],
        ];

        foreach ($endpoints as [$method, $suffix]) {
            $response = $this->json(
                $method,
                "/api/auto-supply-plans/{$plan->id}{$suffix}",
                [],
                ['X-Sellico-Workspace' => (string) self::OTHER_WORKSPACE]
            );

            $this->assertSame(
                403,
                $response->getStatusCode(),
                "Эндпоинт {$method} {$suffix} должен вернуть 403 для чужого workspace, получено {$response->getStatusCode()}"
            );
        }

        // План не тронут (ни пересчёта, ни удаления чужими руками).
        $this->assertDatabaseHas('auto_supply_plans', [
            'id' => $plan->id,
            'status' => AutoSupplyPlan::STATUS_READY,
        ]);
    }

    public function test_owning_workspace_still_has_access(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $plan = $this->makePlanForOwner();

        $this->json(
            'GET',
            "/api/auto-supply-plans/{$plan->id}/lines",
            [],
            ['X-Sellico-Workspace' => (string) self::OWNER_WORKSPACE]
        )->assertOk()->assertJsonPath('message', 'OK');
    }
}
