<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\Product;
use App\Domains\Locality\Recommendation\LocalityDraftApplier;
use App\Services\AutoSupplyPlanning\OzonCrossdockDropOffPointService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class AutoSupplyPlanShowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_show_returns_ok_with_grouped_line_pagination(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9001,
        ]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 2,
            'total_qty' => 10,
        ]);

        $base = [
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-1',
            'offer_id' => 'OFF-1',
            'product_name' => 'Product',
            'warehouse_id' => 'w1',
            'warehouse_name' => 'WH1',
            'destination' => null,
            'destination_id' => null,
            'destination_type' => 'all',
            'cluster_name' => null,
            'region' => null,
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'high',
            'priority' => 'high',
        ];

        AutoSupplyPlanLine::create($base);
        AutoSupplyPlanLine::create(array_merge($base, [
            'warehouse_id' => 'w2',
            'warehouse_name' => 'WH2',
            'qty_rounded' => 5,
            'risk_level' => 'low',
        ]));

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}?page=1&per_page=50");

        $response->assertOk()
            ->assertJsonPath('message', 'OK')
            ->assertJsonPath('data.plan.id', $plan->id);
    }

    public function test_lines_endpoint_aggregates_with_optional_filters(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9002]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 3,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-F',
            'offer_id' => 'OFF-F',
            'product_name' => 'Filtered',
            'warehouse_id' => 'w1',
            'warehouse_name' => 'WH1',
            'destination' => null,
            'destination_id' => null,
            'destination_type' => 'all',
            'cluster_name' => null,
            'region' => null,
            'qty_recommended' => 3,
            'qty_rounded' => 3,
            'risk_level' => 'high',
            'priority' => 'high',
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}/lines?risk_level=high&per_page=10");

        $response->assertOk()
            ->assertJsonPath('message', 'OK')
            ->assertJsonPath('data.total', 1);
    }

    public function test_fix_ktr_baseline_saves_current_ktr_as_control_baseline(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9004]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [
                'planning_mode' => AutoSupplyPlan::MODE_BALANCED,
                'target_ktr' => 80,
            ],
            'result_json' => [
                'territorial_summary' => [
                    'status' => 'включено',
                    'ktr' => [
                        'value' => 72.45,
                        'label' => 'КТР 72.45%',
                        'target_value' => 80.0,
                        'target_gap_pp' => 7.55,
                        'fixation' => [
                            'version' => 'ktr-fixation-1',
                            'tracking_status' => 'not_fixed',
                            'freeze_payload' => [
                                'baseline_ktr' => 72.45,
                                'target_ktr' => 80.0,
                            ],
                        ],
                        'control_loop' => [
                            'version' => 'ktr-control-loop-1',
                            'current_value' => 72.45,
                        ],
                    ],
                ],
            ],
            'total_lines' => 1,
            'total_qty' => 10,
        ]);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/fix-ktr-baseline", [
            'target_ktr' => 90,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'КТР зафиксирован как база сравнения')
            ->assertJsonPath('data.baseline_ktr', 72.45)
            ->assertJsonPath('data.target_ktr', 90)
            ->assertJsonPath('data.plan.params.baseline_ktr', 72.45)
            ->assertJsonPath('data.plan.params.target_ktr', 90)
            ->assertJsonPath('data.territorial_summary.ktr.baseline_value', 72.45)
            ->assertJsonPath('data.territorial_summary.ktr.improvement_vs_baseline_pp', 0)
            ->assertJsonPath('data.territorial_summary.ktr.fixation.tracking_status', 'unchanged')
            ->assertJsonPath('data.territorial_summary.ktr.fixation.fixed_baseline_value', 72.45)
            ->assertJsonPath('data.territorial_summary.ktr.control_loop.fixed_baseline_value', 72.45);

        $this->assertSame(72.45, AutoSupplyPlan::query()->findOrFail($plan->id)->params['baseline_ktr']);
    }

    public function test_fix_ktr_baseline_requires_calculated_ktr(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->wildberries()->create(['id' => 9005]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'wildberries',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'result_json' => [
                'territorial_summary' => [],
            ],
            'total_lines' => 0,
            'total_qty' => 0,
        ]);

        $this->postJson("/api/auto-supply-plans/{$plan->id}/fix-ktr-baseline")
            ->assertStatus(422)
            ->assertJsonPath('error', 'ktr_not_available');
    }

    public function test_crossdock_drop_off_points_endpoint_returns_selectable_ozon_points(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9007]);

        $service = Mockery::mock(OzonCrossdockDropOffPointService::class);
        $service->shouldReceive('list')
            ->once()
            ->withArgs(fn (Integration $passedIntegration, string $search, int $limit): bool => $passedIntegration->id === $integration->id
                && $search === 'Москва'
                && $limit === 10)
            ->andReturn([
                'points' => [
                    [
                        'id' => '777001',
                        'warehouse_id' => 777001,
                        'name' => 'Москва СЦ',
                        'type' => 'sc',
                        'type_label_ru' => 'Сортировочный центр',
                        'drop_off_point_warehouse_id' => 777001,
                        'select_hint_ru' => 'Используйте этот ID как точку отгрузки для кросс-докинг поставки.',
                    ],
                ],
                'summary' => [
                    'source' => 'ozon_warehouse_list',
                    'source_label_ru' => 'Список точек отгрузки Ozon для кросс-докинга',
                    'total' => 1,
                    'usage_ru' => 'Выберите точку отгрузки и передайте её ID как drop_off_point_warehouse_id перед созданием кросс-докинг черновика.',
                    'safety_ru' => 'Само создание черновика всё равно выполняется только через preview, ручное подтверждение и fingerprint-проверку.',
                ],
            ]);
        $this->app->instance(OzonCrossdockDropOffPointService::class, $service);

        $response = $this->getJson("/api/auto-supply-plans/crossdock-drop-off-points?integration_id={$integration->id}&search=%D0%9C%D0%BE%D1%81%D0%BA%D0%B2%D0%B0&limit=10");

        $response->assertOk()
            ->assertJsonPath('message', 'Точки отгрузки Ozon для кросс-докинга')
            ->assertJsonPath('data.points.0.drop_off_point_warehouse_id', 777001)
            ->assertJsonPath('data.points.0.type_label_ru', 'Сортировочный центр')
            ->assertJsonPath('data.summary.source', 'ozon_warehouse_list')
            ->assertJsonPath('data.summary.safety_ru', 'Само создание черновика всё равно выполняется только через preview, ручное подтверждение и fingerprint-проверку.');
    }

    public function test_crossdock_drop_off_points_endpoint_rejects_non_ozon_integration(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->wildberries()->create(['id' => 9008]);

        $service = Mockery::mock(OzonCrossdockDropOffPointService::class);
        $service->shouldNotReceive('list');
        $this->app->instance(OzonCrossdockDropOffPointService::class, $service);

        $response = $this->getJson("/api/auto-supply-plans/crossdock-drop-off-points?integration_id={$integration->id}");

        $response->assertStatus(422)
            ->assertJsonPath('error', 'unsupported_marketplace')
            ->assertJsonPath('message', 'Точки кросс-докинг отгрузки доступны только для Ozon');
    }

    public function test_show_exposes_planning_engine_line_fields(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9003]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'total_lines' => 1,
            'total_qty' => 7,
            'params' => [
                'planning_mode' => AutoSupplyPlan::MODE_BALANCED,
                'analysis_period_days' => 28,
                'include_in_transit' => true,
                'cluster_ids' => [154],
                'demand_seasonality_multiplier' => 1.1,
                'trend_multiplier' => 0.9,
            ],
            'result_json' => [
                'facts_freshness' => [
                    'unit_economics' => ['items' => 1],
                ],
                'deficit_summary' => ['lines' => 1, 'qty' => 7, 'lost_revenue_daily' => 350],
                'surplus_summary' => ['lines' => 0, 'qty' => 0],
                'deficit_surplus_summary' => [
                    'method' => 'Отдельный анализ дефицита, профицита, товаров в пути',
                    'redistribution' => ['policy' => 'Для FBO Ozon/WB физическое перераспределение между складами маркетплейса недоступно продавцу'],
                ],
                'economics_summary' => ['total_expected_profit' => 1200],
                'selection_summary' => ['selected_lines' => 1],
                'territorial_summary' => [
                    'status' => 'включено',
                    'method' => 'Ранжирование кластеров по скорости доставки, локальности, ABC, риску отсутствия товара, ограничениям и потере маржи',
                    'source_coverage' => [
                        'human_status' => 'Территориальное ранжирование достаточно надёжно',
                        'critical_coverage_percent' => 88.5,
                    ],
                    'ktr' => [
                        'value' => 82.3,
                        'label' => 'КТР 82.3%',
                        'explanation' => 'КТР — текущий коэффициент территориального распределения',
                        'abc_a_fast_share_percent' => 90.0,
                        'a_items_policy_status' => 'A-товары в основном ведутся в быстрые направления',
                    ],
                ],
                'marketplace_capabilities' => [
                    'planning_flow' => 'Сначала расчёт и предварительный просмотр, затем создание черновика поставки после ручного подтверждения',
                    'autobooking_policy' => 'Автобронирование не выполняется',
                    'supports_autobooking' => false,
                    'supports_draft_creation' => true,
                    'territorial_distribution' => [
                        'supported' => true,
                        'score_kind' => 'локальность и скорость доставки',
                    ],
                ],
                'planning_sources' => [
                    'demand' => 'posting_fbo_v3',
                    'stock' => 'analytics_stocks',
                    'turnover' => 'turnover_stocks',
                    'delivery_health' => 'average_delivery_time_summary',
                    'in_transit' => 'supply_orders',
                    'constraints' => 'constraint_file',
                    'constraints_status' => 'applied_as_marketplace_needs',
                    'constraint_source_file' => 'ozon-limits.csv',
                    'constraint_parser_version' => 'marketplace-constraints-2',
                    'marketplace_needs' => 'constraint_file',
                    'marketplace_needs_status' => 'applied_as_marketplace_needs',
                    'marketplace_need_qty' => 42,
                    'constraints_used_as_marketplace_needs' => true,
                ],
            ],
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-ENGINE',
            'offer_id' => 'OFF-ENGINE',
            'product_name' => 'Engine line',
            'warehouse_id' => 'w1',
            'warehouse_name' => 'WH1',
            'cluster_id' => 154,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 7,
            'qty_rounded' => 7,
            'current_stock' => 2,
            'in_transit' => 1,
            'demand_daily' => 2,
            'risk_level' => 'high',
            'priority' => 'high',
            'explain_json' => [
                'inputs' => [
                    'supply_type' => 'replenishment',
                    'daily_demand' => 2,
                    'min_cover_days' => 7,
                    'target_cover_days' => 21,
                    'demand_source' => 'posting_fbo_v3',
                ],
                'math' => [
                    'safety_stock' => 6,
                    'needed_before_caps' => 45,
                    'needed_after_caps' => 7,
                    'caps_applied' => ['budget_limit'],
                ],
                'confidence' => [
                    'needs_manual_review' => true,
                    'missing_sources' => [],
                    'fallbacks' => [],
                    'confidence_level' => 'warning',
                    'confidence_reasons' => ['post_promo_cooldown'],
                    'sources' => ['stock' => 'analytics_stocks'],
                ],
            ],
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}?per_page=50");

        $response->assertOk()
            ->assertJsonPath('data.summary.deficit_summary.qty', 7)
            ->assertJsonPath('data.summary.planning_source_cards.0.title_ru', 'Спрос')
            ->assertJsonPath('data.summary.planning_source_cards.0.source_label_ru', 'Заказы FBO из API Ozon')
            ->assertJsonPath('data.summary.planning_source_cards.1.title_ru', 'Остатки')
            ->assertJsonPath('data.summary.planning_source_cards.1.source_label_ru', 'Аналитика остатков маркетплейса')
            ->assertJsonPath('data.summary.planning_source_cards.2.title_ru', 'Оборачиваемость')
            ->assertJsonPath('data.summary.planning_source_cards.3.title_ru', 'Товары в пути')
            ->assertJsonPath('data.summary.planning_source_cards.4.title_ru', 'Ограничения')
            ->assertJsonPath('data.summary.planning_source_cards.4.source_label_ru', 'Файл ограничений/потребностей')
            ->assertJsonPath('data.summary.planning_source_cards.4.file', 'ozon-limits.csv')
            ->assertJsonPath('data.summary.planning_source_cards.4.parser_version', 'marketplace-constraints-2')
            ->assertJsonPath('data.summary.planning_source_cards.5.title_ru', 'Потребности маркетплейса')
            ->assertJsonPath('data.summary.planning_source_cards.5.qty', 42)
            ->assertJsonPath('data.summary.planning_readiness.version', 'planning-readiness-1')
            ->assertJsonPath('data.summary.planning_readiness.overall_status', 'ready')
            ->assertJsonPath('data.summary.planning_readiness.sections.0.title_ru', 'Параметры расчёта')
            ->assertJsonPath('data.summary.planning_readiness.sections.0.items.0.label_ru', 'Период анализа продаж')
            ->assertJsonPath('data.summary.planning_readiness.sections.1.title_ru', 'Источники данных API')
            ->assertJsonPath('data.summary.planning_readiness.sections.1.items.0.value', 'заказы FBO из API Ozon')
            ->assertJsonPath('data.summary.planning_readiness.sections.2.title_ru', 'Что считает сервис')
            ->assertJsonPath('data.summary.planning_readiness.sections.2.items.3.label_ru', 'В какие кластеры лучше везти')
            ->assertJsonPath('data.summary.planning_readiness.sections.3.title_ru', 'Логика площадки')
            ->assertJsonPath('data.summary.planning_readiness.sections.3.items.1.value', 'КТР 82.3%')
            ->assertJsonPath('data.lines.data.0.reason', 'replenishment')
            ->assertJsonPath('data.lines.data.0.reason_label', 'Плановое пополнение')
            ->assertJsonPath('data.lines.data.0.confidence', 'warning')
            ->assertJsonPath('data.lines.data.0.confidence_label', 'Нужна проверка')
            ->assertJsonPath('data.lines.data.0.confidence_reason_labels.0', 'Возможный всплеск после акции')
            ->assertJsonPath('data.lines.data.0.demand_source', 'posting_fbo_v3')
            ->assertJsonPath('data.lines.data.0.demand_source_label', 'Заказы FBO из API Ozon')
            ->assertJsonPath('data.lines.data.0.stock_source', 'analytics_stocks')
            ->assertJsonPath('data.lines.data.0.stock_source_label', 'Аналитика остатков маркетплейса')
            ->assertJsonPath('data.lines.data.0.deficit_qty', 11);
    }

    public function test_ozon_show_keeps_same_sku_separate_by_cluster(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9010]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 2,
            'total_qty' => 15,
        ]);

        $base = [
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-CLUSTERED',
            'offer_id' => 'OFF-CLUSTERED',
            'product_name' => 'Clustered product',
            'destination_type' => 'cluster',
            'qty_recommended' => 1,
            'current_stock' => 0,
            'in_transit' => 0,
            'risk_level' => 'high',
            'priority' => 'high',
        ];

        AutoSupplyPlanLine::create(array_merge($base, [
            'warehouse_id' => 'cluster:1',
            'warehouse_name' => 'Москва',
            'cluster_id' => 1,
            'cluster_name' => 'Москва',
            'destination' => 'Москва',
            'destination_id' => 'cluster:1',
            'qty_rounded' => 10,
        ]));
        AutoSupplyPlanLine::create(array_merge($base, [
            'warehouse_id' => 'cluster:2',
            'warehouse_name' => 'Санкт-Петербург',
            'cluster_id' => 2,
            'cluster_name' => 'Санкт-Петербург',
            'destination' => 'Санкт-Петербург',
            'destination_id' => 'cluster:2',
            'qty_rounded' => 5,
        ]));

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}?per_page=50");

        $response->assertOk()
            ->assertJsonPath('data.lines.total', 2)
            ->assertJsonPath('data.lines.data.0.destination_type', 'cluster')
            ->assertJsonPath('data.lines.data.1.destination_type', 'cluster');

        $clusters = collect($response->json('data.lines.data'))->pluck('cluster_id')->sort()->values()->all();
        $this->assertSame([1, 2], $clusters);
    }

    public function test_selected_ozon_cluster_scopes_show_lines_and_cluster_endpoints(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9014]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [154]],
            'total_lines' => 2,
            'total_qty' => 15,
        ]);

        $base = [
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-SCOPED',
            'offer_id' => 'OFF-SCOPED',
            'product_name' => 'Scoped product',
            'destination_type' => 'cluster',
            'qty_recommended' => 1,
            'current_stock' => 0,
            'in_transit' => 0,
            'risk_level' => 'high',
            'priority' => 'high',
            'expected_profit' => 10,
        ];

        foreach ([154 => 10, 155 => 5] as $clusterId => $qty) {
            AutoSupplyPlanLine::create(array_merge($base, [
                'warehouse_id' => 'cluster:' . $clusterId,
                'warehouse_name' => 'Cluster ' . $clusterId,
                'cluster_id' => $clusterId,
                'cluster_name' => 'Cluster ' . $clusterId,
                'destination' => 'Cluster ' . $clusterId,
                'destination_id' => 'cluster:' . $clusterId,
                'qty_rounded' => $qty,
            ]));
        }

        $this->getJson("/api/auto-supply-plans/{$plan->id}?per_page=50")
            ->assertOk()
            ->assertJsonPath('data.summary.total_lines', 1)
            ->assertJsonPath('data.summary.total_qty', 10)
            ->assertJsonPath('data.lines.total', 1)
            ->assertJsonPath('data.lines.data.0.cluster_id', 154);

        $this->getJson("/api/auto-supply-plans/{$plan->id}/lines?per_page=50")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.cluster_id', 154);

        $this->getJson("/api/auto-supply-plans/{$plan->id}/clusters")
            ->assertOk()
            ->assertJsonPath('data.total_clusters', 1)
            ->assertJsonPath('data.clusters.0.cluster_id', 154);

        $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-split")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cluster_id', '154');
    }

    public function test_ozon_show_normalizes_old_cluster_rows_without_destination_type(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9011]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 7,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-OLD',
            'offer_id' => 'OFF-OLD',
            'product_name' => 'Old product',
            'warehouse_id' => 'spb-wh',
            'warehouse_name' => 'Склад СПБ',
            'cluster_id' => 55,
            'cluster_name' => 'Санкт-Петербург',
            'destination_type' => 'all',
            'qty_recommended' => 7,
            'qty_rounded' => 7,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}");

        $response->assertOk()
            ->assertJsonPath('data.lines.data.0.cluster_id', 55)
            ->assertJsonPath('data.lines.data.0.cluster_name', 'Санкт-Петербург')
            ->assertJsonPath('data.lines.data.0.warehouse_name', 'Санкт-Петербург')
            ->assertJsonPath('data.lines.data.0.destination', 'Санкт-Петербург')
            ->assertJsonPath('data.lines.data.0.destination_id', 'cluster:55')
            ->assertJsonPath('data.lines.data.0.destination_type', 'cluster');
    }

    public function test_wb_show_still_aggregates_same_sku_into_single_line(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->create(['id' => 9012, 'marketplace' => 'wildberries']);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'wildberries',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 2,
            'total_qty' => 12,
        ]);

        foreach ([1 => 5, 2 => 7] as $warehouse => $qty) {
            AutoSupplyPlanLine::create([
                'auto_supply_plan_id' => $plan->id,
                'sku' => 'SKU-WB',
                'offer_id' => 'OFF-WB',
                'product_name' => 'WB product',
                'warehouse_id' => 'wb-' . $warehouse,
                'warehouse_name' => 'WB ' . $warehouse,
                'destination_type' => 'warehouse',
                'qty_recommended' => $qty,
                'qty_rounded' => $qty,
                'risk_level' => 'low',
                'priority' => 'low',
            ]);
        }

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}");

        $response->assertOk()
            ->assertJsonPath('data.lines.total', 1)
            ->assertJsonPath('data.lines.data.0.qty_rounded', 12);
    }

    public function test_create_cluster_drafts_requires_preview_confirmation(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9014]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [1]],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-DRAFT-CONFIRM',
            'offer_id' => 'SKU-DRAFT-CONFIRM',
            'product_name' => 'SKU-DRAFT-CONFIRM',
            'warehouse_id' => 'cluster:1',
            'warehouse_name' => 'Cluster 1',
            'cluster_id' => 1,
            'cluster_name' => 'Cluster 1',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts");

        $response->assertStatus(409)
            ->assertJsonPath('error', 'confirmation_required');
    }

    public function test_cluster_draft_flow_requires_ready_plan_status(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9024]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_PENDING,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [154]],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-DRAFT-NOT-READY',
            'offer_id' => 'SKU-DRAFT-NOT-READY',
            'product_name' => 'SKU-DRAFT-NOT-READY',
            'warehouse_id' => 'cluster:154',
            'warehouse_name' => 'Москва',
            'cluster_id' => 154,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview");
        $preview->assertStatus(409)
            ->assertJsonPath('error', 'plan_not_ready')
            ->assertJsonPath('data.status', AutoSupplyPlan::STATUS_PENDING)
            ->assertJsonPath('data.required_status', AutoSupplyPlan::STATUS_READY)
            ->assertJsonPath('data.message_ru', 'Черновик Ozon можно создавать только из полностью рассчитанного плана.');

        $applier = Mockery::mock(LocalityDraftApplier::class);
        $applier->shouldNotReceive('applyBatch');
        $this->app->instance(LocalityDraftApplier::class, $applier);

        $create = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => 'fake-token',
            'confirmation_text' => 'СОЗДАТЬ ЧЕРНОВИКИ OZON',
        ]);

        $create->assertStatus(409)
            ->assertJsonPath('error', 'plan_not_ready')
            ->assertJsonPath('data.status', AutoSupplyPlan::STATUS_PENDING)
            ->assertJsonPath('data.required_status', AutoSupplyPlan::STATUS_READY);
    }

    public function test_cluster_draft_preview_marks_bad_quality_audit_as_not_allowed(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9020]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [154]],
            'result_json' => [
                'plan_quality_audit' => [
                    'status' => 'bad',
                    'summary_ru' => 'План ограничен защитой данных: найден промо-всплеск и завышенное количество.',
                    'acceptance_gates' => [
                        'can_create_ozon_draft' => false,
                        'requires_manual_review' => true,
                        'manual_review_reason_ru' => 'Проверьте спрос после акции перед созданием черновика.',
                    ],
                    'actions' => [
                        ['type' => 'review_demand', 'title' => 'Проверить спрос после акции'],
                    ],
                    'examples' => [
                        ['sku' => 'SKU-SPIKE', 'qty' => 693, 'reason_ru' => 'Похоже на разовый всплеск'],
                    ],
                ],
            ],
            'total_lines' => 1,
            'total_qty' => 693,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-SPIKE',
            'offer_id' => 'SKU-SPIKE',
            'product_name' => 'SKU-SPIKE',
            'warehouse_id' => 'cluster:154',
            'warehouse_name' => 'Москва',
            'cluster_id' => 154,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 693,
            'qty_rounded' => 693,
            'risk_level' => 'high',
            'priority' => 'critical',
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview");

        $response->assertOk()
            ->assertJsonPath('data.summary.draft_creation_allowed', false)
            ->assertJsonPath('data.summary.quality_audit_status', 'bad')
            ->assertJsonPath('data.summary.quality_audit_summary', 'План ограничен защитой данных: найден промо-всплеск и завышенное количество.')
            ->assertJsonPath('data.summary.quality_audit_manual_review_reason', 'Проверьте спрос после акции перед созданием черновика.');

        $checks = collect($response->json('data.summary.safety_checks'));
        $qualityCheck = $checks->firstWhere('key', 'plan_quality_audit');
        $this->assertSame(false, $qualityCheck['passed'] ?? null);

        $this->assertContains(
            'План ограничен защитой данных: найден промо-всплеск и завышенное количество.',
            $response->json('data.summary.warnings')
        );
    }

    public function test_create_cluster_drafts_rejects_bad_quality_audit_before_ozon_call(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9021]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [154]],
            'result_json' => [
                'plan_quality_audit' => [
                    'status' => 'bad',
                    'summary_ru' => 'План требует ручной проверки перед созданием черновика.',
                    'acceptance_gates' => [
                        'can_create_ozon_draft' => false,
                        'requires_manual_review' => true,
                    ],
                ],
            ],
            'total_lines' => 1,
            'total_qty' => 693,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-BLOCKED',
            'offer_id' => 'SKU-BLOCKED',
            'product_name' => 'SKU-BLOCKED',
            'warehouse_id' => 'cluster:154',
            'warehouse_name' => 'Москва',
            'cluster_id' => 154,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 693,
            'qty_rounded' => 693,
            'risk_level' => 'high',
            'priority' => 'critical',
        ]);

        $applier = Mockery::mock(LocalityDraftApplier::class);
        $applier->shouldNotReceive('applyBatch');
        $this->app->instance(LocalityDraftApplier::class, $applier);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => 'any-token',
            'confirmation_text' => 'СОЗДАТЬ ЧЕРНОВИКИ OZON',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'plan_quality_audit_failed')
            ->assertJsonPath('data.quality_audit.allowed', false)
            ->assertJsonPath('data.quality_audit.status', 'bad')
            ->assertJsonPath('data.quality_audit.summary', 'План требует ручной проверки перед созданием черновика.');
    }

    public function test_create_cluster_drafts_respects_selected_cluster_ids_after_preview_confirmation(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9013]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [1]],
            'total_lines' => 2,
            'total_qty' => 15,
        ]);

        Product::factory()->ozon()->create([
            'integration_id' => $integration->id,
            'sku' => 'SKU-DRAFT-1',
            'ozon_data' => ['sku' => 111],
        ]);
        Product::factory()->ozon()->create([
            'integration_id' => $integration->id,
            'sku' => 'SKU-DRAFT-2',
            'ozon_data' => ['sku' => 222],
        ]);

        foreach ([1 => 'SKU-DRAFT-1', 2 => 'SKU-DRAFT-2'] as $clusterId => $sku) {
            AutoSupplyPlanLine::create([
                'auto_supply_plan_id' => $plan->id,
                'sku' => $sku,
                'offer_id' => $sku,
                'product_name' => $sku,
                'warehouse_id' => 'cluster:' . $clusterId,
                'warehouse_name' => 'Cluster ' . $clusterId,
                'cluster_id' => $clusterId,
                'cluster_name' => 'Cluster ' . $clusterId,
                'destination_type' => 'cluster',
                'qty_recommended' => 5,
                'qty_rounded' => 5,
                'risk_level' => 'low',
                'priority' => 'low',
            ]);
        }

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview");
        $preview->assertOk()
            ->assertJsonPath('data.confirmation_required', true)
            ->assertJsonPath('data.summary.safe_flow', 'preview_only')
            ->assertJsonPath('data.summary.ozon_api_called', false)
            ->assertJsonPath('data.summary.autobooking', false)
            ->assertJsonPath('data.summary.draft_creation_blocked', false)
            ->assertJsonPath('data.summary.selected_cluster_ids.0', 1)
            ->assertJsonPath('data.summary.preview_cluster_ids.0', 1)
            ->assertJsonPath('data.summary.total_sku', 1)
            ->assertJsonPath('data.summary.safety_checks.0.key', 'preview_only')
            ->assertJsonPath('data.summary.safety_checks.2.key', 'fingerprint_will_be_verified')
            ->assertJsonPath('data.summary.acceptance_audit.0.title_ru', 'Предпросмотр построен')
            ->assertJsonPath('data.summary.acceptance_audit.1.title_ru', 'Запрос в Ozon ещё не отправлялся')
            ->assertJsonPath('data.summary.acceptance_audit.2.status', 'required')
            ->assertJsonPath('data.summary.acceptance_audit.7.status', 'ready')
            ->assertJsonPath('data.confirmation_phrase', 'СОЗДАТЬ ЧЕРНОВИКИ OZON')
            ->assertJsonPath('data.summary.confirmation_phrase', 'СОЗДАТЬ ЧЕРНОВИКИ OZON')
            ->assertJsonPath('data.safe_flow_contract.version', 'ozon-draft-safe-flow-ui-1')
            ->assertJsonPath('data.safe_flow_contract.status', 'ready_for_confirmation')
            ->assertJsonPath('data.safe_flow_contract.primary_action_ru', 'Создать черновики Ozon')
            ->assertJsonPath('data.safe_flow_contract.primary_action_enabled', true)
            ->assertJsonPath('data.safe_flow_contract.frontend_flags.can_submit_create', true)
            ->assertJsonPath('data.safe_flow_contract.frontend_flags.show_no_autobooking_notice', true)
            ->assertJsonPath('data.safe_flow_contract.payload_requirements.1.field', 'confirmation_text')
            ->assertJsonPath('data.safe_flow_contract.payload_requirements.1.expected_value', 'СОЗДАТЬ ЧЕРНОВИКИ OZON')
            ->assertJsonPath('data.summary.safe_flow_contract.confirmation_phrase_ru', 'Введите точно: СОЗДАТЬ ЧЕРНОВИКИ OZON')
            ->assertJsonPath('data.clusters.0.items_count', 1)
            ->assertJsonCount(1, 'data.clusters');
        $this->assertIsString($preview->json('data.summary.preview_fingerprint_short'));
        $this->assertSame(12, strlen((string) $preview->json('data.summary.preview_fingerprint_short')));
        $confirmationToken = $preview->json('data.confirmation_token');

        $applier = Mockery::mock(LocalityDraftApplier::class);
        $applier->shouldReceive('applyBatch')
            ->once()
            ->withArgs(function (Integration $passedIntegration, array $items, int $clusterId) use ($integration) {
                return $passedIntegration->id === $integration->id
                    && $clusterId === 1
                    && $items === [['sku' => 111, 'quantity' => 5]];
            })
            ->andReturn(['success' => true, 'draft_id' => 'draft-1', 'error' => null]);
        $this->app->instance(LocalityDraftApplier::class, $applier);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => $confirmationToken,
            'confirmation_text' => 'СОЗДАТЬ ЧЕРНОВИКИ OZON',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.drafts.0.cluster_id', '1')
            ->assertJsonPath('data.safe_flow', 'preview_confirmed')
            ->assertJsonPath('data.confirmation_checked', true)
            ->assertJsonPath('data.preview_fingerprint_verified', true)
            ->assertJsonPath('data.safety_checks_passed', true)
            ->assertJsonPath('data.acceptance_audit.1.title_ru', 'Вызов Ozon разрешён после подтверждения')
            ->assertJsonPath('data.acceptance_audit.2.status', 'passed')
            ->assertJsonPath('data.acceptance_audit.3.title_ru', 'Контрольная подпись preview совпала')
            ->assertJsonPath('data.acceptance_audit.7.title_ru', 'Создание прошло через safe-flow')
            ->assertJsonCount(1, 'data.drafts')
            ->assertJsonCount(0, 'data.errors');
    }

    public function test_create_cluster_drafts_rejects_changed_plan_after_preview(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9015]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [1]],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-DRAFT-CHANGED',
            'offer_id' => 'SKU-DRAFT-CHANGED',
            'product_name' => 'SKU-DRAFT-CHANGED',
            'warehouse_id' => 'cluster:1',
            'warehouse_name' => 'Cluster 1',
            'cluster_id' => 1,
            'cluster_name' => 'Cluster 1',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview");
        $preview->assertOk();
        $confirmationToken = $preview->json('data.confirmation_token');

        $plan->lines()->first()->update(['qty_rounded' => 7]);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => $confirmationToken,
            'confirmation_text' => 'СОЗДАТЬ ЧЕРНОВИКИ OZON',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'preview_changed');
    }

    public function test_cluster_draft_preview_blocks_partial_selected_clusters(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9025]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [1, 2]],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-PARTIAL-CLUSTER',
            'offer_id' => 'SKU-PARTIAL-CLUSTER',
            'product_name' => 'SKU-PARTIAL-CLUSTER',
            'warehouse_id' => 'cluster:1',
            'warehouse_name' => 'Cluster 1',
            'cluster_id' => 1,
            'cluster_name' => 'Cluster 1',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview");
        $preview->assertOk()
            ->assertJsonPath('data.summary.draft_creation_allowed', false)
            ->assertJsonPath('data.summary.draft_creation_blocked', true)
            ->assertJsonPath('data.summary.selected_clusters_complete', false)
            ->assertJsonPath('data.summary.missing_selected_cluster_ids.0', 2)
            ->assertJsonPath('data.summary.safety_checks.1.passed', false)
            ->assertJsonPath('data.summary.acceptance_audit.4.status', 'failed')
            ->assertJsonPath('data.summary.acceptance_audit.4.missing_selected_cluster_ids.0', 2)
            ->assertJsonPath('data.summary.acceptance_audit.7.status', 'blocked')
            ->assertJsonPath('data.safe_flow_contract.status', 'blocked')
            ->assertJsonPath('data.safe_flow_contract.primary_action_enabled', false)
            ->assertJsonPath('data.safe_flow_contract.frontend_flags.show_blocking_reasons', true)
            ->assertJsonPath('data.safe_flow_contract.frontend_flags.can_submit_create', false);
        $this->assertStringContainsString(
            'Часть выбранных кластеров не попала в предпросмотр',
            (string) $preview->json('data.safe_flow_contract.disabled_reason_ru')
        );
        $this->assertContains(
            'Не все выбранные кластеры попали в предпросмотр: отсутствуют 2.',
            $preview->json('data.safe_flow_contract.blocking_checks_ru')
        );

        $this->assertContains(
            'Часть выбранных кластеров не попала в предпросмотр, потому что в плане нет строк к поставке для этих кластеров: 2. Создание черновика остановлено, чтобы не создать частичную поставку.',
            $preview->json('data.summary.warnings')
        );

        $applier = Mockery::mock(LocalityDraftApplier::class);
        $applier->shouldNotReceive('applyBatch');
        $this->app->instance(LocalityDraftApplier::class, $applier);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => $preview->json('data.confirmation_token'),
            'confirmation_text' => $preview->json('data.confirmation_phrase'),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'preview_not_allowed')
            ->assertJsonPath('data.summary.selected_clusters_complete', false)
            ->assertJsonPath('data.summary.missing_selected_cluster_ids.0', 2);
    }

    public function test_create_cluster_drafts_rejects_changed_quality_audit_after_preview(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9024]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [154]],
            'result_json' => [
                'plan_quality_audit' => [
                    'status' => 'good',
                    'summary_ru' => 'План достаточно надёжен для создания черновика.',
                    'acceptance_gates' => [
                        'can_create_ozon_draft' => true,
                    ],
                ],
            ],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-DRAFT-AUDIT-CHANGED',
            'offer_id' => 'SKU-DRAFT-AUDIT-CHANGED',
            'product_name' => 'SKU-DRAFT-AUDIT-CHANGED',
            'warehouse_id' => 'cluster:154',
            'warehouse_name' => 'Москва',
            'cluster_id' => 154,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview");
        $preview->assertOk()
            ->assertJsonPath('data.summary.draft_creation_allowed', true);

        $plan->result_json = [
            'plan_quality_audit' => [
                'status' => 'good',
                'summary_ru' => 'Аудит качества обновился после предпросмотра.',
                'acceptance_gates' => [
                    'can_create_ozon_draft' => true,
                ],
                'actions' => [
                    ['type' => 'review_after_recalculation', 'description' => 'Нужно открыть preview заново.'],
                ],
            ],
        ];
        $plan->save();

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => $preview->json('data.confirmation_token'),
            'confirmation_text' => 'СОЗДАТЬ ЧЕРНОВИКИ OZON',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'preview_changed');
    }

    public function test_create_cluster_drafts_requires_exact_confirmation_phrase(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9016]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [1]],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-DRAFT-PHRASE',
            'offer_id' => 'SKU-DRAFT-PHRASE',
            'product_name' => 'SKU-DRAFT-PHRASE',
            'warehouse_id' => 'cluster:1',
            'warehouse_name' => 'Cluster 1',
            'cluster_id' => 1,
            'cluster_name' => 'Cluster 1',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview");
        $preview->assertOk();

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => $preview->json('data.confirmation_token'),
            'confirmation_text' => 'создать',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'confirmation_phrase_required')
            ->assertJsonPath('expected_confirmation_phrase', 'СОЗДАТЬ ЧЕРНОВИКИ OZON');
    }

    public function test_cluster_draft_preview_requires_dropoff_point_for_crossdock(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9022]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [
                'cluster_ids' => [154],
                'draft_supply_method' => 'crossdock',
            ],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-CROSS-NO-DROPOFF',
            'offer_id' => 'SKU-CROSS-NO-DROPOFF',
            'product_name' => 'SKU-CROSS-NO-DROPOFF',
            'warehouse_id' => 'cluster:154',
            'warehouse_name' => 'Москва',
            'cluster_id' => 154,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview");
        $preview->assertOk()
            ->assertJsonPath('data.summary.supply_method', 'crossdock')
            ->assertJsonPath('data.summary.draft_creation_allowed', false)
            ->assertJsonPath('data.summary.draft_creation_blocked', true)
            ->assertJsonPath('data.summary.drop_off_point_warehouse_id', null)
            ->assertJsonPath('data.confirmation_phrase', 'СОЗДАТЬ КРОСС-ДОКИНГ OZON')
            ->assertJsonPath('data.summary.confirmation_phrase', 'СОЗДАТЬ КРОСС-ДОКИНГ OZON')
            ->assertJsonPath('data.summary.acceptance_audit.6.status', 'failed')
            ->assertJsonPath('data.summary.acceptance_audit.7.status', 'blocked')
            ->assertJsonPath('data.safe_flow_contract.title_ru', 'Безопасное создание кросс-докинга Ozon')
            ->assertJsonPath('data.safe_flow_contract.primary_action_ru', 'Создать кросс-докинг Ozon')
            ->assertJsonPath('data.safe_flow_contract.frontend_flags.show_drop_off_selector', true)
            ->assertJsonPath('data.safe_flow_contract.payload_requirements.2.field', 'drop_off_point_warehouse_id')
            ->assertJsonPath('data.safe_flow_contract.payload_requirements.2.required', true)
            ->assertJsonPath('data.safe_flow_contract.payload_requirements.2.current_value', null)
            ->assertJsonPath('data.safe_flow_contract.confirmation_phrase', 'СОЗДАТЬ КРОСС-ДОКИНГ OZON');

        $checks = collect($preview->json('data.summary.safety_checks'));
        $crossdockCheck = $checks->firstWhere('key', 'crossdock_drop_off_configured');
        $this->assertSame(false, $crossdockCheck['passed'] ?? null);
        $this->assertContains(
            'Для кросс-докинга Ozon укажите ID точки отгрузки: без неё сервер не будет создавать черновик.',
            $preview->json('data.summary.warnings')
        );

        $applier = Mockery::mock(LocalityDraftApplier::class);
        $applier->shouldNotReceive('applyBatch');
        $this->app->instance(LocalityDraftApplier::class, $applier);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => $preview->json('data.confirmation_token'),
            'confirmation_text' => $preview->json('data.confirmation_phrase'),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'preview_not_allowed')
            ->assertJsonPath('data.summary.supply_method', 'crossdock')
            ->assertJsonPath('data.summary.draft_creation_allowed', false);
    }

    public function test_crossdock_preview_can_use_dropoff_point_override_before_confirmation(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9024]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [
                'cluster_ids' => [154],
                'draft_supply_method' => 'crossdock',
            ],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        Product::factory()->ozon()->create([
            'integration_id' => $integration->id,
            'sku' => 'SKU-CROSS-OVERRIDE',
            'ozon_data' => ['sku' => 444],
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-CROSS-OVERRIDE',
            'offer_id' => 'SKU-CROSS-OVERRIDE',
            'product_name' => 'SKU-CROSS-OVERRIDE',
            'warehouse_id' => 'cluster:154',
            'warehouse_name' => 'Москва',
            'cluster_id' => 154,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview?drop_off_point_warehouse_id=777002");
        $preview->assertOk()
            ->assertJsonPath('data.summary.supply_method', 'crossdock')
            ->assertJsonPath('data.summary.drop_off_point_warehouse_id', 777002)
            ->assertJsonPath('data.summary.draft_creation_allowed', true)
            ->assertJsonPath('data.safe_flow_contract.frontend_flags.show_drop_off_selector', false)
            ->assertJsonPath('data.safe_flow_contract.payload_requirements.2.current_value', 777002);

        $applier = Mockery::mock(LocalityDraftApplier::class);
        $applier->shouldReceive('applyBatch')
            ->once()
            ->withArgs(function (Integration $passedIntegration, array $items, int $clusterId, array $options) use ($integration) {
                return $passedIntegration->id === $integration->id
                    && $clusterId === 154
                    && $items === [['sku' => 444, 'quantity' => 5]]
                    && $options['supply_method'] === 'crossdock'
                    && $options['drop_off_point_warehouse_id'] === 777002;
            })
            ->andReturn(['success' => true, 'draft_id' => 'draft-cross-override', 'error' => null, 'supply_method' => 'crossdock']);
        $this->app->instance(LocalityDraftApplier::class, $applier);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => $preview->json('data.confirmation_token'),
            'confirmation_text' => $preview->json('data.confirmation_phrase'),
            'drop_off_point_warehouse_id' => 777002,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.safe_flow', 'preview_confirmed')
            ->assertJsonPath('data.supply_method', 'crossdock')
            ->assertJsonPath('data.drop_off_point_warehouse_id', 777002)
            ->assertJsonPath('data.drafts.0.drop_off_point_warehouse_id', 777002);
    }

    public function test_crossdock_create_rejects_dropoff_point_changed_after_preview(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9025]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [
                'cluster_ids' => [154],
                'draft_supply_method' => 'crossdock',
            ],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        Product::factory()->ozon()->create([
            'integration_id' => $integration->id,
            'sku' => 'SKU-CROSS-CHANGED-DROPOFF',
            'ozon_data' => ['sku' => 445],
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-CROSS-CHANGED-DROPOFF',
            'offer_id' => 'SKU-CROSS-CHANGED-DROPOFF',
            'product_name' => 'SKU-CROSS-CHANGED-DROPOFF',
            'warehouse_id' => 'cluster:154',
            'warehouse_name' => 'Москва',
            'cluster_id' => 154,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview?drop_off_point_warehouse_id=777002");
        $preview->assertOk()
            ->assertJsonPath('data.summary.draft_creation_allowed', true)
            ->assertJsonPath('data.summary.drop_off_point_warehouse_id', 777002);

        $applier = Mockery::mock(LocalityDraftApplier::class);
        $applier->shouldNotReceive('applyBatch');
        $this->app->instance(LocalityDraftApplier::class, $applier);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => $preview->json('data.confirmation_token'),
            'confirmation_text' => $preview->json('data.confirmation_phrase'),
            'drop_off_point_warehouse_id' => 777003,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'drop_off_point_changed');
    }

    public function test_create_cluster_drafts_passes_crossdock_options_after_safe_preview(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9023]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [
                'cluster_ids' => [154],
                'draft_supply_method' => 'crossdock',
                'drop_off_point_warehouse_id' => 777001,
            ],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        Product::factory()->ozon()->create([
            'integration_id' => $integration->id,
            'sku' => 'SKU-CROSS-OK',
            'ozon_data' => ['sku' => 333],
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-CROSS-OK',
            'offer_id' => 'SKU-CROSS-OK',
            'product_name' => 'SKU-CROSS-OK',
            'warehouse_id' => 'cluster:154',
            'warehouse_name' => 'Москва',
            'cluster_id' => 154,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $preview = $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-draft-preview");
        $preview->assertOk()
            ->assertJsonPath('data.summary.supply_method', 'crossdock')
            ->assertJsonPath('data.summary.drop_off_point_warehouse_id', 777001)
            ->assertJsonPath('data.summary.draft_creation_allowed', true)
            ->assertJsonPath('data.confirmation_phrase', 'СОЗДАТЬ КРОСС-ДОКИНГ OZON')
            ->assertJsonPath('data.summary.confirmation_phrase', 'СОЗДАТЬ КРОСС-ДОКИНГ OZON')
            ->assertJsonPath('data.safe_flow_contract.status', 'ready_for_confirmation')
            ->assertJsonPath('data.safe_flow_contract.primary_action_ru', 'Создать кросс-докинг Ozon')
            ->assertJsonPath('data.safe_flow_contract.frontend_flags.show_drop_off_selector', false)
            ->assertJsonPath('data.safe_flow_contract.payload_requirements.2.current_value', 777001);

        $applier = Mockery::mock(LocalityDraftApplier::class);
        $applier->shouldReceive('applyBatch')
            ->once()
            ->withArgs(function (Integration $passedIntegration, array $items, int $clusterId, array $options) use ($integration) {
                return $passedIntegration->id === $integration->id
                    && $clusterId === 154
                    && $items === [['sku' => 333, 'quantity' => 5]]
                    && $options['supply_method'] === 'crossdock'
                    && $options['drop_off_point_warehouse_id'] === 777001;
            })
            ->andReturn(['success' => true, 'draft_id' => 'draft-cross-1', 'error' => null, 'supply_method' => 'crossdock']);
        $this->app->instance(LocalityDraftApplier::class, $applier);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts", [
            'confirmation_token' => $preview->json('data.confirmation_token'),
            'confirmation_text' => $preview->json('data.confirmation_phrase'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.safe_flow', 'preview_confirmed')
            ->assertJsonPath('data.supply_method', 'crossdock')
            ->assertJsonPath('data.drop_off_point_warehouse_id', 777001)
            ->assertJsonPath('data.drafts.0.supply_method', 'crossdock')
            ->assertJsonPath('data.drafts.0.drop_off_point_warehouse_id', 777001);
    }

    public function test_show_summary_financials_match_line_aggregates(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9003]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 2,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-X',
            'offer_id' => 'OFF-X',
            'product_name' => 'X',
            'warehouse_id' => 'w1',
            'warehouse_name' => 'WH1',
            'destination' => null,
            'destination_id' => null,
            'destination_type' => 'all',
            'cluster_name' => null,
            'region' => null,
            'qty_recommended' => 2,
            'qty_rounded' => 2,
            'risk_level' => 'med',
            'priority' => 'medium',
            'sales_trend' => 'growing',
            'supply_cost_estimate' => 10.5,
            'expected_revenue' => 100,
            'expected_profit' => 20,
            'lost_revenue_daily' => 1.25,
            'roi_percent' => 15.5,
            'turnover_days' => 12.3,
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}");

        $response->assertOk()
            ->assertJsonPath('data.summary.financials.total_supply_cost', 10.5)
            ->assertJsonPath('data.summary.financials.total_expected_revenue', 100)
            ->assertJsonPath('data.summary.financials.total_expected_profit', 20)
            ->assertJsonPath('data.summary.financials.total_lost_revenue_daily', 1.25)
            ->assertJsonPath('data.summary.financials.avg_roi_percent', 15.5)
            ->assertJsonPath('data.summary.financials.avg_turnover_days', 12.3)
            ->assertJsonPath('data.summary.risk_breakdown.med', 1)
            ->assertJsonPath('data.summary.priority_breakdown.medium', 1)
            ->assertJsonPath('data.summary.trend_breakdown.growing', 1);
    }

    public function test_show_forbidden_when_workspace_does_not_match_integration(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9004,
            'work_space_id' => 100,
        ]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 0,
            'total_qty' => 0,
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}", [
            'X-Sellico-Workspace' => '999',
        ]);

        $response->assertForbidden();
    }

    public function test_non_uuid_plan_id_returns_not_found(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $this->getJson('/api/auto-supply-plans/not-a-uuid')->assertNotFound();
    }
}
