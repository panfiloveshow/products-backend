<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsSettings;
use App\Services\Ozon\OzonLocalityService;
use App\Services\SellicoApiService;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ManagerPromotionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $permissions = [
        'products.view',
        'marketing.view',
        'marketing.manage',
        'unit_economics.view',
        'unit_economics.calculate',
        'integrations.view',
    ];

    private int $actor = 42;

    private bool $serviceAccount = false;

    private int $legacyHeldCount = 0;

    private string $priceControlMode = 'manual_only';

    private int $priceControlVersion = 1;

    private bool $nativeMutationTimeout = false;

    private int $nativeMutationCalls = 0;

    private function fixture(string $marketplace = 'wildberries'): Product
    {
        config([
            'services.manager.promotions_enabled' => true,
            'services.manager.price_proposals_enabled' => true,
            'services.manager.price_execution_enabled' => true,
            'services.manager.price_executor_url' => 'https://ads.test/api/v1',
            'services.price_dispatch.legacy_enabled' => false,
            'services.sellico.base_url' => 'https://crm.test/api',
            'services.sellico.skip_permission_check' => true,
        ]);

        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');

        $scheme = $marketplace === 'ozon' ? 'RFBS' : 'DBS';
        Integration::factory()->{$marketplace}()->create([
            'id' => 62500,
            'work_space_id' => 8,
            'settings' => ['own_delivery_cost' => 50, 'own_return_cost' => 20],
        ]);

        $product = Product::factory()->{$marketplace}()->create([
            'integration_id' => 62500,
            'sku' => 'PROMO-SKU-1',
            'price' => 1000,
            'old_price' => 1000,
            'fulfillment_type' => $scheme,
            'wb_data' => ['nmID' => 10001, 'price_version' => 1, 'commissions_by_scheme' => ['dbs' => ['percent' => 10]]],
            'ozon_data' => [
                'product_id' => 10001,
                'offer_id' => 'PROMO-SKU-1',
                'actual_price' => 1000,
                'price_version' => 1,
                'dimensions' => ['depth' => 100, 'width' => 100, 'height' => 100, 'weight' => 1000],
                'commissions_by_scheme' => ['rfbs' => ['percent' => 10]],
            ],
        ]);

        UnitEconomicsSettings::create([
            'integration_id' => 62500,
            'sku' => 'PROMO-SKU-1',
            'cost_price' => 200,
            'tax_percent' => 6,
            'drr_percent' => 5,
            'vat_percent' => 3,
            'our_share_percent' => 2,
            'redemption_rate_override' => 100,
        ]);

        UnitEconomics::create([
            'integration_id' => 62500,
            'sku' => 'PROMO-SKU-1',
            'marketplace' => $marketplace,
            'fulfillment_type' => $scheme,
            'is_actual_scheme' => true,
            'price' => 1000,
            'cost_price' => 200,
            'commission_percent' => 10,
            'effective_logistics' => 50,
            'net_profit' => 470,
            'net_profit_per_unit' => 470,
            'margin_percent' => 47,
            'acquiring_percent' => 2,
            'redemption_rate' => 100,
            'drr_percent' => 5,
            'tariff_source' => 'seller_own_delivery',
            'redemption_source' => 'manual',
            'dimensions_observed_at' => now(),
            'acquiring_observed_at' => now(),
            'marketplace_data' => [
                'commission_source' => 'wb_commission_api_scheme',
                'dimensions_source' => 'wb_product_characteristics',
                'acquiring_source' => 'wb_realization_report_sku',
            ],
        ]);

        if ($marketplace === 'ozon') {
            $this->mock(OzonLocalityService::class, function ($m) {
                $m->shouldReceive('resolveSkuLocality')->andReturn(['total_orders' => 0, 'locality_rate' => null, 'clusters_summary' => []]);
                $m->shouldReceive('buildShippingRoutesForIntegration')->andReturn([]);
                $m->shouldReceive('countSellerFboOrders7Days')->andReturn(0);
            });
        }

        app(UnitEconomicsCacheService::class)->calculateAndCache($product, $scheme);

        Http::fake(function ($r) use ($marketplace) {
            return match (true) {
                $r->url() === 'https://crm.test/api/user' => Http::response(['id' => $this->actor, 'is_service_account' => $this->serviceAccount]),
                str_contains($r->url(), '/user-permissions') => Http::response(['user_id' => $this->actor, 'permissions' => $this->permissions]),
                $r->url() === 'https://crm.test/api/get-integrations/8' => Http::response([[
                    'id' => 62500,
                    'work_space_id' => 8,
                    'status' => 'active',
                    'type' => $marketplace,
                    'name' => 'Promo Store',
                    'api_key' => 'vendor-promo-secret',
                    'client_id' => '555',
                ]]),
                str_starts_with($r->url(), 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/upload') => $this->handleNativeMutation(),
                str_starts_with($r->url(), 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/nomenclatures') => Http::response([
                    'data' => [
                        'nomenclatures' => [[
                            'id' => 10001,
                            'inAction' => false,
                            'price' => 1000,
                            'planPrice' => 920,
                            'discount' => 0,
                            'planDiscount' => 8,
                        ]],
                    ],
                ]),
                str_starts_with($r->url(), 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions') => Http::response([
                    'data' => [
                        'promotions' => [[
                            'id' => 7701,
                            'name' => 'Осенняя распродажа WB',
                            'type' => 'auto',
                            'startDateTime' => '2026-11-15T00:00:00Z',
                            'endDateTime' => '2026-11-30T23:59:59Z',
                            'inPromoActionTotal' => 12,
                        ]],
                    ],
                ]),
                $r->url() === 'https://api-seller.ozon.ru/v1/actions/products/activate'
                    || $r->url() === 'https://api-seller.ozon.ru/v1/actions/products/deactivate' => $this->handleNativeMutation('ozon'),
                $r->url() === 'https://api-seller.ozon.ru/v1/actions/candidates' => Http::response([
                    'result' => [
                        'products' => [[
                            'id' => 10001,
                            'price' => 1000,
                            'action_price' => 920,
                            'max_action_price' => 950,
                        ]],
                    ],
                ]),
                str_starts_with($r->url(), 'https://api-seller.ozon.ru/v1/actions') => Http::response([
                    'result' => [[
                        'id' => 8802,
                        'title' => 'Хиты Ozon',
                        'action_type' => 'DISCOUNT',
                        'action_status' => 'ACTIVE',
                        'date_start' => '2026-11-15T00:00:00Z',
                        'date_end' => '2026-11-28T23:59:59Z',
                        'is_participating' => true,
                        'participating_products_count' => 4,
                        'freeze_date' => '2026-11-20T00:00:00Z',
                    ]],
                ]),
                str_contains($r->url(), 'discounts-prices-api.wildberries.ru') => Http::response([
                    'error' => false,
                    'data' => ['listGoods' => [[
                        'nmID' => 10001,
                        'currencyIsoCode4217' => 'RUB',
                        'discount' => 0,
                        'clubDiscount' => 0,
                        'editableSizePrice' => false,
                        'wholesaleDiscountThreshold' => [],
                        'sizes' => [[
                            'sizeID' => 20001,
                            'price' => 1000,
                            'discountedPrice' => 1000,
                            'clubDiscountedPrice' => 1000,
                            'techSizeName' => 'M',
                        ]],
                    ]]],
                ]),
                $r->url() === 'https://api-seller.ozon.ru/v5/product/info/prices' => Http::response([
                    'items' => [[
                        'product_id' => 10001,
                        'offer_id' => 'PROMO-SKU-1',
                        'price' => [
                            'currency_code' => 'RUB',
                            'price' => '1000.00',
                            'marketing_seller_price' => '1000.00',
                            'min_price' => '500',
                            'old_price' => '1000',
                            'auto_action_enabled' => false,
                            'auto_add_to_ozon_actions_list_enabled' => false,
                        ],
                        'marketing_actions' => ['actions' => [], 'ozon_actions_exist' => false],
                    ]],
                ]),
                str_starts_with($r->url(), 'https://ads.test/api/v1/manager/prices/execution-controls/context') => Http::response([
                    'data' => [
                        'workspace_id' => '8',
                        'integration_id' => '62500',
                        'marketplace' => $marketplace === 'wildberries' ? 'wb' : 'ozon',
                        'integration_name' => 'Promo Store',
                        'scope' => 'configured_price_account',
                        'account_proof' => [
                            'resource_id' => '10001',
                            'resource_label' => 'PROMO-SKU-1',
                            'source' => $marketplace === 'wildberries' ? 'WB Prices API' : 'Ozon Seller API',
                            'observed_at' => now()->utc()->toIso8601String(),
                        ],
                        'control' => [
                            'mode' => $this->priceControlMode,
                            'version' => $this->priceControlVersion,
                            'changed_at' => $this->priceControlVersion === 0 ? null : '2026-11-10T00:00:00Z',
                        ],
                        'context_fingerprint' => hash('sha256', 'promo-control-'.$marketplace.'-'.$this->priceControlMode.'-'.$this->legacyHeldCount),
                        'approval_available' => true,
                        'legacy_held_count' => $this->legacyHeldCount,
                    ],
                ]),
                default => Http::response(['unexpected' => $r->url()], 500),
            };
        });

        return $product;
    }

    private function handleNativeMutation(string $market = 'wildberries')
    {
        $this->nativeMutationCalls++;
        if ($this->nativeMutationTimeout) {
            return Http::response(['error' => 'upstream timeout'], 504);
        }
        if ($market === 'ozon') {
            return Http::response(['result' => ['product_ids' => [10001], 'rejected' => []]], 200);
        }

        return Http::response(['data' => ['uploadID' => 991234, 'alreadyExists' => false]], 200);
    }

    public function test_reads_wb_and_ozon_promotions_without_leaking_secrets(): void
    {
        $this->fixture('wildberries');
        $wb = $this->getJson('/api/manager/promotions?integration_id=62500&marketplace=wildberries')
            ->assertOk()
            ->assertJsonPath('workspace_id', '8')
            ->assertJsonPath('integration_id', '62500')
            ->assertJsonPath('marketplace', 'wildberries')
            ->assertJsonPath('items.0.promotion_id', '7701')
            ->assertJsonPath('items.0.title', 'Осенняя распродажа WB')
            ->assertJsonPath('items.0.auto_action_enabled', true);
        $this->assertStringNotContainsString('vendor-promo-secret', $wb->getContent());
    }

    public function test_candidate_context_and_prepare_approve_promotion_proposal_under_price_policy_v1(): void
    {
        $product = $this->fixture('ozon');

        $ctx = $this->getJson('/api/manager/promotions/candidate-context?'.http_build_query([
            'integration_id' => 62500,
            'marketplace' => 'ozon',
            'promotion_id' => '8802',
            'product_id' => $product->id,
            'fulfillment_type' => 'RFBS',
            'action' => 'enter',
            'target_promo_price' => '900.00',
            'min_margin_percent' => '15.00',
            'min_profit_rub' => '100.00',
            'max_change_percent' => '20.00',
        ]))->assertOk()
            ->assertJsonPath('workspace_id', '8')
            ->assertJsonPath('integration_id', '62500')
            ->assertJsonPath('promotion_id', '8802')
            ->assertJsonPath('scenario.policy.version', 'price_policy_v1')
            ->assertJsonPath('scenario.policy.status', 'within_limits')
            ->assertJsonPath('legacy_held_count', 0)
            ->json();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ctx['context_fingerprint']);

        $prepared = $this->postJson('/api/manager/promotions/proposals/prepare', [
            'integration_id' => '62500',
            'marketplace' => 'ozon',
            'promotion_id' => '8802',
            'product_id' => $product->id,
            'fulfillment_type' => 'RFBS',
            'action' => 'enter',
            'target_promo_price' => '900.00',
            'context_fingerprint' => $ctx['context_fingerprint'],
            'constraints' => [
                'min_margin_percent' => '15.00',
                'min_profit_rub' => '100.00',
                'max_change_percent' => '20.00',
            ],
        ])->assertOk()
            ->assertJsonPath('schema_version', 1)
            ->assertJsonPath('operation_type', 'promotion_change_proposal')
            ->assertJsonPath('status', 'awaiting_confirmation')
            ->assertJsonPath('approval_available', true)
            ->assertJsonPath('promotion_changed', false)
            ->json();

        $this->assertSame(0, $this->nativeMutationCalls);

        // Missing third confirmation is rejected
        $this->postJson('/api/manager/promotions/proposals/'.$prepared['id'].'/approve', [
            'fingerprint' => $prepared['fingerprint'],
            'confirmations' => [
                'financial_assumptions_checked' => true,
                'promotion_terms_and_limits_checked' => true,
                'exact_sku_and_promo_price_checked' => false,
            ],
        ])->assertStatus(422);
        $this->assertSame(0, $this->nativeMutationCalls);

        $approved = $this->postJson('/api/manager/promotions/proposals/'.$prepared['id'].'/approve', [
            'fingerprint' => $prepared['fingerprint'],
            'confirmations' => [
                'financial_assumptions_checked' => true,
                'promotion_terms_and_limits_checked' => true,
                'exact_sku_and_promo_price_checked' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('promotion_changed', true)
            ->assertJsonPath('executor_receipt.status', 'succeeded')
            ->json();

        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/i', $approved['executor_receipt']['native_change_id']);
        $this->assertSame(1, $this->nativeMutationCalls);
    }

    public function test_blocks_prepare_on_legacy_holds_outside_limits_or_unknown_never_replays(): void
    {
        $product = $this->fixture('wildberries');
        $this->legacyHeldCount = 2;

        $ctx = $this->getJson('/api/manager/promotions/candidate-context?'.http_build_query([
            'integration_id' => 62500,
            'marketplace' => 'wildberries',
            'promotion_id' => '7701',
            'product_id' => $product->id,
            'fulfillment_type' => 'DBS',
            'action' => 'enter',
            'target_promo_price' => '920.00',
            'min_margin_percent' => '15.00',
            'min_profit_rub' => '100.00',
            'max_change_percent' => '20.00',
        ]))->assertOk()->assertJsonPath('legacy_held_count', 2)->json();

        $this->postJson('/api/manager/promotions/proposals/prepare', [
            'integration_id' => '62500',
            'marketplace' => 'wildberries',
            'promotion_id' => '7701',
            'product_id' => $product->id,
            'fulfillment_type' => 'DBS',
            'action' => 'enter',
            'target_promo_price' => '920.00',
            'context_fingerprint' => $ctx['context_fingerprint'],
            'constraints' => [
                'min_margin_percent' => '15.00',
                'min_profit_rub' => '100.00',
                'max_change_percent' => '20.00',
            ],
        ])->assertConflict()->assertJsonPath('code', 'legacy_price_admissions_unresolved');

        // Clear legacy holds, test outside_limits refusal (422)
        $this->legacyHeldCount = 0;
        $badCtx = $this->getJson('/api/manager/promotions/candidate-context?'.http_build_query([
            'integration_id' => 62500,
            'marketplace' => 'wildberries',
            'promotion_id' => '7701',
            'product_id' => $product->id,
            'fulfillment_type' => 'DBS',
            'action' => 'enter',
            'target_promo_price' => '920.00',
            'min_margin_percent' => '90.00',
            'min_profit_rub' => '100.00',
            'max_change_percent' => '20.00',
        ]))->assertOk()->assertJsonPath('scenario.policy.status', 'outside_limits')->json();

        $this->postJson('/api/manager/promotions/proposals/prepare', [
            'integration_id' => '62500',
            'marketplace' => 'wildberries',
            'promotion_id' => '7701',
            'product_id' => $product->id,
            'fulfillment_type' => 'DBS',
            'action' => 'enter',
            'target_promo_price' => '920.00',
            'context_fingerprint' => $badCtx['context_fingerprint'],
            'constraints' => [
                'min_margin_percent' => '90.00',
                'min_profit_rub' => '100.00',
                'max_change_percent' => '20.00',
            ],
        ])->assertStatus(422);

        // Prepare valid proposal and simulate upstream 504 -> durable unknown without replay on GET/approve
        $goodCtx = $this->getJson('/api/manager/promotions/candidate-context?'.http_build_query([
            'integration_id' => 62500,
            'marketplace' => 'wildberries',
            'promotion_id' => '7701',
            'product_id' => $product->id,
            'fulfillment_type' => 'DBS',
            'action' => 'enter',
            'target_promo_price' => '920.00',
            'min_margin_percent' => '15.00',
            'min_profit_rub' => '100.00',
            'max_change_percent' => '20.00',
        ]))->assertOk()->json();

        $prepared = $this->postJson('/api/manager/promotions/proposals/prepare', [
            'integration_id' => '62500',
            'marketplace' => 'wildberries',
            'promotion_id' => '7701',
            'product_id' => $product->id,
            'fulfillment_type' => 'DBS',
            'action' => 'enter',
            'target_promo_price' => '920.00',
            'context_fingerprint' => $goodCtx['context_fingerprint'],
            'constraints' => [
                'min_margin_percent' => '15.00',
                'min_profit_rub' => '100.00',
                'max_change_percent' => '20.00',
            ],
        ])->assertOk()->json();

        $this->nativeMutationTimeout = true;
        $this->postJson('/api/manager/promotions/proposals/'.$prepared['id'].'/approve', [
            'fingerprint' => $prepared['fingerprint'],
            'confirmations' => [
                'financial_assumptions_checked' => true,
                'promotion_terms_and_limits_checked' => true,
                'exact_sku_and_promo_price_checked' => true,
            ],
        ])->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('promotion_changed', false);

        $this->assertSame(1, $this->nativeMutationCalls);

        // GET (reconcile) and repeated approve MUST NOT replay Native write
        $this->nativeMutationTimeout = false;
        $this->getJson('/api/manager/promotions/proposals/'.$prepared['id'])->assertOk()->assertJsonPath('status', 'unknown');
        $this->postJson('/api/manager/promotions/proposals/'.$prepared['id'].'/approve', [
            'fingerprint' => $prepared['fingerprint'],
            'confirmations' => [
                'financial_assumptions_checked' => true,
                'promotion_terms_and_limits_checked' => true,
                'exact_sku_and_promo_price_checked' => true,
            ],
        ])->assertOk()->assertJsonPath('status', 'unknown');
        $this->assertSame(1, $this->nativeMutationCalls);
    }
}
