<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Posting;
use App\Models\PostingItem;
use App\Models\SellerWarehouseStock;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ManagerFbsRecoveryAndComplexTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $permissions = ['auto_supply.view', 'auto_supply.create', 'integrations.view'];

    private int $actor = 42;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sellico.base_url' => 'https://crm.test/api',
            'services.sellico.skip_permission_check' => true,
            'services.manager.fbs_writes_enabled' => true,
        ]);
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-session'));
        Http::fake(function ($r) {
            return match ($r->url()) {
                'https://crm.test/api/user' => Http::response(['id' => $this->actor, 'is_service_account' => false]),
                'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => $this->actor, 'permissions' => $this->permissions]),
                'https://crm.test/api/get-integrations/8' => Http::response([[
                    'id' => 62600,
                    'work_space_id' => 8,
                    'type' => 'ozon',
                    'status' => 'active',
                    'name' => 'FBS Recovery Shop',
                    'api_key' => 'secret-fbs-key',
                    'client_id' => '777',
                ]]),
                default => throw new \RuntimeException('Unexpected HTTP request: '.$r->url()),
            };
        });
        Integration::factory()->ozon()->create(['id' => 62600, 'work_space_id' => 8]);
        $this->withToken('personal-token')->withHeader('X-Sellico-Workspace', '8');
    }

    public function test_lists_unknown_fbs_operations_and_resolves_manually_releasing_holds_without_replay(): void
    {
        $op1 = (string) Str::uuid();
        $op2 = (string) Str::uuid();

        DB::table('manager_fbs_operations')->insert([
            'id' => $op1,
            'workspace_id' => 8,
            'user_id' => 42,
            'integration_id' => 62600,
            'marketplace' => 'ozon',
            'external_id' => '0123456789-0001-1',
            'store_name' => 'FBS Recovery Shop',
            'store_binding' => str_repeat('a', 64),
            'operation_type' => 'ozon_fbs_cancel',
            'resource_key' => hash('sha256', 'ozon:0123456789-0001-1'),
            'fingerprint' => str_repeat('b', 64),
            'status' => 'unknown',
            'plan' => json_encode(['posting_number' => '0123456789-0001-1']),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(5),
        ]);

        DB::table('manager_ozon_carriage_operations')->insert([
            'id' => $op2,
            'workspace_id' => 8,
            'user_id' => 42,
            'integration_id' => 62600,
            'store_name' => 'FBS Recovery Shop',
            'store_binding' => str_repeat('a', 64),
            'operation_type' => 'ozon_carriage_approve',
            'fingerprint' => str_repeat('c', 64),
            'status' => 'unknown',
            'plan' => json_encode(['carriage_id' => '554433']),
            'native_carriage_id' => '554433',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(4),
        ]);
        DB::table('manager_fbs_resource_holds')->insert([
            'resource_key' => hash('sha256', 'carriage:554433'),
            'operation_id' => $op2,
            'created_at' => now()->subMinutes(8),
        ]);

        $list = $this->getJson('/api/manager/fbs-operations/unknown?integration_id=62600&marketplace=ozon')
            ->assertOk()
            ->assertJsonPath('workspace_id', '8')
            ->assertJsonPath('integration_id', '62600')
            ->assertJsonPath('has_more', false)
            ->json();

        $this->assertCount(2, $list['items']);
        foreach ($list['items'] as $item) {
            $this->assertFalse($item['native_outcome_verified']);
            $this->assertNull($item['physical_status']);
            $this->assertTrue($item['hold_active']);
        }

        $prepared = $this->postJson("/api/manager/fbs-operations/{$op2}/resolution/prepare", [
            'integration_id' => '62600',
            'marketplace' => 'ozon',
            'reason' => 'В ЛК Ozon перевозка 554433 уже сформирована и подписана кладовщиком.',
            'support_reference' => 'Проверено в ЛК Ozon #554433',
        ])->assertOk()
            ->assertJsonPath('status', 'awaiting_confirmation')
            ->assertJsonPath('operation_id', $op2)
            ->assertJsonPath('native_outcome_verified', false)
            ->assertJsonPath('physical_status', null)
            ->json();

        // Missing one of 4 checks fails with 422
        $this->postJson("/api/manager/fbs-operations/{$op2}/resolution/{$prepared['id']}/approve", [
            'fingerprint' => $prepared['fingerprint'],
            'confirmations' => [
                'fbs_cabinet_checked' => true,
                'fbs_no_pending_shipment_action' => true,
                'fbs_late_effect_risk_accepted' => true,
                'fbs_hold_release_understood' => false,
            ],
        ])->assertStatus(422);

        $approved = $this->postJson("/api/manager/fbs-operations/{$op2}/resolution/{$prepared['id']}/approve", [
            'fingerprint' => $prepared['fingerprint'],
            'confirmations' => [
                'fbs_cabinet_checked' => true,
                'fbs_no_pending_shipment_action' => true,
                'fbs_late_effect_risk_accepted' => true,
                'fbs_hold_release_understood' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('status', 'resolved_manually')
            ->assertJsonPath('hold_active', false)
            ->assertJsonPath('native_outcome_verified', false)
            ->assertJsonPath('physical_status', null)
            ->json();

        $this->assertNotNull($approved['resolved_at']);
        $this->assertDatabaseMissing('manager_fbs_resource_holds', ['operation_id' => $op2]);
        $this->assertSame('resolved_manually', DB::table('manager_ozon_carriage_operations')->where('id', $op2)->value('status'));

        // Also resolve op1 and verify resource_key is cleared
        $prep1 = $this->postJson("/api/manager/fbs-operations/{$op1}/resolution/prepare", [
            'integration_id' => '62600',
            'marketplace' => 'ozon',
            'reason' => 'Отправление уже отменено покупателем в кабинете Ozon.',
            'support_reference' => 'ЛК Ozon отправление 0123456789-0001-1',
        ])->assertOk()->json();

        $this->postJson("/api/manager/fbs-operations/{$op1}/resolution/{$prep1['id']}/approve", [
            'fingerprint' => $prep1['fingerprint'],
            'confirmations' => [
                'fbs_cabinet_checked' => true,
                'fbs_no_pending_shipment_action' => true,
                'fbs_late_effect_risk_accepted' => true,
                'fbs_hold_release_understood' => true,
            ],
        ])->assertOk()->assertJsonPath('status', 'resolved_manually')->assertJsonPath('hold_active', false);

        $this->assertNull(DB::table('manager_fbs_operations')->where('id', $op1)->value('resource_key'));
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), 'https://crm.test/'));
    }

    public function test_reads_fbs_complex_context_with_seller_stock_cross_check(): void
    {
        $posting = Posting::create([
            'integration_id' => 62600,
            'marketplace' => 'ozon',
            'posting_number' => '99887766-0001-1',
            'status' => Posting::STATUS_AWAITING_PACKAGING,
            'warehouse_id' => '21000',
            'warehouse_name' => 'Склад Москва FBS',
            'items_count' => 1,
            'total_quantity' => 5,
            'meta' => ['is_multibox' => true, 'mandatory_mark_required' => true],
        ]);

        PostingItem::create([
            'posting_id' => $posting->id,
            'sku' => 'MARKED-COAT-XL',
            'offer_id' => 'MARKED-COAT-XL',
            'name' => 'Пальто зимнее с маркировкой ЧЗ',
            'quantity' => 5,
            'price' => 4500,
            'weight' => 1.800,
            'meta' => ['exemplar_info_required' => true],
        ]);

        SellerWarehouseStock::create([
            'integration_id' => 62600,
            'sku' => 'MARKED-COAT-XL',
            'product_name' => 'Пальто зимнее с маркировкой ЧЗ',
            'quantity' => 4,
            'reserved' => 1,
            'last_counted_at' => now()->subHours(2),
        ]);

        $res = $this->getJson('/api/manager/fbs-complex-context?integration_id=62600&marketplace=ozon&posting_ids=99887766-0001-1')
            ->assertOk()
            ->assertJsonPath('workspace_id', '8')
            ->assertJsonPath('integration_id', '62600')
            ->assertJsonPath('stock_cross_check.physical_stock_verified', false)
            ->assertJsonPath('stock_cross_check.has_shortage', true)
            ->assertJsonPath('stock_cross_check.rows.0.sku', 'MARKED-COAT-XL')
            ->assertJsonPath('stock_cross_check.rows.0.required_qty', 5)
            ->assertJsonPath('stock_cross_check.rows.0.available_qty', 3)
            ->assertJsonPath('stock_cross_check.rows.0.shortage_qty', 2)
            ->json();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $res['context_fingerprint']);
        $this->assertCount(1, $res['postings']);
    }
}
