<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoSupplyPlan\StoreAutoSupplyPlanRequest;
use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Models\AutoSupplyConstraintFile;
use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanAdjustment;
use App\Models\AutoSupplyPlanExecution;
use App\Models\AutoSupplyPlanLine;
use App\Models\InventoryWarehouse;
use App\Models\Integration;
use App\Models\LocalityRecommendation;
use App\Models\MarketplaceConstraintSnapshot;
use App\Models\OzonWarehouseCluster;
use App\Models\PlanLineEvaluation;
use App\Models\Product;
use App\Services\AutoSupplyPlanning\MarketplaceConstraintFileParser;
use App\Services\AutoSupplyPlanning\MarketplaceConstraintSyncService;
use App\Services\AutoSupplyPlanning\MarketplacePlanningCapabilityService;
use App\Services\AutoSupplyPlanning\AutoSupplyPlanExecutionService;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use App\Services\AutoSupplyPlanning\OzonCrossdockDropOffPointService;
use App\Services\AutoSupplyPlanning\OzonPlanMaterializationService;
use App\Services\AutoSupplyPlanning\OzonPlanValidationService;
use App\Services\AutoSupplyPlanning\OzonPlanningFactRefreshService;
use App\Services\AutoSupplyPlanning\PlanningReadinessChecklistService;
use App\Services\IntegrationAccessService;
use App\Services\LimitsSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AutoSupplyPlanController extends Controller
{
    public function __construct(
        private IntegrationAccessService $integrationAccessService,
        private LimitsSyncService $limitsSync
    ) {}

    /**
     * Загружает план по id и проверяет, что его интеграция принадлежит
     * workspace текущего запроса (защита от IDOR/BOLA). При отказе бросает
     * HttpException (403/404) — работает для любого типа ответа метода
     * (JsonResponse и StreamedResponse). Используйте вместо прямого
     * AutoSupplyPlan::findOrFail() во всех экшенах по {id}, чтобы проверку
     * нельзя было забыть.
     *
     * @param  array<int, string>  $with  жадно загружаемые связи
     */
    private function authorizedPlan(Request $request, string $id, array $with = []): AutoSupplyPlan
    {
        $plan = AutoSupplyPlan::with($with)->findOrFail($id);

        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);

        if (! ($access['success'] ?? false)) {
            abort($access['status'] ?? 403, $access['message'] ?? 'Доступ запрещён');
        }

        return $plan;
    }

    /**
     * GET /api/auto-supply-plans
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'nullable|integer',
            'status' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AutoSupplyPlan::with('integration')
            ->orderByDesc('created_at');

        if ($request->filled('integration_id')) {
            $integrationAccess = $this->integrationAccessService
                ->ensureAccessibleIntegration($request, (int) $request->input('integration_id'));

            if (! ($integrationAccess['success'] ?? false)) {
                return response()->json([
                    'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
                ], $integrationAccess['status'] ?? 404);
            }

            $query->where('integration_id', $integrationAccess['integration']->id);
        } else {
            $query->whereIn('integration_id', Integration::query()->select('id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = $request->input('per_page', 20);
        $plans = $query->paginate($perPage);

        return response()->json([
            'message' => 'OK',
            'data' => $plans,
        ]);
    }

    /**
     * GET /api/auto-supply-plans/capabilities?marketplace=ozon
     */
    public function capabilities(Request $request, MarketplacePlanningCapabilityService $service): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'nullable|integer',
            'marketplace' => 'nullable|string|in:ozon,wildberries,yandex,yandex_market',
        ]);

        $marketplace = $validated['marketplace'] ?? null;

        if (! empty($validated['integration_id'])) {
            $integrationAccess = $this->integrationAccessService
                ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);

            if (! ($integrationAccess['success'] ?? false)) {
                return response()->json([
                    'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
                ], $integrationAccess['status'] ?? 404);
            }

            /** @var Integration $integration */
            $integration = $integrationAccess['integration'];
            $marketplace = (string) $integration->marketplace;
        }

        $marketplace ??= 'ozon';

        return response()->json([
            'message' => 'Возможности автопланирования',
            'data' => $service->forMarketplace($marketplace),
        ]);
    }

    /**
     * POST /api/auto-supply-plans
     */
    public function store(StoreAutoSupplyPlanRequest $request): JsonResponse
    {
        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $request->input('integration_id'));

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];

        return $this->createPlanFromRequest($request, $integration);
    }

    private function createPlanFromRequest(Request $request, Integration $integration): JsonResponse
    {
        $limitResponse = $this->ensureAutoplanningLimitAvailable($integration);
        if ($limitResponse !== null) {
            return $limitResponse;
        }

        $normalizedClusterIds = $this->normalizeOzonClusterIdsFromRequest($request, $integration);
        $warehouseIds = $request->input('warehouse_ids');
        if ($integration->marketplace === 'ozon' && is_array($warehouseIds)) {
            $warehouseIds = array_values(array_filter(
                $warehouseIds,
                static fn ($warehouseId) => ! preg_match('/^cluster:\d+$/', (string) $warehouseId)
            ));
        }

        $planningMode = (string) $request->input('planning_mode', $request->input('mode', AutoSupplyPlan::MODE_BALANCED));
        $analysisPeriodDays = (int) $request->input('analysis_period_days', $request->input('horizon_days', 28));
        $seasonalityMultiplier = $request->input('seasonality_multiplier', $request->input('demand_seasonality_multiplier'));
        $draftSupplyMethod = $this->normalizeDraftSupplyMethod((string) $request->input('draft_supply_method', $request->input('supply_method', '')));
        // Источник ограничений по приоритету: явный запрос > ручной файл > авто-синк из API.
        $constraintFile = $this->resolveConstraintFile($request, $integration);
        $autoSnapshot = $this->resolveAutoConstraintSnapshot($request, $integration);
        $warehouseConstraints = $request->input('warehouse_constraints', $constraintFile?->warehouse_constraints_json ?? $autoSnapshot?->warehouse_constraints_json);
        $clusterConstraints = $request->input('cluster_constraints', $constraintFile?->cluster_constraints_json ?? $autoSnapshot?->cluster_constraints_json);
        $constraintMetadata = $request->input('constraint_metadata', $constraintFile?->toPlanMetadata() ?? $autoSnapshot?->toPlanMetadata());
        // PLAN-2: если применённого источника нет — пометить причину (устарел / ошибка / не подключён).
        $constraintSourceNote = $this->autoConstraintSourceNote($request, $integration, $autoSnapshot, $constraintFile);

        $params = array_filter([
            'planning_mode' => $planningMode,
            'analysis_period_days' => $analysisPeriodDays,
            'target_days' => $request->input('target_cover_days', 21),
            'safety_days' => $request->input('safety_stock_days', 5),
            'lead_time_days' => $request->input('lead_time_days', 7),
            'ewma_alpha' => 0.35,
            'warehouse_ids' => $warehouseIds,
            'cluster_ids' => $normalizedClusterIds,
            'warehouse_constraints' => $warehouseConstraints,
            'cluster_constraints' => $clusterConstraints,
            'constraint_file_id' => $constraintFile?->id ?? $request->input('constraint_file_id'),
            'use_latest_constraint_file' => $request->boolean('use_latest_constraint_file', false),
            'constraint_metadata' => $constraintMetadata,
            'constraint_source_note' => $constraintSourceNote,
            'target_ktr' => $request->input('target_ktr'),
            'baseline_ktr' => $request->input('baseline_ktr'),
            'draft_supply_method' => $draftSupplyMethod,
            'supply_method' => $draftSupplyMethod,
            'drop_off_point_warehouse_id' => $request->input('drop_off_point_warehouse_id', $request->input('crossdock_drop_off_point_warehouse_id')),

            // Advanced (используются алгоритмом расчёта — см. CalculateAutoSupplyPlanJob)
            'demand_seasonality_multiplier' => $seasonalityMultiplier,
            'seasonality_multiplier' => $seasonalityMultiplier,
            'trend_multiplier' => $request->input('trend_multiplier'),
            'promo_mode' => $request->input('promo_mode', $planningMode === AutoSupplyPlan::MODE_POST_PROMO_CAREFUL ? 'post_promo' : null),
            'include_in_transit' => $request->input('include_in_transit'),
            'skip_negative_profit' => $request->input('skip_negative_profit'),
            'include_wb_supplies_api_in_transit' => $request->input('include_wb_supplies_api_in_transit'),

            // Locality integration (читаются в CalculateAutoSupplyPlanJob и LocalityEnrichmentService)
            'split_by_cluster' => $request->input('split_by_cluster'),
            'minimum_locality_confidence' => $request->input('minimum_locality_confidence'),
            'include_locality_recommendations' => $request->input('include_locality_recommendations'),
            'locality_distribution_strategy' => $request->input('locality_distribution_strategy'),
            'locality_recommendation_ids' => $request->input('locality_recommendation_ids'),
        ], static fn ($v) => $v !== null && $v !== []);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => $integration->marketplace,
            'status' => AutoSupplyPlan::STATUS_PENDING,
            'mode' => $planningMode,
            'horizon_days' => $request->input('horizon_days', $analysisPeriodDays),
            'min_cover_days' => $request->input('min_cover_days', 7),
            'target_cover_days' => $request->input('target_cover_days', 21),
            'max_cover_days' => $request->input('max_cover_days', 42),
            'safety_stock_days' => $request->input('safety_stock_days', 5),
            'turnover_limit_days' => $request->input('turnover_limit_days'),
            'budget_limit' => $request->input('budget_limit'),
            'forecast_model' => 'EWMA_0.35',
            'algorithm_version' => 'asp-1.0.0',
            'params' => $params,
        ]);

        if ((int) ($integration->work_space_id ?? 0) > 0) {
            $this->limitsSync->syncWorkspaceAutoplanningLimit((int) $integration->work_space_id);
        }

        $constraintFile?->forceFill(['last_used_at' => now()])->save();

        CalculateAutoSupplyPlanJob::dispatch($plan->id);

        return response()->json([
            'message' => 'План создан, расчёт запущен',
            'data' => $plan->load('integration'),
        ], 201);
    }

    /**
     * POST /api/auto-supply-plans/constraints/preview
     */
    public function previewConstraints(Request $request, MarketplaceConstraintFileParser $parser): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|integer',
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:15360',
        ]);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];
        $result = $parser->parse($request->file('file'), (string) $integration->marketplace);
        if ($result['success'] ?? false) {
            $constraintFile = $this->persistConstraintFile($integration, $result['data']);
            $result['data']['constraint_file_id'] = $constraintFile->id;
        }

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * GET /api/auto-supply-plans/constraints
     */
    public function constraintFiles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|integer',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];
        $files = AutoSupplyConstraintFile::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', $integration->marketplace)
            ->orderByDesc('created_at')
            ->limit((int) ($validated['limit'] ?? 10))
            ->get()
            ->map(fn (AutoSupplyConstraintFile $file): array => $this->constraintFileResource($file))
            ->values();

        return response()->json([
            'message' => 'Файлы ограничений',
            'data' => $files,
        ]);
    }

    /**
     * GET /api/auto-supply-plans/crossdock-drop-off-points
     */
    public function crossdockDropOffPoints(Request $request, OzonCrossdockDropOffPointService $service): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|integer',
            'search' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];
        if ((string) $integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Точки кросс-докинг отгрузки доступны только для Ozon',
                'error' => 'unsupported_marketplace',
            ], 422);
        }

        $data = $service->list(
            $integration,
            trim((string) ($validated['search'] ?? '')),
            (int) ($validated['limit'] ?? 100)
        );

        return response()->json([
            'message' => 'Точки отгрузки Ozon для кросс-докинга',
            'data' => $data,
        ]);
    }

    private function resolveConstraintFile(Request $request, Integration $integration): ?AutoSupplyConstraintFile
    {
        $constraintFileId = $request->input('constraint_file_id');
        if ($constraintFileId !== null && $constraintFileId !== '') {
            return AutoSupplyConstraintFile::query()
                ->whereKey((int) $constraintFileId)
                ->where('integration_id', $integration->id)
                ->where('marketplace', $integration->marketplace)
                ->first();
        }

        if (! $request->boolean('use_latest_constraint_file', false)) {
            return null;
        }

        if ($request->has('warehouse_constraints') || $request->has('cluster_constraints')) {
            return null;
        }

        return $this->latestUsableConstraintFile($integration);
    }

    /**
     * Свежий авто-снапшот ограничений из API (этап 1, источник ниже ручного файла по приоритету).
     * Используется только если продавец не отключил авто-источник (use_auto_constraints)
     * и в запросе нет явных ограничений (иначе они и так перекроют по приоритету).
     */
    private function resolveAutoConstraintSnapshot(Request $request, Integration $integration): ?MarketplaceConstraintSnapshot
    {
        if (! $request->boolean('use_auto_constraints', true)) {
            return null;
        }

        if ($request->has('warehouse_constraints') || $request->has('cluster_constraints')) {
            return null;
        }

        return MarketplaceConstraintSnapshot::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', $integration->marketplace)
            ->usable()
            ->orderByDesc('synced_at')
            ->first();
    }

    /**
     * PLAN-2: причина отсутствия ограничений (для предупреждения в UI). Возвращает null,
     * если источник есть, продавец сам отключил авто или передал явные ограничения.
     */
    private function autoConstraintSourceNote(
        Request $request,
        Integration $integration,
        ?MarketplaceConstraintSnapshot $appliedSnapshot,
        ?AutoSupplyConstraintFile $appliedFile
    ): ?string {
        if ($appliedSnapshot !== null || $appliedFile !== null) {
            return null;
        }
        if (! $request->boolean('use_auto_constraints', true)) {
            return null;
        }
        if ($request->has('warehouse_constraints') || $request->has('cluster_constraints')) {
            return null;
        }

        $latest = MarketplaceConstraintSnapshot::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', $integration->marketplace)
            ->orderByDesc('synced_at')
            ->first();

        if ($latest === null) {
            return 'Ограничения МП не подключены: нет ни файла, ни авто-синка. План построен без ограничений складов.';
        }
        if ($latest->sync_status === 'error') {
            return 'Последний авто-синк ограничений завершился ошибкой — ограничения не применены.';
        }

        return 'Авто-снапшот ограничений устарел (> 48 ч) и не применён. Запустите синк или загрузите файл.';
    }

    /**
     * UI-2: компактный дескриптор источника ограничений для summary плана.
     *
     * @return array<string, mixed>
     */
    private function constraintSourceDescriptor(AutoSupplyPlan $plan): array
    {
        $params = is_array($plan->params) ? $plan->params : [];
        $metadata = is_array($params['constraint_metadata'] ?? null) ? $params['constraint_metadata'] : [];
        $hasConstraints = ! empty($params['warehouse_constraints']) || ! empty($params['cluster_constraints']);
        $kind = $metadata['source_kind'] ?? ($hasConstraints ? 'request_params' : 'none');

        return [
            'kind' => $kind, // api_sync | constraint_file | request_params | none
            'has_constraints' => $hasConstraints,
            'synced_at' => $metadata['source']['synced_at'] ?? null,
            'sync_status' => $metadata['source']['sync_status'] ?? null,
            'file_name' => $metadata['file']['name'] ?? null,
            'warning' => $params['constraint_source_note'] ?? null,
            // Честная граница: лимит штук и потребность по WB/Ozon вводятся вручную.
            'manual_fields_note' => in_array($plan->marketplace, ['wildberries', 'ozon'], true)
                ? 'Лимит штук (max_qty) и потребность (need_qty) — вручную: API маркетплейса их не отдаёт'
                : null,
        ];
    }

    private function latestUsableConstraintFile(Integration $integration): ?AutoSupplyConstraintFile
    {
        return AutoSupplyConstraintFile::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', $integration->marketplace)
            ->orderByDesc('parsed_at')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->first(fn (AutoSupplyConstraintFile $file): bool => $this->constraintFileHasPlanningPayload($file));
    }

    private function constraintFileHasPlanningPayload(AutoSupplyConstraintFile $file): bool
    {
        $summary = is_array($file->summary_json) ? $file->summary_json : [];
        $planningRoles = is_array($summary['planning_roles'] ?? null) ? $summary['planning_roles'] : [];
        $sourceTypeCounts = is_array($summary['source_type_counts'] ?? null) ? $summary['source_type_counts'] : [];

        if ((int) $file->constraints_count > 0 || (int) ($summary['constraints_count'] ?? 0) > 0) {
            return true;
        }

        if (
            (int) ($summary['marketplace_needs_count'] ?? 0) > 0
            || (int) ($summary['coefficient_lines_count'] ?? 0) > 0
            || (int) ($summary['limit_lines_count'] ?? 0) > 0
            || (int) ($summary['blocked_lines_count'] ?? 0) > 0
            || (int) ($sourceTypeCounts['marketplace_need'] ?? 0) > 0
            || (int) ($sourceTypeCounts['constraint_and_need'] ?? 0) > 0
        ) {
            return true;
        }

        foreach (['used_as_constraints', 'used_as_marketplace_needs', 'used_as_coefficients'] as $roleKey) {
            if (! empty($planningRoles[$roleKey])) {
                return true;
            }
        }

        return count($file->cluster_constraints_json ?? []) > 0
            || count($file->warehouse_constraints_json ?? []) > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistConstraintFile(Integration $integration, array $data): AutoSupplyConstraintFile
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $hash = $file['sha256'] ?? null;
        $attributes = [
            'integration_id' => $integration->id,
            'marketplace' => (string) $integration->marketplace,
            'file_name' => (string) ($file['name'] ?? 'constraints'),
            'file_size_bytes' => isset($file['size_bytes']) ? (int) $file['size_bytes'] : null,
            'file_hash' => $hash,
            'parser_version' => $summary['parser_version'] ?? null,
            'rows_total' => (int) ($summary['rows_total'] ?? 0),
            'constraints_count' => (int) ($summary['constraints_count'] ?? 0),
            'warnings_count' => (int) ($summary['warnings_count'] ?? count($data['warnings'] ?? [])),
            'cluster_constraints_json' => $data['cluster_constraints'] ?? [],
            'warehouse_constraints_json' => $data['warehouse_constraints'] ?? [],
            'summary_json' => $summary,
            'warnings_json' => $data['warnings'] ?? [],
            'parsed_at' => now(),
        ];

        if ($hash) {
            return AutoSupplyConstraintFile::query()->updateOrCreate([
                'integration_id' => $integration->id,
                'file_hash' => $hash,
            ], $attributes);
        }

        return AutoSupplyConstraintFile::query()->create($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function constraintFileResource(AutoSupplyConstraintFile $file): array
    {
        $summary = is_array($file->summary_json) ? $file->summary_json : [];

        return [
            'id' => $file->id,
            'integration_id' => $file->integration_id,
            'marketplace' => $file->marketplace,
            'file_name' => $file->file_name,
            'file_size_bytes' => $file->file_size_bytes,
            'file_hash' => $file->file_hash,
            'parser_version' => $file->parser_version,
            'rows_total' => $file->rows_total,
            'constraints_count' => $file->constraints_count,
            'marketplace_needs_count' => (int) ($summary['marketplace_needs_count'] ?? 0),
            'coefficient_lines_count' => (int) ($summary['coefficient_lines_count'] ?? 0),
            'warnings_count' => $file->warnings_count,
            'usable_for_planning' => $this->constraintFileHasPlanningPayload($file),
            'planning_roles' => is_array($summary['planning_roles'] ?? null) ? $summary['planning_roles'] : [],
            'cluster_constraints' => $file->cluster_constraints_json ?? [],
            'warehouse_constraints' => $file->warehouse_constraints_json ?? [],
            'summary' => $summary,
            'warnings' => $file->warnings_json ?? [],
            'parsed_at' => $file->parsed_at?->toIso8601String(),
            'last_used_at' => $file->last_used_at?->toIso8601String(),
            'created_at' => $file->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<int>|null
     */
    private function normalizeOzonClusterIdsFromRequest(Request $request, Integration $integration): ?array
    {
        if ($integration->marketplace !== 'ozon') {
            return $request->input('cluster_ids');
        }

        $clusterIds = collect((array) $request->input('cluster_ids', []))
            ->map(fn ($clusterId) => (int) $clusterId);

        $legacyClusterIds = collect((array) $request->input('warehouse_ids', []))
            ->map(function ($warehouseId) {
                $warehouseId = (string) $warehouseId;
                if (preg_match('/^cluster:(\d+)$/', $warehouseId, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter();

        $normalized = $clusterIds
            ->merge($legacyClusterIds)
            ->filter(fn (int $clusterId) => $clusterId > 0)
            ->unique()
            ->values()
            ->all();

        return $normalized === [] ? null : $normalized;
    }

    private function ensureAutoplanningLimitAvailable(Integration $integration): ?JsonResponse
    {
        $workspaceId = (int) ($integration->work_space_id ?? 0);
        if ($workspaceId <= 0) {
            return null;
        }

        $limitCheck = $this->limitsSync->ensureLimitAvailable($workspaceId, 'autoplanning', 1);
        if ($limitCheck['success'] ?? false) {
            return null;
        }

        return response()->json(
            $this->limitsSync->limitResponsePayload($limitCheck),
            (int) ($limitCheck['status'] ?? 403)
        );
    }

    /**
     * POST /api/auto-supply-plans/{id}/calculate
     */
    public function calculate(Request $request, string $id): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);

        $blocked = \DB::transaction(function () use ($plan): bool {
            $lockedPlan = AutoSupplyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($lockedPlan->supplies()->exists()) {
                return true;
            }

            // Удаляем старые строки только после блокировки версии плана.
            $lockedPlan->lines()->delete();
            $lockedPlan->update([
                'status' => AutoSupplyPlan::STATUS_PENDING,
                'business_status' => AutoSupplyPlan::BUSINESS_STATUS_DRAFT,
                'approved_at' => null,
                'approved_by' => null,
                'approval_fingerprint' => null,
                'materialized_at' => null,
                'error_message' => null,
                'data_quality_score' => null,
                'data_quality_json' => null,
                'total_lines' => 0,
                'total_qty' => 0,
            ]);

            return false;
        });

        if ($blocked) {
            return response()->json([
                'message' => 'Нельзя пересчитать план, по которому уже созданы поставки. Создайте новый план.',
                'error' => 'plan_already_materialized',
            ], 409);
        }

        CalculateAutoSupplyPlanJob::dispatch($plan->id);

        return response()->json([
            'message' => 'Пересчёт запущен',
            'data' => $plan->fresh()->load('integration'),
        ]);
    }

    /**
     * POST /api/auto-supply-plans/{id}/approve
     */
    public function approve(
        Request $request,
        string $id,
        OzonPlanMaterializationService $service
    ): JsonResponse {
        $plan = $this->authorizedPlan($request, $id);
        $check = $service->approvalCheck($plan);

        if (! $check['allowed']) {
            return response()->json([
                'message' => 'План нельзя утвердить, пока не устранены блокирующие ошибки.',
                'error' => 'approval_blocked',
                'data' => ['approval_check' => $check],
            ], 422);
        }

        $approved = $service->approve($plan, $this->requestUserId($request));

        return response()->json([
            'message' => 'План Ozon утверждён',
            'data' => [
                'plan' => $approved,
                'approval_check' => $check,
            ],
        ]);
    }

    /**
     * POST /api/auto-supply-plans/{id}/validate
     */
    public function validatePlan(
        Request $request,
        string $id,
        OzonPlanValidationService $service
    ): JsonResponse {
        $plan = $this->authorizedPlan($request, $id);
        $validation = $service->validate($plan);

        return response()->json([
            'message' => $validation['allowed']
                ? 'План прошёл проверку и готов к утверждению'
                : 'План заблокирован проверкой',
            'data' => [
                'plan' => $plan->fresh(),
                'validation' => $validation,
            ],
        ], $validation['allowed'] ? 200 : 422);
    }

    /**
     * POST /api/auto-supply-plans/{id}/materialize-supplies
     *
     * Создаёт только локальные Supply/SupplyItem. Вызов Ozon Seller API
     * выполняется отдельным явным действием в модуле поставок.
     */
    public function materializeSupplies(
        Request $request,
        string $id,
        OzonPlanMaterializationService $service
    ): JsonResponse {
        $plan = $this->authorizedPlan($request, $id);
        $result = $service->materialize($plan, $this->requestUserId($request));
        $status = $result['created_count'] > 0 ? 201 : 200;

        return response()->json([
            'message' => $result['created_count'] > 0
                ? 'Локальные поставки Ozon созданы'
                : 'Поставки уже были созданы ранее, дубли не добавлены',
            'data' => [
                'plan' => $result['plan'],
                'supplies' => $result['supplies'],
                'created_count' => $result['created_count'],
                'idempotent' => $result['created_count'] === 0,
                'external_api_called' => false,
            ],
        ], $status);
    }

    /**
     * GET /api/auto-supply-plans/{id}/execution
     */
    public function execution(
        Request $request,
        string $id,
        OzonPlanMaterializationService $service
    ): JsonResponse {
        $plan = $this->authorizedPlan($request, $id, [
            'supplies.items',
            'executions.supplies.items',
        ]);

        return response()->json([
            'message' => 'OK',
            'data' => [
                'plan' => $plan,
                'approval_check' => $service->approvalCheck($plan),
                'supplies' => $plan->supplies,
                'executions' => $plan->executions,
            ],
        ]);
    }

    /**
     * POST /api/auto-supply-plans/{id}/execute
     */
    public function executePlan(
        Request $request,
        string $id,
        AutoSupplyPlanExecutionService $service
    ): JsonResponse {
        $validated = $request->validate([
            'idempotency_key' => 'required|string|min:8|max:120',
            'confirmation_text' => 'required|string',
            'auto_book_timeslot' => 'sometimes|boolean',
            'timeslot_preferences' => 'sometimes|array',
            'timeslot_preferences.target_date' => 'sometimes|date',
            'timeslot_preferences.weekdays' => 'sometimes|array',
            'timeslot_preferences.weekdays.*' => 'integer|min:1|max:7',
            'timeslot_preferences.time_from' => 'sometimes|date_format:H:i',
            'timeslot_preferences.time_to' => 'sometimes|date_format:H:i',
        ]);
        $plan = $this->authorizedPlan($request, $id);
        $result = $service->start(
            $plan,
            $validated['idempotency_key'],
            $validated['confirmation_text'],
            $this->requestUserId($request),
            $validated
        );

        return response()->json([
            'message' => $result['created']
                ? 'Исполнение поставок Ozon поставлено в очередь'
                : 'Операция с таким ключом уже существует',
            'data' => [
                'execution' => $result['execution'],
                'idempotent' => ! $result['created'],
                'confirmation_phrase' => AutoSupplyPlanExecutionService::CONFIRMATION_PHRASE,
            ],
        ], $result['created'] ? 202 : 200);
    }

    /**
     * POST /api/auto-supply-plans/{id}/executions/{executionId}/retry
     */
    public function retryExecution(
        Request $request,
        string $id,
        string $executionId,
        AutoSupplyPlanExecutionService $service
    ): JsonResponse {
        $plan = $this->authorizedPlan($request, $id);
        $validated = $request->validate([
            'confirmed_no_external_draft' => 'sometimes|boolean',
        ]);
        $execution = AutoSupplyPlanExecution::query()
            ->where('auto_supply_plan_id', $plan->id)
            ->findOrFail($executionId);

        return response()->json([
            'message' => 'Повторная попытка поставлена в очередь',
            'data' => [
                'execution' => $service->retry(
                    $execution,
                    (bool) ($validated['confirmed_no_external_draft'] ?? false)
                ),
            ],
        ], 202);
    }

    /**
     * GET /api/auto-supply-plans/{id}/accuracy
     * Точность план-факта (этап 2): агрегат по плану + пер-строчные оценки.
     */
    public function accuracy(Request $request, string $id): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);

        $lines = PlanLineEvaluation::query()
            ->where('auto_supply_plan_id', $plan->id)
            ->orderByRaw('abs_pct_error IS NULL, abs_pct_error DESC')
            ->get([
                'sku', 'cluster_id', 'warehouse_id', 'horizon_days',
                'forecast_demand_qty', 'actual_sales_qty', 'abs_pct_error', 'bias_pct',
                'planned_qty', 'accepted_qty', 'rejected_qty', 'acceptance_rate',
                'status', 'details_json', 'evaluated_at',
            ]);

        return response()->json([
            'message' => 'OK',
            'data' => [
                'plan_id' => $plan->id,
                'evaluation_status' => $plan->accuracy_json === null ? 'not_evaluated' : 'evaluated',
                'summary' => $plan->accuracy_json,
                'lines' => $lines,
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/lines
     * Агрегирует строки по SKU — одна строка на товар, qty суммируется по всем складам
     */
    public function lines(Request $request, string $id): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);
        $request->validate([
            'risk_level' => 'nullable|string|in:low,med,medium,high,critical',
            'offer_id' => 'nullable|string|max:255',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'cluster_id' => 'nullable|string|max:64',
            'is_excluded' => 'nullable|boolean',
            'missing_cost' => 'nullable|boolean',
            'negative_profit' => 'nullable|boolean',
            'urgent_oos' => 'nullable|boolean',
            'blocked' => 'nullable|boolean',
            'low_confidence' => 'nullable|boolean',
            'new_product' => 'nullable|boolean',
            'budget_cut' => 'nullable|boolean',
            'manually_modified' => 'nullable|boolean',
            'min_qty' => 'nullable|integer|min:0',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = $this->planLinesQueryForSelectedClusters($plan);

        if ($request->filled('risk_level')) {
            $query->where('risk_level', $request->input('risk_level'));
        }

        if ($request->filled('offer_id')) {
            $query->where('offer_id', $request->input('offer_id'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }
        if ($request->filled('cluster_id')) {
            $query->where('cluster_id', (string) $request->input('cluster_id'));
        }
        if ($request->has('is_excluded')) {
            $query->where('is_excluded', $request->boolean('is_excluded'));
        }
        if ($request->boolean('missing_cost')) {
            $query->whereNull('cost_price');
        }
        if ($request->boolean('negative_profit')) {
            $query->where('expected_profit', '<', 0);
        }
        if ($request->boolean('urgent_oos')) {
            $urgentUntil = CarbonImmutable::now()->addDays(7)->toDateString();
            $query->where(function ($inner) use ($urgentUntil) {
                $inner->whereIn('risk_level', ['high', 'critical'])
                    ->orWhere(function ($dateQuery) use ($urgentUntil) {
                        $dateQuery->whereNotNull('oos_date')
                            ->where('oos_date', '<=', $urgentUntil);
                    });
            });
        }
        if ($request->boolean('blocked')) {
            $query->where(function ($inner) {
                $inner->where('is_excluded', true)
                    ->orWhereNull('cost_price')
                    ->orWhere('expected_profit', '<', 0)
                    ->orWhereIn('explain_json->confidence->confidence_level', ['low', 'bad']);
            });
        }
        if ($request->boolean('low_confidence')) {
            $query->whereIn('explain_json->confidence->confidence_level', ['low', 'bad', 'warning']);
        }
        if ($request->boolean('new_product')) {
            $query->where('explain_json->inputs->supply_type', 'new_warehouse');
        }
        if ($request->boolean('budget_cut')) {
            $query->where('explain_json->optimizer_rejection->reason', 'budget_limit');
        }
        if ($request->boolean('manually_modified')) {
            $query->whereNotNull('manual_updated_at');
        }
        if ($request->filled('min_qty')) {
            $query->where('qty_rounded', '>=', max(0, (int) $request->input('min_qty')));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($inner) use ($search) {
                $inner->where('sku', 'like', '%' . $search . '%')
                    ->orWhere('offer_id', 'like', '%' . $search . '%')
                    ->orWhere('product_name', 'like', '%' . $search . '%');
            });
        }

        $perPage = $request->input('per_page', 50);
        $lines = $this->paginateAggregatedPlanLines($plan, $query, (int) $perPage);

        return response()->json([
            'message' => 'OK',
            'data' => $lines,
            'review' => [
                'business_status' => $plan->business_status,
                'validation' => $plan->validation_json,
                'manual_adjustments_count' => $plan->adjustments()->count(),
                'excluded_lines_count' => $plan->lines()->where('is_excluded', true)->count(),
                'missing_cost_lines_count' => $plan->lines()->whereNull('cost_price')->count(),
                'negative_profit_lines_count' => $plan->lines()->where('expected_profit', '<', 0)->count(),
                'budget_cut_lines_count' => $plan->lines()
                    ->where('explain_json->optimizer_rejection->reason', 'budget_limit')
                    ->count(),
                'manual_lines_count' => $plan->lines()->whereNotNull('manual_updated_at')->count(),
                'low_confidence_lines_count' => $plan->lines()
                    ->whereIn('explain_json->confidence->confidence_level', ['low', 'bad', 'warning'])
                    ->count(),
            ],
        ]);
    }

    public function adjustments(Request $request, string $id): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);
        $items = $plan->adjustments()
            ->with('line:id,sku,offer_id,cluster_id,cluster_name')
            ->orderByDesc('created_at')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 50))));

        return response()->json(['message' => 'OK', 'data' => $items]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/compare
     *
     * Сравнивает текущий review-план с явно выбранным или предыдущим готовым
     * планом той же интеграции. Сравнение выполняется в каноническом разрезе
     * SKU × кластер и учитывает исключённые/ручные строки.
     */
    public function compare(Request $request, string $id): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);
        $validated = $request->validate([
            'with_plan_id' => 'nullable|uuid',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
            'changed_only' => 'nullable|boolean',
        ]);

        if (! empty($validated['with_plan_id'])) {
            $other = $this->authorizedPlan($request, (string) $validated['with_plan_id']);
        } else {
            $other = AutoSupplyPlan::query()
                ->where('integration_id', $plan->integration_id)
                ->where('marketplace', $plan->marketplace)
                ->where('status', AutoSupplyPlan::STATUS_READY)
                ->where('id', '!=', $plan->id)
                ->where('created_at', '<=', $plan->created_at)
                ->orderByDesc('created_at')
                ->first();
        }

        if ($other === null) {
            return response()->json([
                'message' => 'Предыдущий готовый план для сравнения не найден.',
                'error' => 'comparison_plan_not_found',
            ], 404);
        }
        if ((int) $other->integration_id !== (int) $plan->integration_id
            || $other->marketplace !== $plan->marketplace) {
            return response()->json([
                'message' => 'Сравнивать можно только планы одной интеграции и маркетплейса.',
                'error' => 'comparison_scope_mismatch',
            ], 422);
        }

        $current = $this->comparisonMap($plan);
        $previous = $this->comparisonMap($other);
        $keys = array_values(array_unique(array_merge(array_keys($current), array_keys($previous))));
        $rows = collect($keys)->map(function (string $key) use ($current, $previous): array {
            $now = $current[$key] ?? null;
            $before = $previous[$key] ?? null;
            $qtyNow = (int) ($now['effective_qty'] ?? 0);
            $qtyBefore = (int) ($before['effective_qty'] ?? 0);
            $costNow = (float) ($now['effective_cost'] ?? 0);
            $costBefore = (float) ($before['effective_cost'] ?? 0);
            $change = match (true) {
                $before === null => 'added',
                $now === null => 'removed',
                $qtyNow > $qtyBefore => 'increased',
                $qtyNow < $qtyBefore => 'decreased',
                ($now['review_status'] ?? null) !== ($before['review_status'] ?? null) => 'status_changed',
                default => 'unchanged',
            };

            return [
                'key' => $key,
                'sku' => $now['sku'] ?? $before['sku'],
                'offer_id' => $now['offer_id'] ?? $before['offer_id'],
                'product_name' => $now['product_name'] ?? $before['product_name'],
                'cluster_id' => $now['cluster_id'] ?? $before['cluster_id'],
                'cluster_name' => $now['cluster_name'] ?? $before['cluster_name'],
                'change' => $change,
                'previous' => $before,
                'current' => $now,
                'delta' => [
                    'qty' => $qtyNow - $qtyBefore,
                    'supply_cost' => round($costNow - $costBefore, 2),
                    'demand_daily' => round(
                        (float) ($now['demand_daily'] ?? 0) - (float) ($before['demand_daily'] ?? 0),
                        4
                    ),
                    'stock' => (int) ($now['current_stock'] ?? 0) - (int) ($before['current_stock'] ?? 0),
                    'in_transit' => (int) ($now['in_transit'] ?? 0) - (int) ($before['in_transit'] ?? 0),
                ],
            ];
        });

        if ($request->boolean('changed_only')) {
            $rows = $rows->where('change', '!=', 'unchanged')->values();
        }
        $rows = $rows->sortByDesc(fn (array $row): int => abs((int) $row['delta']['qty']))->values();

        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($validated['per_page'] ?? 50)));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        $summary = [
            'rows_total' => $rows->count(),
            'changed_rows' => $rows->where('change', '!=', 'unchanged')->count(),
            'added_rows' => $rows->where('change', 'added')->count(),
            'removed_rows' => $rows->where('change', 'removed')->count(),
            'increased_rows' => $rows->where('change', 'increased')->count(),
            'decreased_rows' => $rows->where('change', 'decreased')->count(),
            'previous_total_qty' => array_sum(array_column($previous, 'effective_qty')),
            'current_total_qty' => array_sum(array_column($current, 'effective_qty')),
            'previous_total_supply_cost' => round(array_sum(array_column($previous, 'effective_cost')), 2),
            'current_total_supply_cost' => round(array_sum(array_column($current, 'effective_cost')), 2),
        ];
        $summary['delta_qty'] = $summary['current_total_qty'] - $summary['previous_total_qty'];
        $summary['delta_supply_cost'] = round(
            $summary['current_total_supply_cost'] - $summary['previous_total_supply_cost'],
            2
        );

        return response()->json([
            'message' => 'Сравнение планов построено',
            'data' => [
                'current_plan_id' => $plan->id,
                'previous_plan_id' => $other->id,
                'summary' => $summary,
                'rows' => $paginator,
            ],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function comparisonMap(AutoSupplyPlan $plan): array
    {
        return $this->planLinesQueryForSelectedClusters($plan)
            ->get()
            ->groupBy(fn (AutoSupplyPlanLine $line): string => implode('|', [
                $line->sku,
                $plan->marketplace === 'ozon' ? ($line->cluster_id ?? 'no-cluster') : 'all',
            ]))
            ->map(function (Collection $lines): array {
                /** @var AutoSupplyPlanLine $first */
                $first = $lines->first();
                $active = $lines->reject(fn (AutoSupplyPlanLine $line): bool => (bool) $line->is_excluded);
                $levels = $lines->pluck('confidence')->filter()->all();
                $confidence = 'good';
                foreach ($levels as $level) {
                    $confidence = $this->worstConfidenceLevel($confidence, (string) $level);
                }

                return [
                    'sku' => $first->sku,
                    'offer_id' => $first->offer_id,
                    'product_name' => $first->product_name,
                    'cluster_id' => $first->cluster_id,
                    'cluster_name' => $first->cluster_name,
                    'effective_qty' => (int) $active->sum('qty_rounded'),
                    'candidate_qty' => (int) $lines->sum('candidate_quantity'),
                    'effective_cost' => round((float) $active->sum('supply_cost_estimate'), 2),
                    'demand_daily' => round((float) $lines->sum('demand_daily'), 4),
                    'current_stock' => (int) $lines->sum('current_stock'),
                    'in_transit' => (int) $lines->sum('in_transit'),
                    'confidence' => $confidence,
                    'review_status' => $active->isEmpty()
                        ? 'blocked'
                        : ($lines->contains(fn (AutoSupplyPlanLine $line): bool => $line->manual_updated_at !== null)
                            ? 'manually_modified'
                            : 'recommended'),
                    'manually_modified' => $lines->contains(
                        fn (AutoSupplyPlanLine $line): bool => $line->manual_updated_at !== null
                    ),
                    'not_recommended_reasons' => $lines->pluck('not_recommended_reason')->filter()->unique()->values()->all(),
                ];
            })
            ->all();
    }

    public function bulkUpdateLines(Request $request, string $id): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);
        $validated = $request->validate([
            'line_ids' => 'required|array|min:1|max:500',
            'line_ids.*' => 'integer|distinct',
            'action' => 'required|string|in:exclude,include,set_quantity,multiply_quantity,minimum_quantity,maximum_quantity',
            'quantity' => 'required_if:action,set_quantity,minimum_quantity,maximum_quantity|nullable|integer|min:0',
            'multiplier' => 'required_if:action,multiply_quantity|nullable|numeric|min:0|max:10',
            'reason' => 'required|string|max:1000',
            'comment' => 'nullable|string|max:2000',
        ]);
        $userId = $this->requestUserId($request);

        $result = \DB::transaction(function () use ($plan, $validated, $userId): ?array {
            $lockedPlan = AutoSupplyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($lockedPlan->supplies()->exists()) {
                return null;
            }

            $anchors = $lockedPlan->lines()
                ->whereIn('id', array_map('intval', $validated['line_ids']))
                ->lockForUpdate()
                ->get();
            if ($anchors->count() !== count($validated['line_ids'])) {
                abort(422, 'Часть строк не принадлежит этому плану');
            }
            $groups = $anchors
                ->mapWithKeys(function (AutoSupplyPlanLine $anchor) use ($lockedPlan): array {
                    $key = $anchor->sku . '|' . (
                        $lockedPlan->marketplace === 'ozon'
                            ? ($anchor->cluster_id ?? 'no-cluster')
                            : 'all'
                    );

                    return [$key => [
                        'anchor' => $anchor,
                        'lines' => $this->aggregatedSiblingLines($lockedPlan, $anchor),
                    ]];
                });
            $affectedSourceLines = 0;

            foreach ($groups as $group) {
                /** @var AutoSupplyPlanLine $anchor */
                $anchor = $group['anchor'];
                /** @var Collection<int, AutoSupplyPlanLine> $siblings */
                $siblings = $group['lines'];
                $affectedSourceLines += $siblings->count();
                $oldQty = (int) $siblings->sum('qty_rounded');
                $oldExcluded = $siblings->every(
                    fn (AutoSupplyPlanLine $sibling): bool => (bool) $sibling->is_excluded
                );
                $old = [
                    'qty_rounded' => $oldQty,
                    'is_excluded' => $oldExcluded,
                ];
                $newQty = match ($validated['action']) {
                    'set_quantity' => (int) $validated['quantity'],
                    'multiply_quantity' => (int) round(
                        $oldQty * (float) $validated['multiplier']
                    ),
                    'minimum_quantity' => max($oldQty, (int) $validated['quantity']),
                    'maximum_quantity' => min($oldQty, (int) $validated['quantity']),
                    default => $oldQty,
                };
                $excluded = match ($validated['action']) {
                    'exclude' => true,
                    'include' => false,
                    'set_quantity', 'multiply_quantity', 'minimum_quantity', 'maximum_quantity' => $newQty > 0
                        ? false
                        : $oldExcluded,
                    default => $oldExcluded,
                };

                if (in_array($validated['action'], [
                    'set_quantity',
                    'multiply_quantity',
                    'minimum_quantity',
                    'maximum_quantity',
                ], true)) {
                    $this->redistributeQtyAcrossLines($siblings, $newQty);
                }
                foreach ($siblings as $sibling) {
                    $sibling->update([
                        'is_excluded' => $excluded,
                        'manual_comment' => $validated['comment'] ?? $sibling->manual_comment,
                        'manual_override_json' => [
                            'action' => $validated['action'],
                            'aggregate_qty_rounded' => $newQty,
                            'is_excluded' => $excluded,
                            'reason' => $validated['reason'],
                        ],
                        'manual_updated_by' => $userId,
                        'manual_updated_at' => now(),
                    ]);
                }
                AutoSupplyPlanAdjustment::query()->create([
                    'auto_supply_plan_id' => $lockedPlan->id,
                    'auto_supply_plan_line_id' => $anchor->id,
                    'user_id' => $userId,
                    'action' => $validated['action'],
                    'old_values_json' => $old,
                    'new_values_json' => [
                        'qty_rounded' => $newQty,
                        'is_excluded' => $excluded,
                    ],
                    'reason' => $validated['reason'],
                ]);
            }

            $review = $this->refreshPlanAfterManualChange($lockedPlan);

            return [
                'updated_count' => $groups->count(),
                'affected_source_lines_count' => $affectedSourceLines,
                'total_qty' => $review['total_qty'],
                'total_supply_cost' => $review['total_supply_cost'],
                'budget_limit' => $review['budget_limit'],
                'budget_overrun' => $review['budget_overrun'],
                'business_status' => $review['business_status'],
            ];
        });

        if ($result === null) {
            return response()->json([
                'message' => 'Нельзя изменить план, по которому уже созданы поставки.',
                'error' => 'plan_already_materialized',
            ], 409);
        }

        return response()->json(['message' => 'Строки обновлены', 'data' => $result]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/simulate?offer_id=...&destination_id=...
     */
    public function simulate(Request $request, string $id): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);

        $request->validate([
            'offer_id' => 'required|string',
            'destination_id' => 'nullable|string',
        ]);

        $query = $this->planLinesQueryForSelectedClusters($plan)->where('offer_id', $request->input('offer_id'));

        if ($request->filled('destination_id')) {
            $query->where('destination_id', $request->input('destination_id'));
        }

        $line = $query->first();

        if (! $line) {
            return response()->json(['message' => 'Строка не найдена'], 404);
        }

        return response()->json([
            'message' => 'OK',
            'data' => [
                'line' => $line,
                'simulation' => $line->simulation_json,
                'explain' => $line->explain_json,
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::with('integration')->findOrFail($id);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Доступ запрещён',
            ], $integrationAccess['status'] ?? 403);
        }

        $perPage = $request->input('per_page', 50);
        $linesQuery = $this->planLinesQueryForSelectedClusters($plan);
        $lines = $this->paginateAggregatedPlanLines($plan, $linesQuery, (int) $perPage);

        // Финансовые агрегаты
        $allLines = $this->planLinesQueryForSelectedClusters($plan)->where('is_excluded', false);
        $totalSupplyCost = (float) $allLines->sum('supply_cost_estimate');
        $totalExpectedRevenue = (float) $this->planLinesQueryForSelectedClusters($plan)->where('is_excluded', false)->sum('expected_revenue');
        $totalExpectedProfit = (float) $this->planLinesQueryForSelectedClusters($plan)->where('is_excluded', false)->sum('expected_profit');
        $totalLostRevenueDaily = (float) $this->planLinesQueryForSelectedClusters($plan)->where('is_excluded', false)->sum('lost_revenue_daily');
        $avgRoi = (float) $this->planLinesQueryForSelectedClusters($plan)->where('is_excluded', false)->whereNotNull('roi_percent')->avg('roi_percent');
        $avgTurnover = (float) $this->planLinesQueryForSelectedClusters($plan)->where('is_excluded', false)->whereNotNull('turnover_days')->avg('turnover_days');
        $scopedTotalLines = (int) $this->planLinesQueryForSelectedClusters($plan)->count();
        $scopedTotalQty = (int) $this->planLinesQueryForSelectedClusters($plan)->where('is_excluded', false)->sum('qty_rounded');

        // Приоритет breakdown
        $priorityBreakdown = [
            'critical' => $this->planLinesQueryForSelectedClusters($plan)->where('priority', 'critical')->count(),
            'high' => $this->planLinesQueryForSelectedClusters($plan)->where('priority', 'high')->count(),
            'medium' => $this->planLinesQueryForSelectedClusters($plan)->where('priority', 'medium')->count(),
            'low' => $this->planLinesQueryForSelectedClusters($plan)->where('priority', 'low')->count(),
        ];

        // Тренд breakdown
        $trendBreakdown = [
            'growing' => $this->planLinesQueryForSelectedClusters($plan)->where('sales_trend', 'growing')->count(),
            'stable' => $this->planLinesQueryForSelectedClusters($plan)->where('sales_trend', 'stable')->count(),
            'declining' => $this->planLinesQueryForSelectedClusters($plan)->where('sales_trend', 'declining')->count(),
        ];

        return response()->json([
            'message' => 'OK',
            'data' => [
                'plan' => $plan,
                'lines' => $lines,
                'summary' => [
                    'total_lines' => $scopedTotalLines,
                    'total_qty' => $scopedTotalQty,
                    'data_quality_score' => $plan->data_quality_score,
                    'data_quality_json' => $plan->data_quality_json,
                    'snapshot_id' => $plan->snapshot_id,
                    'facts_freshness' => $plan->facts_freshness,
                    'planning_sources' => $plan->planning_sources,
                    'planning_source_cards' => $this->planningSourceCards($plan),
                    'planning_readiness' => app(PlanningReadinessChecklistService::class)->build($plan),
                    'demand_granularity' => $plan->demand_granularity,
                    'quality_gate_status' => $plan->quality_gate_status,
                    'quality_gate_reasons' => $plan->quality_gate_reasons,
                    'deficit_summary' => $plan->deficit_summary,
                    'surplus_summary' => $plan->surplus_summary,
                    'deficit_surplus_summary' => $plan->deficit_surplus_summary,
                    'economics_summary' => $plan->economics_summary,
                    'selection_summary' => $plan->selection_summary,
                    'constraints_summary' => $plan->constraints_summary,
                    'constraint_source' => $this->constraintSourceDescriptor($plan),
                    'accuracy' => $plan->accuracy_json,
                    'territorial_summary' => $plan->territorial_summary,
                    'plan_quality_audit' => $plan->plan_quality_audit,
                    'marketplace_capabilities' => $plan->marketplace_capabilities,
                    'risk_breakdown' => [
                        'high' => $this->planLinesQueryForSelectedClusters($plan)->where('risk_level', 'high')->count(),
                        'med' => $this->planLinesQueryForSelectedClusters($plan)->where('risk_level', 'med')->count(),
                        'low' => $this->planLinesQueryForSelectedClusters($plan)->where('risk_level', 'low')->count(),
                    ],
                    'priority_breakdown' => $priorityBreakdown,
                    'trend_breakdown' => $trendBreakdown,
                    'financials' => [
                        'total_supply_cost' => round($totalSupplyCost, 2),
                        'total_expected_revenue' => round($totalExpectedRevenue, 2),
                        'total_expected_profit' => round($totalExpectedProfit, 2),
                        'total_lost_revenue_daily' => round($totalLostRevenueDaily, 2),
                        'avg_roi_percent' => round($avgRoi, 2),
                        'avg_turnover_days' => round($avgTurnover, 1),
                    ],
                    'redistribution' => $plan->result_json['redistribution'] ?? [],
                ],
            ],
        ]);
    }

    /**
     * POST /api/auto-supply-plans/{id}/fix-ktr-baseline
     */
    public function fixKtrBaseline(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'target_ktr' => 'nullable|numeric|min:1|max:100',
        ]);

        $plan = AutoSupplyPlan::with('integration')->findOrFail($id);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Доступ запрещён',
            ], $integrationAccess['status'] ?? 403);
        }

        $territorial = $plan->territorial_summary;
        $ktr = is_array($territorial['ktr'] ?? null) ? $territorial['ktr'] : [];
        $currentKtr = isset($ktr['value']) && is_numeric($ktr['value'])
            ? round(max(0.0, min(100.0, (float) $ktr['value'])), 2)
            : null;

        if ($currentKtr === null) {
            return response()->json([
                'message' => 'КТР ещё не рассчитан для этого плана',
                'error' => 'ktr_not_available',
            ], 422);
        }

        $params = is_array($plan->params ?? null) ? $plan->params : [];
        $targetKtr = isset($validated['target_ktr']) && is_numeric($validated['target_ktr'])
            ? round(max(1.0, min(100.0, (float) $validated['target_ktr'])), 2)
            : round(max(1.0, min(100.0, (float) ($ktr['target_value'] ?? $params['target_ktr'] ?? 80))), 2);

        $params['baseline_ktr'] = $currentKtr;
        $params['target_ktr'] = $targetKtr;

        $resultJson = is_array($plan->result_json ?? null) ? $plan->result_json : [];
        $resultJson['territorial_summary'] = $this->territorialSummaryWithFixedKtr(
            $territorial,
            $currentKtr,
            $targetKtr
        );

        $plan->forceFill([
            'params' => $params,
            'result_json' => $resultJson,
        ])->save();
        $plan->refresh();

        return response()->json([
            'message' => 'КТР зафиксирован как база сравнения',
            'data' => [
                'plan' => $plan,
                'baseline_ktr' => $currentKtr,
                'target_ktr' => $targetKtr,
                'territorial_summary' => $plan->territorial_summary,
                'planning_readiness' => app(PlanningReadinessChecklistService::class)->build($plan),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $territorial
     * @return array<string, mixed>
     */
    private function territorialSummaryWithFixedKtr(array $territorial, float $baselineKtr, float $targetKtr): array
    {
        $ktr = is_array($territorial['ktr'] ?? null) ? $territorial['ktr'] : [];
        $targetGap = round(max(0.0, $targetKtr - $baselineKtr), 2);
        $baselineGap = round($targetKtr - $baselineKtr, 2);

        $ktr['baseline_value'] = $baselineKtr;
        $ktr['target_value'] = $targetKtr;
        $ktr['baseline_gap_pp'] = $baselineGap;
        $ktr['improvement_vs_baseline_pp'] = 0.0;
        $ktr['target_gap_pp'] = $targetGap;

        $fixation = is_array($ktr['fixation'] ?? null) ? $ktr['fixation'] : [];
        $fixation['version'] = $fixation['version'] ?? 'ktr-fixation-1';
        $fixation['can_fix_current_value'] = true;
        $fixation['current_value'] = $baselineKtr;
        $fixation['fixed_baseline_value'] = $baselineKtr;
        $fixation['target_value'] = $targetKtr;
        $fixation['tracking_status'] = 'unchanged';
        $fixation['tracking_status_ru'] = 'зафиксирован';
        $fixation['improvement_vs_fixed_pp'] = 0.0;
        $fixation['target_gap_pp'] = $targetGap;
        $fixation['freeze_payload'] = [
            'baseline_ktr' => $baselineKtr,
            'target_ktr' => $targetKtr,
        ];
        $fixation['state_ru'] = 'КТР зафиксирован как база сравнения.';
        $fixation['next_action_ru'] = $targetGap <= 0
            ? 'Цель КТР достигнута: контролируйте экономику, ограничения и стабильность распределения.'
            : 'Следующие планы будут сравниваться с этой базой: система покажет, улучшилось или ухудшилось территориальное распределение.';
        $fixation['explanation_ru'] = 'Фиксация КТР сохраняет текущее территориальное распределение как базу. Следующие планы сравниваются с этой базой, чтобы показывать реальное улучшение или ухудшение.';
        $ktr['fixation'] = $fixation;

        $controlLoop = is_array($ktr['control_loop'] ?? null) ? $ktr['control_loop'] : [];
        $controlLoop['version'] = $controlLoop['version'] ?? 'ktr-control-loop-1';
        $controlLoop['current_value'] = $baselineKtr;
        $controlLoop['fixed_baseline_value'] = $baselineKtr;
        $controlLoop['target_value'] = $targetKtr;
        $controlLoop['state_ru'] = $fixation['state_ru'];
        $controlLoop['next_action_ru'] = $fixation['next_action_ru'];
        $ktr['control_loop'] = $controlLoop;

        $territorial['ktr'] = $ktr;

        return $territorial;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planningSourceCards(AutoSupplyPlan $plan): array
    {
        $sources = $plan->planning_sources;
        $cards = [
            $this->sourceCard('demand', 'Спрос', $sources['demand'] ?? null),
            $this->sourceCard('stock', 'Остатки', $sources['stock'] ?? null),
            $this->sourceCard('turnover', 'Оборачиваемость', $sources['turnover'] ?? null),
            $this->sourceCard('in_transit', 'Товары в пути', $sources['in_transit'] ?? null),
            $this->sourceCard('constraints', 'Ограничения', $sources['constraints'] ?? null, [
                'status' => $sources['constraints_status'] ?? null,
                'file' => $sources['constraint_source_file'] ?? null,
                'parser_version' => $sources['constraint_parser_version'] ?? null,
                'requires_review' => $sources['constraints_requires_review'] ?? false,
            ]),
            $this->sourceCard('marketplace_needs', 'Потребности маркетплейса', $sources['marketplace_needs'] ?? null, [
                'status' => $sources['marketplace_needs_status'] ?? null,
                'file' => $sources['constraint_source_file'] ?? null,
                'qty' => $sources['marketplace_need_qty'] ?? null,
                'requires_review' => $sources['constraints_has_unmatched_marketplace_needs'] ?? false,
            ]),
        ];

        return array_values(array_filter($cards, static fn (array $card): bool => $card['source'] !== null || $card['status'] !== 'missing'));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function sourceCard(string $key, string $title, mixed $source, array $extra = []): array
    {
        $source = $source !== null && trim((string) $source) !== '' ? (string) $source : null;
        $status = $source !== null ? 'connected' : 'missing';

        if (($extra['requires_review'] ?? false) === true) {
            $status = 'review';
        }

        return array_merge([
            'key' => $key,
            'title_ru' => $title,
            'source' => $source,
            'source_label_ru' => $this->planningSourceLabel($source),
            'status' => $status,
            'status_ru' => match ($status) {
                'connected' => 'используется',
                'review' => 'нужна проверка',
                default => 'нет данных',
            },
        ], $extra);
    }

    private function planningSourceLabel(?string $source): ?string
    {
        return match ($source) {
            'posting_fbo_v3' => 'Заказы FBO из API Ozon',
            'ozon_order_report' => 'Отчёт заказов Ozon',
            'analytics_stocks' => 'Аналитика остатков маркетплейса',
            'product_info_stocks' => 'Текущие остатки товаров',
            'inventory_warehouses' => 'Синхронизированные остатки',
            'turnover_stocks' => 'Оборачиваемость Ozon',
            'average_delivery_time_summary' => 'Среднее время доставки Ozon',
            'supply_orders' => 'Заявки поставки в пути',
            'constraint_file' => 'Файл ограничений/потребностей',
            'request_params' => 'Параметры запроса',
            default => $source,
        };
    }

    private function paginateAggregatedPlanLines(AutoSupplyPlan $plan, $query, int $perPage)
    {
        $warehouseBreakdownMap = $this->buildWarehouseBreakdownMap($plan);
        $aggregatedExplainMap = $this->buildAggregatedExplainMap($plan, clone $query);
        $paginator = $this->aggregatedPlanLinesQuery($plan, $query)->paginate($perPage);
        $paginator->getCollection()->transform(function (AutoSupplyPlanLine $line) use (
            $plan,
            $warehouseBreakdownMap,
            $aggregatedExplainMap
        ) {
            return $this->normalizeAggregatedPlanLine(
                $plan,
                $line,
                $warehouseBreakdownMap,
                $aggregatedExplainMap
            );
        });

        return $paginator;
    }

    private function planLinesQueryForSelectedClusters(AutoSupplyPlan $plan)
    {
        $selectedClusterIds = $this->selectedPlanClusterIds($plan);

        return $plan->lines()
            ->when(
                $plan->marketplace === 'ozon' && $selectedClusterIds !== [],
                fn ($query) => $query->whereIn('cluster_id', $selectedClusterIds)
            );
    }

    private function aggregatedPlanLinesQuery(AutoSupplyPlan $plan, $query)
    {
        $isOzon = $plan->marketplace === 'ozon';
        $clusterSelect = $isOzon ? 'cluster_id' : 'MAX(cluster_id) as cluster_id';
        $driver = \DB::connection()->getDriverName();
        $idSelect = 'MIN(id) as id';
        $planIdSelect = $driver === 'pgsql'
            ? 'MIN(auto_supply_plan_id::text) as auto_supply_plan_id'
            : 'MIN(auto_supply_plan_id) as auto_supply_plan_id';
        $explainSelect = $driver === 'pgsql'
            ? 'MIN(explain_json::text) as explain_json'
            : 'MIN(explain_json) as explain_json';
        $isExcludedSelect = $driver === 'pgsql'
            ? 'BOOL_AND(is_excluded) as is_excluded'
            : 'MIN(is_excluded) as is_excluded';
        $riskSelect = "CASE MAX(CASE risk_level
            WHEN 'critical' THEN 4
            WHEN 'high' THEN 3
            WHEN 'med' THEN 2
            WHEN 'medium' THEN 2
            ELSE 1 END)
            WHEN 4 THEN 'critical'
            WHEN 3 THEN 'high'
            WHEN 2 THEN 'med'
            ELSE 'low' END as risk_level";
        $prioritySelect = "CASE MAX(CASE priority
            WHEN 'critical' THEN 4
            WHEN 'high' THEN 3
            WHEN 'medium' THEN 2
            ELSE 1 END)
            WHEN 4 THEN 'critical'
            WHEN 3 THEN 'high'
            WHEN 2 THEN 'medium'
            ELSE 'low' END as priority";
        $demandSelect = $isOzon ? 'SUM(demand_daily) as demand_daily' : 'MAX(demand_daily) as demand_daily';
        $averageDemandSelect = $isOzon
            ? 'SUM(avg_daily_sales) as avg_daily_sales, SUM(ewma_daily_sales) as ewma_daily_sales'
            : 'MAX(avg_daily_sales) as avg_daily_sales, MAX(ewma_daily_sales) as ewma_daily_sales';

        return $query
            ->selectRaw("
                {$idSelect},
                {$planIdSelect},
                sku,
                MAX(offer_id) as offer_id,
                MAX(product_name) as product_name,
                MAX(barcode) as barcode,
                MAX(warehouse_id) as warehouse_id,
                MAX(warehouse_name) as warehouse_name,
                {$clusterSelect},
                MAX(cluster_name) as cluster_name,
                MAX(region) as region,
                MAX(destination) as destination,
                MAX(destination_id) as destination_id,
                MAX(destination_type) as destination_type,
                SUM(qty_rounded) as qty_rounded,
                SUM(qty_recommended) as qty_recommended,
                SUM(current_stock) as current_stock,
                SUM(in_transit) as in_transit,
                SUM(sales_7_days) as sales_7_days,
                SUM(sales_14_days) as sales_14_days,
                SUM(sales_30_days) as sales_30_days,
                {$averageDemandSelect},
                {$demandSelect},
                MIN(cover_days_before) as cover_days_before,
                MAX(cover_days_after) as cover_days_after,
                MIN(oos_date) as oos_date,
                {$riskSelect},
                {$prioritySelect},
                MAX(priority_score) as priority_score,
                MAX(sales_trend) as sales_trend,
                MAX(sales_trend_percent) as sales_trend_percent,
                MAX(price) as price,
                MAX(cost_price) as cost_price,
                SUM(supply_cost_estimate) as supply_cost_estimate,
                SUM(expected_revenue) as expected_revenue,
                SUM(expected_profit) as expected_profit,
                MAX(roi_percent) as roi_percent,
                MAX(turnover_days) as turnover_days,
                SUM(storage_cost_daily) as storage_cost_daily,
                SUM(storage_cost_monthly) as storage_cost_monthly,
                SUM(lost_revenue_daily) as lost_revenue_daily,
                SUM(original_qty_rounded) as original_qty_rounded,
                {$isExcludedSelect},
                MAX(manual_comment) as manual_comment,
                MAX(manual_updated_at) as manual_updated_at,
                {$explainSelect}
            ")
            ->when($isOzon, fn ($q) => $q->groupBy('sku', 'cluster_id'), fn ($q) => $q->groupBy('sku'))
            ->orderByRaw("MAX(CASE risk_level WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'med' THEN 2 WHEN 'medium' THEN 2 ELSE 1 END) DESC")
            ->orderByRaw('SUM(qty_rounded) DESC');
    }

    private function normalizeAggregatedPlanLine(
        AutoSupplyPlan $plan,
        AutoSupplyPlanLine $line,
        array $warehouseBreakdownMap = [],
        array $aggregatedExplainMap = []
    ): AutoSupplyPlanLine
    {
        if ($plan->marketplace !== 'ozon') {
            return $line;
        }

        $key = $line->sku . '|' . ($line->cluster_id ?? 'no-cluster');
        if (isset($aggregatedExplainMap[$key])) {
            $aggregate = $aggregatedExplainMap[$key];
            $explain = is_array($line->explain_json) ? $line->explain_json : [];
            $explain['aggregated_quantity_explanation'] = $aggregate['quantity_explanation'];
            $explain['aggregated_sources'] = $aggregate['sources'];
            $explain['confidence'] = array_merge(
                is_array($explain['confidence'] ?? null) ? $explain['confidence'] : [],
                $aggregate['confidence']
            );
            // Аксессоры deficit_qty/surplus_qty читают inputs.daily_demand и
            // inputs.target_cover_days. После GROUP BY explain_json приходит из
            // MIN()-строки: спрос одной под-строки против SUM остатков занижал
            // дефицит и завышал профицит. Подменяем входы агрегатом.
            $inputs = is_array($explain['inputs'] ?? null) ? $explain['inputs'] : [];
            $inputs['daily_demand'] = $aggregate['demand_daily'];
            $inputs['target_cover_days'] = $aggregate['quantity_explanation']['target_cover_days'];
            $explain['inputs'] = $inputs;
            $line->setAttribute('explain_json', $explain);
            $line->setAttribute('demand_daily', $aggregate['demand_daily']);
            $line->setAttribute('cover_days_before', $aggregate['cover_days_before']);
            $line->setAttribute('cover_days_after', $aggregate['cover_days_after']);
        }
        if (isset($warehouseBreakdownMap[$key])) {
            $line->setAttribute('warehouse_breakdown', $warehouseBreakdownMap[$key]);
        } else {
            $line->setAttribute('warehouse_breakdown', []);
        }

        if ($line->cluster_id === null) {
            return $line;
        }

        $clusterName = $line->cluster_name
            ?: $line->destination
            ?: ($line->cluster_id ? 'Кластер ' . $line->cluster_id : null);

        $line->setAttribute('destination_type', 'cluster');
        $line->setAttribute('destination_id', 'cluster:' . $line->cluster_id);
        $line->setAttribute('destination', $clusterName);

        if ($clusterName) {
            $line->setAttribute('warehouse_id', 'cluster:' . $line->cluster_id);
            $line->setAttribute('warehouse_name', $clusterName);
            $line->setAttribute('cluster_name', $clusterName);
        }

        return $line;
    }

    /**
     * Строит согласованное объяснение для показанной строки Ozon
     * (SKU × кластер). Это устраняет старую ошибку, когда SQL суммировал
     * остатки, но explain_json случайно брался только у одной дочерней строки.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildAggregatedExplainMap(AutoSupplyPlan $plan, $query): array
    {
        if ($plan->marketplace !== 'ozon') {
            return [];
        }

        $map = [];
        foreach ($query->get() as $line) {
            $key = $line->sku . '|' . ($line->cluster_id ?? 'no-cluster');
            $explain = is_array($line->explain_json) ? $line->explain_json : [];
            $inputs = is_array($explain['inputs'] ?? null) ? $explain['inputs'] : [];
            $math = is_array($explain['math'] ?? null) ? $explain['math'] : [];
            $confidence = is_array($explain['confidence'] ?? null) ? $explain['confidence'] : [];
            $level = (string) ($confidence['confidence_level'] ?? 'good');

            $map[$key] ??= [
                'source_lines' => 0,
                'demand_daily' => 0.0,
                'stock_now' => 0,
                'in_transit' => 0,
                'qty_rounded' => 0,
                'target_cover_days' => 0.0,
                'safety_stock' => 0.0,
                'needed_before_caps' => 0.0,
                'needed_after_caps' => 0.0,
                'caps_applied' => [],
                'confidence_level' => 'good',
                'confidence_reasons' => [],
                'demand_sources' => [],
            ];

            $map[$key]['source_lines']++;
            $map[$key]['demand_daily'] += max(0.0, (float) ($inputs['daily_demand'] ?? $line->demand_daily ?? 0));
            $map[$key]['stock_now'] += max(0, (int) $line->current_stock);
            $map[$key]['in_transit'] += max(0, (int) $line->in_transit);
            $map[$key]['qty_rounded'] += max(0, (int) $line->qty_rounded);
            $map[$key]['target_cover_days'] = max(
                $map[$key]['target_cover_days'],
                (float) ($inputs['target_cover_days'] ?? 0)
            );
            $map[$key]['safety_stock'] += max(0.0, (float) ($math['safety_stock'] ?? 0));
            $map[$key]['needed_before_caps'] += max(0.0, (float) ($math['needed_before_caps'] ?? 0));
            $map[$key]['needed_after_caps'] += max(0.0, (float) ($math['needed_after_caps'] ?? 0));
            $map[$key]['caps_applied'] = array_values(array_unique(array_merge(
                $map[$key]['caps_applied'],
                array_values(array_filter((array) ($math['caps_applied'] ?? [])))
            )));
            $map[$key]['confidence_reasons'] = array_values(array_unique(array_merge(
                $map[$key]['confidence_reasons'],
                array_values(array_filter((array) ($confidence['confidence_reasons'] ?? [])))
            )));
            $map[$key]['confidence_level'] = $this->worstConfidenceLevel(
                $map[$key]['confidence_level'],
                $level
            );
            if (! empty($inputs['demand_source'])) {
                $map[$key]['demand_sources'][] = (string) $inputs['demand_source'];
            }
        }

        foreach ($map as $key => $aggregate) {
            $dailyDemand = round((float) $aggregate['demand_daily'], 4);
            $availableBefore = (int) $aggregate['stock_now'] + (int) $aggregate['in_transit'];
            $availableAfter = $availableBefore + (int) $aggregate['qty_rounded'];
            $map[$key] = [
                'demand_daily' => $dailyDemand,
                'cover_days_before' => $dailyDemand > 0 ? round($availableBefore / $dailyDemand, 2) : null,
                'cover_days_after' => $dailyDemand > 0 ? round($availableAfter / $dailyDemand, 2) : null,
                'quantity_explanation' => [
                    'aggregation_scope' => 'sku_cluster',
                    'source_lines' => (int) $aggregate['source_lines'],
                    'daily_demand' => $dailyDemand,
                    'target_cover_days' => round((float) $aggregate['target_cover_days'], 2),
                    'safety_stock' => round((float) $aggregate['safety_stock'], 2),
                    'stock_now' => (int) $aggregate['stock_now'],
                    'in_transit' => (int) $aggregate['in_transit'],
                    'needed_before_caps' => round((float) $aggregate['needed_before_caps'], 2),
                    'needed_after_caps' => round((float) $aggregate['needed_after_caps'], 2),
                    'qty_rounded' => (int) $aggregate['qty_rounded'],
                    'caps_applied' => $aggregate['caps_applied'],
                ],
                'confidence' => [
                    'confidence_level' => $aggregate['confidence_level'],
                    'confidence_reasons' => $aggregate['confidence_reasons'],
                ],
                'sources' => [
                    'demand' => array_values(array_unique($aggregate['demand_sources'])),
                ],
            ];
        }

        return $map;
    }

    private function worstConfidenceLevel(string $left, string $right): string
    {
        $rank = ['good' => 0, 'warning' => 1, 'low' => 2, 'bad' => 3];

        return ($rank[$right] ?? 1) > ($rank[$left] ?? 1) ? $right : $left;
    }

    private function buildWarehouseBreakdownMap(AutoSupplyPlan $plan): array
    {
        if ($plan->marketplace !== 'ozon') {
            return [];
        }

        $planKeys = $this->planLinesQueryForSelectedClusters($plan)
            ->whereNotNull('cluster_id')
            ->get(['sku', 'cluster_id']);

        if ($planKeys->isEmpty()) {
            return [];
        }

        $skus = $planKeys->pluck('sku')->filter()->unique()->values();
        $clusterIds = $planKeys->pluck('cluster_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $inventoryBySkuAndWarehouse = [];
        InventoryWarehouse::query()
            ->where('integration_id', $plan->integration_id)
            ->where('marketplace', 'ozon')
            ->whereIn('sku', $skus)
            ->get(['sku', 'warehouse_id', 'warehouse_name', 'quantity', 'in_transit', 'sales_7_days', 'sales_30_days'])
            ->each(function (InventoryWarehouse $warehouse) use (&$inventoryBySkuAndWarehouse) {
                if (! $warehouse->warehouse_name) {
                    return;
                }

                $key = $warehouse->sku . '|' . OzonWarehouseCluster::normalizeWarehouseName($warehouse->warehouse_name);
                $inventoryBySkuAndWarehouse[$key] ??= [
                    'warehouse_id' => $warehouse->warehouse_id,
                    'warehouse_name' => $warehouse->warehouse_name,
                    'current_stock' => 0,
                    'in_transit' => 0,
                    'sales_7_days' => 0,
                    'sales_30_days' => 0,
                ];

                $inventoryBySkuAndWarehouse[$key]['current_stock'] += (int) $warehouse->quantity;
                $inventoryBySkuAndWarehouse[$key]['in_transit'] += (int) $warehouse->in_transit;
                $inventoryBySkuAndWarehouse[$key]['sales_7_days'] += (int) $warehouse->sales_7_days;
                $inventoryBySkuAndWarehouse[$key]['sales_30_days'] += (int) $warehouse->sales_30_days;
            });

        $clusterWarehouses = [];
        foreach ($clusterIds as $clusterId) {
            $clusterWarehouses[$clusterId] = OzonWarehouseCluster::getWarehousesByCluster($clusterId)
                ->filter(fn (OzonWarehouseCluster $warehouse) => $this->isRegularOzonClusterWarehouse($warehouse))
                ->map(fn (OzonWarehouseCluster $warehouse) => [
                    'warehouse_id' => null,
                    'warehouse_name' => $warehouse->warehouse_name,
                    'warehouse_name_normalized' => $warehouse->warehouse_name_normalized
                        ?: OzonWarehouseCluster::normalizeWarehouseName($warehouse->warehouse_name),
                ])
                ->values()
                ->all();
        }

        $map = [];
        foreach ($planKeys as $line) {
            $clusterId = (int) $line->cluster_id;
            $key = $line->sku . '|' . $clusterId;
            $map[$key] = [];

            foreach ($clusterWarehouses[$clusterId] ?? [] as $warehouse) {
                $inventoryKey = $line->sku . '|' . $warehouse['warehouse_name_normalized'];
                $inventory = $inventoryBySkuAndWarehouse[$inventoryKey] ?? null;

                $map[$key][] = [
                    'warehouse_id' => $inventory['warehouse_id'] ?? $warehouse['warehouse_id'],
                    'warehouse_name' => $inventory['warehouse_name'] ?? $warehouse['warehouse_name'],
                    'current_stock' => (int) ($inventory['current_stock'] ?? 0),
                    'in_transit' => (int) ($inventory['in_transit'] ?? 0),
                    'sales_7_days' => (int) ($inventory['sales_7_days'] ?? 0),
                    'sales_30_days' => (int) ($inventory['sales_30_days'] ?? 0),
                    'has_inventory' => $inventory !== null,
                ];
            }

            usort($map[$key], fn ($a, $b) => ($b['current_stock'] + $b['in_transit']) <=> ($a['current_stock'] + $a['in_transit']));
        }

        return $map;
    }

    private function isRegularOzonClusterWarehouse(OzonWarehouseCluster $warehouse): bool
    {
        if ($warehouse->is_negabarit || $warehouse->is_jewelry) {
            return false;
        }

        $name = OzonWarehouseCluster::normalizeWarehouseName($warehouse->warehouse_name);

        foreach (['АПТЕКА', 'ФОТОСТУДИЯ', 'ВОЗВРАТ', 'КГТ', 'ПАЛЛЕТ'] as $specialMarker) {
            if (str_contains($name, $specialMarker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * GET /api/auto-supply-plans/{id}/clusters
     * Агрегация строк плана по кластерам доставки (гео-распределение)
     */
    public function clusters(Request $request, string $id): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);

        $lines = $this->planLinesQueryForSelectedClusters($plan)->get();

        $clusters = [];
        $unclustered = ['cluster_id' => null, 'cluster_name' => 'Без кластера', 'region' => null, 'warehouses' => [], 'total_qty' => 0, 'total_skus' => 0, 'total_supply_cost' => 0, 'skus' => []];

        foreach ($lines as $line) {
            $cid = $line->cluster_id;
            if ($cid) {
                if (! isset($clusters[$cid])) {
                    $clusters[$cid] = [
                        'cluster_id' => $cid,
                        'cluster_name' => $line->cluster_name,
                        'region' => $line->region,
                        'warehouses' => [],
                        'total_qty' => 0,
                        'total_skus' => 0,
                        'total_supply_cost' => 0,
                        'skus' => [],
                    ];
                }
                $clusters[$cid]['total_qty'] += $line->qty_rounded;
                $clusters[$cid]['total_supply_cost'] += (float) ($line->supply_cost_estimate ?? 0);
                $clusters[$cid]['skus'][$line->sku] = true;
                if ($line->warehouse_name && ! in_array($line->warehouse_name, $clusters[$cid]['warehouses'])) {
                    $clusters[$cid]['warehouses'][] = $line->warehouse_name;
                }
            } else {
                $unclustered['total_qty'] += $line->qty_rounded;
                $unclustered['total_supply_cost'] += (float) ($line->supply_cost_estimate ?? 0);
                $unclustered['skus'][$line->sku] = true;
                if ($line->warehouse_name && ! in_array($line->warehouse_name, $unclustered['warehouses'])) {
                    $unclustered['warehouses'][] = $line->warehouse_name;
                }
            }
        }

        // Finalize SKU counts
        foreach ($clusters as &$c) {
            $c['total_skus'] = count($c['skus']);
            $c['total_supply_cost'] = round($c['total_supply_cost'], 2);
            unset($c['skus']);
        }
        $unclustered['total_skus'] = count($unclustered['skus']);
        $unclustered['total_supply_cost'] = round($unclustered['total_supply_cost'], 2);
        unset($unclustered['skus']);

        // Sort by total_qty desc
        $result = array_values($clusters);
        usort($result, fn ($a, $b) => $b['total_qty'] <=> $a['total_qty']);

        if ($unclustered['total_qty'] > 0) {
            $result[] = $unclustered;
        }

        return response()->json([
            'message' => 'OK',
            'data' => [
                'plan_id' => $plan->id,
                'marketplace' => $plan->marketplace,
                'clusters' => $result,
                'total_clusters' => count($clusters),
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/data-health?integration_id=X
     * Lightweight freshness metadata for the plan details screen.
     */
    public function dataHealth(
        Request $request,
        DataFreshnessRegistry $registry
    ): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
        ]);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $request->input('integration_id'));

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];

        return response()->json([
            'message' => 'OK',
            'data' => $registry->inspect($integration),
        ]);
    }

    /**
     * POST /api/auto-supply-plans/refresh-data
     * Кнопка «Обновить данные» запускает весь pipeline фактов Ozon.
     */
    public function refreshData(
        Request $request,
        OzonPlanningFactRefreshService $refresh
    ): JsonResponse {
        $validated = $request->validate([
            'integration_id' => 'required|integer',
        ]);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];
        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Полный pipeline этой команды реализован только для Ozon.',
            ], 422);
        }

        try {
            return response()->json([
                'message' => 'Полное обновление данных Ozon поставлено в очередь.',
                'data' => $refresh->queue($integration),
            ], 202);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Не удалось запустить обновление данных.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/auto-supply-plans/sync-constraints
     * Ручной триггер авто-синка ограничений для одной интеграции (кнопка «обновить лимиты»).
     * Синхронный: для одной интеграции это 1–2 запроса к API МП, даёт мгновенный результат.
     */
    public function syncConstraints(Request $request, MarketplaceConstraintSyncService $service): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
        ]);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $request->input('integration_id'));

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];

        if (! in_array($integration->marketplace, $service->supportedMarketplaces(), true)) {
            return response()->json([
                'message' => 'Авто-синк ограничений для этого маркетплейса пока не поддержан',
                'data' => ['supported_marketplaces' => $service->supportedMarketplaces()],
            ], 422);
        }

        $snapshot = $service->syncIntegration($integration);
        $isError = $snapshot->sync_status === 'error';

        return response()->json([
            'message' => $isError ? 'Синхронизация завершилась с ошибкой' : 'Ограничения обновлены',
            'data' => [
                'sync_status' => $snapshot->sync_status,
                'synced_at' => $snapshot->synced_at?->toIso8601String(),
                'summary' => $snapshot->summary_json,
                'sync_error' => $snapshot->sync_error,
            ],
        ], $isError ? 422 : 200);
    }

    /**
     * GET /api/auto-supply-plans/warehouses?integration_id=X
     * Список складов интеграции с количеством SKU и остатками
     */
    public function warehouses(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
        ]);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $request->input('integration_id'));

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];
        $integrationId = $integration->id;

        $warehouses = \App\Models\InventoryWarehouse::where('integration_id', $integrationId)
            ->when(
                in_array($integration->marketplace, ['yandex', 'yandex_market'], true),
                fn ($q) => $q->whereIn('marketplace', ['yandex', 'yandex_market']),
                fn ($q) => $q->where('marketplace', $integration->marketplace)
            )
            ->selectRaw('warehouse_id, warehouse_name, COUNT(DISTINCT sku) as sku_count, SUM(quantity) as total_stock, SUM(sales_30_days) as total_sales_30d, SUM(sales_7_days) as total_sales_7d, AVG(storage_cost_per_day) as avg_storage_cost_daily, SUM(storage_fee_total) as total_storage_fee')
            ->groupBy('warehouse_id', 'warehouse_name')
            ->orderByDesc(\DB::raw('SUM(quantity)'))
            ->get();

        // Добавляем кластер для Ozon (безопасно — таблица может не существовать)
        $clusterMapping = [];
        if ($integration->marketplace === 'ozon') {
            try {
                $clusterMapping = OzonWarehouseCluster::getAllMapping();
            } catch (\Exception $e) {
                // Таблица ozon_warehouse_clusters может не существовать — не критично
            }
        }

        $result = $warehouses->map(function ($wh) use ($clusterMapping) {
            $clusterId = null;
            $clusterName = null;
            $region = null;

            if (! empty($clusterMapping) && $wh->warehouse_name) {
                try {
                    $normalizedName = OzonWarehouseCluster::normalizeWarehouseName($wh->warehouse_name);
                    if (isset($clusterMapping[$normalizedName])) {
                        $clusterId = $clusterMapping[$normalizedName]['cluster_id'];
                        $clusterName = $clusterMapping[$normalizedName]['cluster_name'];
                        $region = $clusterMapping[$normalizedName]['region'];
                    }
                } catch (\Exception $e) {
                }
            }

            // Рассчитываем оборачиваемость
            $avgDailySales = ($wh->total_sales_30d ?? 0) / 30;
            $turnoverDays = $avgDailySales > 0 ? round(($wh->total_stock ?? 0) / $avgDailySales, 1) : null;
            $storageCostDaily = round((float) ($wh->avg_storage_cost_daily ?? 0), 2);

            return [
                'warehouse_id' => $wh->warehouse_id,
                'warehouse_name' => $wh->warehouse_name,
                'cluster_id' => $clusterId,
                'cluster_name' => $clusterName,
                'region' => $region,
                'sku_count' => (int) $wh->sku_count,
                'total_stock' => (int) $wh->total_stock,
                'total_sales_30d' => (int) ($wh->total_sales_30d ?? 0),
                'total_sales_7d' => (int) ($wh->total_sales_7d ?? 0),
                'avg_daily_sales' => round($avgDailySales, 1),
                'turnover_days' => $turnoverDays,
                'storage_cost_daily' => $storageCostDaily,
                'total_storage_fee' => round((float) ($wh->total_storage_fee ?? 0), 2),
            ];
        });

        if ($integration->marketplace === 'ozon') {
            $result = $result
                ->groupBy(fn (array $row) => $row['cluster_id'] !== null ? 'cluster:' . $row['cluster_id'] : 'warehouse:' . $row['warehouse_id'])
                ->map(function ($rows) {
                    $first = $rows->first();
                    $isCluster = $first['cluster_id'] !== null;

                    return [
                        'warehouse_id' => $isCluster ? 'cluster:' . $first['cluster_id'] : $first['warehouse_id'],
                        'warehouse_name' => $isCluster ? $first['cluster_name'] : $first['warehouse_name'],
                        'cluster_id' => $first['cluster_id'],
                        'cluster_name' => $first['cluster_name'],
                        'region' => $first['region'],
                        'destination_type' => $isCluster ? 'cluster' : 'warehouse',
                        'warehouses' => $rows->pluck('warehouse_name')->filter()->unique()->values()->all(),
                        'sku_count' => (int) $rows->sum('sku_count'),
                        'total_stock' => (int) $rows->sum('total_stock'),
                        'total_sales_30d' => (int) $rows->sum('total_sales_30d'),
                        'total_sales_7d' => (int) $rows->sum('total_sales_7d'),
                        'avg_daily_sales' => round((float) $rows->sum('avg_daily_sales'), 1),
                        'turnover_days' => $rows->sum('avg_daily_sales') > 0
                            ? round($rows->sum('total_stock') / max($rows->sum('avg_daily_sales'), 0.1), 1)
                            : null,
                        'storage_cost_daily' => round((float) $rows->sum('storage_cost_daily'), 2),
                        'total_storage_fee' => round((float) $rows->sum('total_storage_fee'), 2),
                    ];
                })
                ->sortByDesc('total_stock')
                ->values();
        }

        return response()->json([
            'message' => 'OK',
            'data' => $result->values(),
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/locality-impact
     * Влияние плана на локальность: before/after доля, экономия, timeline, топ offender-кластеров.
     */
    public function localityImpact(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::with('integration')->findOrFail($id);

        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json([
                'message' => $access['message'] ?? 'Доступ запрещён',
            ], $access['status'] ?? 403);
        }

        if ($plan->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Locality-анализ доступен только для Ozon-планов',
                'error' => 'not_ozon_plan',
            ], 422);
        }

        $summary = $plan->result_json['locality_summary'] ?? null;

        // Fallback: если план не содержит summary (старый план или сбой при расчёте) — строим on-the-fly.
        if ($summary === null) {
            try {
                $enricher = app(\App\Domains\Locality\Integration\LocalityEnrichmentService::class);
                $summary = $enricher->buildPlanSummary($plan);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Не удалось построить Locality-анализ',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        // Timeline 28 дней для графика
        $timeline = \App\Models\LocalityMetricDaily::query()
            ->where('integration_id', $plan->integration_id)
            ->where('period_days', 28)
            ->where('snapshot_date', '>=', now()->subDays(28)->toDateString())
            ->selectRaw('snapshot_date, SUM(orders_count) AS orders, SUM(local_orders_count) AS local_orders,
                         SUM(overpayment_amount) AS overpayment')
            ->groupBy('snapshot_date')
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->snapshot_date instanceof \Carbon\Carbon
                    ? $row->snapshot_date->toDateString()
                    : (string) $row->snapshot_date,
                'orders' => (int) $row->orders,
                'local_orders' => (int) $row->local_orders,
                'overpayment_rub' => (float) $row->overpayment,
                'local_share_percent' => (int) $row->orders > 0
                    ? round(((int) $row->local_orders / (int) $row->orders) * 100, 2)
                    : null,
            ])
            ->values()
            ->all();

        $enricher = app(\App\Domains\Locality\Integration\LocalityEnrichmentService::class);
        $narrative = $enricher->narrate($summary);

        return response()->json([
            'message' => 'Success',
            'data' => [
                'plan_id' => $plan->id,
                'summary' => $summary,
                'narrative' => $narrative,
                'timeline' => $timeline,
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/cluster-split
     * Группирует split-строки плана по target_cluster_id, выдаёт агрегат per-кластер.
     */
    public function clusterSplit(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);
        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        $rows = $this->planLinesQueryForSelectedClusters($plan)
            ->whereNotNull('cluster_id')
            ->selectRaw("
                cluster_id,
                MAX(cluster_name) AS cluster_name,
                COUNT(DISTINCT sku) AS sku_count,
                SUM(qty_rounded) AS total_qty,
                SUM(expected_savings_rub) AS total_savings,
                AVG(local_share_percent) AS avg_local_share,
                MIN(locality_confidence) AS min_confidence
            ")
            ->groupBy('cluster_id')
            ->orderByDesc('total_savings')
            ->get()
            ->map(fn ($row) => [
                'cluster_id' => (string) $row->cluster_id,
                'cluster_name' => (string) $row->cluster_name,
                'sku_count' => (int) $row->sku_count,
                'total_qty' => (int) $row->total_qty,
                'expected_savings_rub' => round((float) $row->total_savings, 2),
                'avg_local_share_percent' => $row->avg_local_share !== null
                    ? round((float) $row->avg_local_share, 2)
                    : null,
                'confidence' => (string) ($row->min_confidence ?? 'low'),
            ])
            ->values()
            ->all();

        return response()->json(['message' => 'Success', 'data' => $rows]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/locality-recommendations
     * Список всех активных LocalityRecommendation для SKU из плана, с флагом in_plan.
     */
    public function localityRecommendations(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);
        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        $selectedClusterIds = $this->selectedPlanClusterIds($plan);
        $skusInPlan = $this->planLinesQueryForSelectedClusters($plan)->pluck('sku')->unique()->values();
        $linkedRecIds = collect($this->planLinesQueryForSelectedClusters($plan)
            ->whereNotNull('linked_locality_recommendation_ids')
            ->pluck('linked_locality_recommendation_ids')
            ->all())
            ->flatMap(function ($raw) {
                $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
                return is_array($decoded) ? $decoded : [];
            })
            ->unique()
            ->values()
            ->all();

        $recs = \App\Models\LocalityRecommendation::query()
            ->where('integration_id', $plan->integration_id)
            ->whereIn('sku', $skusInPlan)
            ->where('state', \App\Models\LocalityRecommendation::STATE_NEW)
            ->when($selectedClusterIds !== [], fn ($query) => $query->whereIn('target_cluster_id', array_map('strval', $selectedClusterIds)))
            ->orderByDesc('rank_score')
            ->get();

        $data = $recs->map(fn ($r) => [
            'id' => (int) $r->id,
            'sku' => (string) $r->sku,
            'target_cluster_id' => $r->target_cluster_id,
            'target_cluster_name' => (string) $r->target_cluster_name,
            'recommended_qty_units' => (int) $r->recommended_qty_units,
            'expected_savings_rub' => (float) $r->expected_savings_rub,
            'expected_local_share_uplift_pp' => (float) $r->expected_local_share_uplift_pp,
            'confidence' => (string) $r->confidence,
            'in_plan' => in_array((int) $r->id, array_map('intval', $linkedRecIds), true),
        ])->all();

        return response()->json(['message' => 'Success', 'data' => $data]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/cluster-draft-preview
     * Превью: что создастся в Ozon, если нажать «Создать драфты по всем кластерам» (без API-вызова).
     */
    public function clusterDraftPreview(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);
        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        if ($plan->marketplace !== 'ozon') {
            return response()->json(['message' => 'Превью черновиков доступно только для Ozon-планов', 'error' => 'not_ozon'], 422);
        }

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            return response()->json([
                'message' => 'План ещё не готов к созданию черновика Ozon. Дождитесь завершения расчёта и откройте предпросмотр заново.',
                'error' => 'plan_not_ready',
                'data' => [
                    'status' => $plan->status,
                    'required_status' => AutoSupplyPlan::STATUS_READY,
                    'message_ru' => 'Черновик Ozon можно создавать только из полностью рассчитанного плана.',
                ],
            ], 409);
        }

        $groups = $this->buildClusterDraftPreviewGroups($plan);
        $confirmationToken = bin2hex(random_bytes(24));
        $expiresAt = now()->addMinutes(30);
        $dropOffPointWarehouseId = $this->requestDropOffPointWarehouseId($request);
        $summary = $this->buildClusterDraftPreviewSummary($plan, $groups, $expiresAt, $dropOffPointWarehouseId);
        Cache::put($this->clusterDraftConfirmationCacheKey($plan, $confirmationToken), [
            'plan_id' => (string) $plan->id,
            'fingerprint' => $this->clusterDraftPreviewStateFingerprint($plan, $groups, $dropOffPointWarehouseId),
            'summary' => $summary,
            'draft_creation_allowed' => (bool) ($summary['draft_creation_allowed'] ?? false),
            'confirmation_phrase' => (string) $summary['confirmation_phrase'],
            'drop_off_point_warehouse_id' => $summary['drop_off_point_warehouse_id'] ?? null,
            'created_at' => now()->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
        ], $expiresAt);

        return response()->json([
            'message' => 'Success',
            'data' => [
                'clusters' => $groups,
                'total_drafts' => count($groups),
                'total_qty' => array_sum(array_column($groups, 'total_qty')),
                'total_sku' => array_sum(array_column($groups, 'items_count')),
                'summary' => $summary,
                'safe_flow_contract' => $summary['safe_flow_contract'],
                'warnings' => $summary['warnings'],
                'notes' => $summary['notes'],
                'confirmation_required' => true,
                'confirmation_token' => $confirmationToken,
                'confirmation_expires_at' => $expiresAt->toISOString(),
                'confirmation_phrase' => (string) $summary['confirmation_phrase'],
                'confirmation_note' => $summary['confirmation_note'],
            ],
        ]);
    }

    /**
     * POST /api/auto-supply-plans/{id}/create-cluster-drafts
     * Batch: создаёт по одному Ozon FBO-draft на каждый target-кластер плана.
     */
    public function createClusterDrafts(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::with('integration')->findOrFail($id);
        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        if ($plan->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon-планов', 'error' => 'not_ozon'], 422);
        }

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            return response()->json([
                'message' => 'План ещё не готов к созданию черновика Ozon. Дождитесь завершения расчёта и откройте предпросмотр заново.',
                'error' => 'plan_not_ready',
                'data' => [
                    'status' => $plan->status,
                    'required_status' => AutoSupplyPlan::STATUS_READY,
                    'message_ru' => 'Черновик Ozon можно создавать только из полностью рассчитанного плана.',
                ],
            ], 409);
        }

        $qualityGate = $this->planQualityAllowsOzonDraft($plan);
        if (! $qualityGate['allowed']) {
            return response()->json([
                'message' => 'Аудит качества плана требует ручной проверки. Создание черновиков Ozon остановлено.',
                'error' => 'plan_quality_audit_failed',
                'data' => [
                    'quality_audit' => $qualityGate,
                ],
            ], 409);
        }

        $confirmationToken = (string) $request->input('confirmation_token', '');
        if ($confirmationToken === '') {
            return response()->json([
                'message' => 'Сначала откройте предпросмотр черновиков и подтвердите создание',
                'error' => 'confirmation_required',
            ], 409);
        }

        $previewGroups = $this->buildClusterDraftPreviewGroups($plan);
        $confirmation = Cache::get($this->clusterDraftConfirmationCacheKey($plan, $confirmationToken));
        if (! is_array($confirmation)) {
            return response()->json([
                'message' => 'Подтверждение устарело или не найдено. Откройте предпросмотр заново.',
                'error' => 'confirmation_expired',
            ], 409);
        }

        $expectedConfirmationPhrase = (string) ($confirmation['confirmation_phrase'] ?? $this->clusterDraftConfirmationPhrase());
        $confirmationText = trim((string) $request->input('confirmation_text', ''));
        if ($confirmationText !== $expectedConfirmationPhrase) {
            return response()->json([
                'message' => 'Введите точную фразу подтверждения из предпросмотра перед созданием черновиков Ozon.',
                'error' => 'confirmation_phrase_required',
                'expected_confirmation_phrase' => $expectedConfirmationPhrase,
            ], 409);
        }

        $confirmedDropOffPointWarehouseId = $this->normalizeDropOffPointWarehouseId(
            $confirmation['drop_off_point_warehouse_id']
                ?? ($confirmation['summary']['drop_off_point_warehouse_id'] ?? null)
        );
        $requestDropOffPointWarehouseId = $this->requestDropOffPointWarehouseId($request);
        if (
            $requestDropOffPointWarehouseId !== null
            && $confirmedDropOffPointWarehouseId !== null
            && $requestDropOffPointWarehouseId !== $confirmedDropOffPointWarehouseId
        ) {
            return response()->json([
                'message' => 'Точка отгрузки отличается от предпросмотра. Откройте предпросмотр заново с нужной точкой.',
                'error' => 'drop_off_point_changed',
            ], 409);
        }

        $currentSummary = $this->buildClusterDraftPreviewSummary(
            $plan,
            $previewGroups,
            now()->addMinutes(30),
            $confirmedDropOffPointWarehouseId
        );
        if (! (bool) ($confirmation['draft_creation_allowed'] ?? ($confirmation['summary']['draft_creation_allowed'] ?? false))
            || ! (bool) ($currentSummary['draft_creation_allowed'] ?? false)) {
            return response()->json([
                'message' => 'Предпросмотр не разрешает создание черновика. Откройте предпросмотр и устраните предупреждения перед созданием.',
                'error' => 'preview_not_allowed',
                'data' => [
                    'summary' => $currentSummary,
                ],
            ], 409);
        }

        if (($confirmation['fingerprint'] ?? null) !== $this->clusterDraftPreviewStateFingerprint($plan, $previewGroups, $confirmedDropOffPointWarehouseId)) {
            return response()->json([
                'message' => 'Состав плана изменился после предпросмотра. Откройте предпросмотр заново перед созданием черновика.',
                'error' => 'preview_changed',
            ], 409);
        }

        // Legacy endpoint оставлен для совместимости старого интерфейса, но
        // исполнение теперь проходит только через канонические Supply,
        // журнал AutoSupplyPlanExecution и очередь с rate limit.
        $canonicalCheck = app(OzonPlanMaterializationService::class)->approvalCheck($plan);
        if (! $canonicalCheck['allowed']
            || $plan->business_status !== AutoSupplyPlan::BUSINESS_STATUS_APPROVED
            || ! $plan->approval_fingerprint) {
            return response()->json([
                'message' => 'План прошёл legacy-preview, но ещё не готов к безопасному исполнению. Выполните validate и approve.',
                'error' => 'canonical_prerequisites_required',
                'data' => [
                    'approval_check' => $canonicalCheck,
                    'business_status' => $plan->business_status,
                    'required_business_status' => AutoSupplyPlan::BUSINESS_STATUS_APPROVED,
                    'next_actions' => [
                        'POST /api/auto-supply-plans/' . $plan->id . '/validate',
                        'POST /api/auto-supply-plans/' . $plan->id . '/approve',
                        'POST /api/auto-supply-plans/' . $plan->id . '/create-cluster-drafts',
                    ],
                ],
            ], 409);
        }

        $executionResult = app(AutoSupplyPlanExecutionService::class)->start(
            $plan,
            'legacy-preview:' . $confirmationToken,
            AutoSupplyPlanExecutionService::CONFIRMATION_PHRASE,
            $this->requestUserId($request),
            ['auto_book_timeslot' => false]
        );
        Cache::forget($this->clusterDraftConfirmationCacheKey($plan, $confirmationToken));

        return response()->json([
            'message' => $executionResult['created']
                ? 'Создание черновиков Ozon поставлено в очередь'
                : 'Эта подтверждённая операция уже была запущена',
            'data' => [
                'execution' => $executionResult['execution'],
                'idempotent' => ! $executionResult['created'],
                'safe_flow' => 'canonical_supply_execution',
                'confirmation_checked' => true,
                'preview_fingerprint_verified' => true,
                'external_api_called_in_request' => false,
            ],
        ], $executionResult['created'] ? 202 : 200);
    }

    /**
     * POST /api/auto-supply-plans/from-locality-recommendations
     * Создаёт автоплан с включённым locality split по активным рекомендациям.
     */
    public function createFromLocalityRecommendations(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'recommendation_ids' => 'nullable|array',
            'recommendation_ids.*' => 'integer',
            'mode' => 'nullable|string|in:anti_oos,balanced,cash_safe',
            'horizon_days' => 'nullable|integer|in:7,14,28,30,56,60,90',
            'min_cover_days' => 'nullable|integer|min:1|max:90',
            'target_cover_days' => 'nullable|integer|min:1|max:120',
            'max_cover_days' => 'nullable|integer|min:1|max:180',
            'safety_stock_days' => 'nullable|integer|min:0|max:30',
            'turnover_limit_days' => 'nullable|integer|min:1|max:365',
            'budget_limit' => 'nullable|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0|max:30',
            'cluster_ids' => 'nullable|array',
            'cluster_ids.*' => 'integer',
        ])->validate();

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];

        $recommendations = LocalityRecommendation::query()
            ->where('integration_id', $integration->id)
            ->where('state', LocalityRecommendation::STATE_NEW)
            ->when(! empty($validated['recommendation_ids'] ?? []), function ($query) use ($validated) {
                $query->whereIn('id', array_map('intval', $validated['recommendation_ids']));
            })
            ->whereNotNull('target_cluster_id')
            ->get();

        $clusterIds = collect($validated['cluster_ids'] ?? [])
            ->merge($recommendations->pluck('target_cluster_id'))
            ->map(fn ($clusterId) => (int) $clusterId)
            ->filter(fn (int $clusterId) => $clusterId > 0)
            ->unique()
            ->values()
            ->all();

        if ($clusterIds === []) {
            return response()->json([
                'message' => 'Нет активных locality-рекомендаций с целевыми кластерами',
                'error' => 'no_locality_recommendations',
            ], 422);
        }

        $request->merge([
            'cluster_ids' => $clusterIds,
            'split_by_cluster' => true,
            'include_locality_recommendations' => true,
            'locality_distribution_strategy' => 'recommendations',
            'locality_recommendation_ids' => $recommendations->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ]);

        return $this->createPlanFromRequest($request, $integration);
    }

    /**
     * POST /api/auto-supply-plans/preview-split-by-cluster
     * Лёгкий preview для UI перед созданием плана из locality recommendations.
     */
    public function previewSplitByCluster(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'recommendation_ids' => 'nullable|array',
            'recommendation_ids.*' => 'integer',
        ])->validate();

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];

        $recommendations = LocalityRecommendation::query()
            ->where('integration_id', $integration->id)
            ->where('state', LocalityRecommendation::STATE_NEW)
            ->when(! empty($validated['recommendation_ids'] ?? []), function ($query) use ($validated) {
                $query->whereIn('id', array_map('intval', $validated['recommendation_ids']));
            })
            ->whereNotNull('target_cluster_id')
            ->orderByDesc('rank_score')
            ->get();

        $clusters = $recommendations
            ->groupBy('target_cluster_id')
            ->map(function ($items, $clusterId) {
                $first = $items->first();

                return [
                    'cluster_id' => (string) $clusterId,
                    'cluster_name' => (string) $first->target_cluster_name,
                    'recommendations_count' => $items->count(),
                    'total_qty' => (int) $items->sum('recommended_qty_units'),
                    'expected_savings_rub' => round((float) $items->sum('expected_savings_rub'), 2),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'message' => 'Success',
            'data' => [
                'clusters' => $clusters,
                'total_clusters' => count($clusters),
                'total_recommendations' => $recommendations->count(),
                'total_qty' => array_sum(array_column($clusters, 'total_qty')),
            ],
        ]);
    }

    /**
     * Строки, из которых можно сформировать Ozon FBO draft по кластерам.
     *
     * Поддерживает оба режима:
     * - старый locality split: is_cluster_split=true;
     * - новый Ozon auto-planning: destination_type=cluster.
     */
    private function clusterDraftLinesQuery(AutoSupplyPlan $plan)
    {
        return $plan->lines()
            ->whereNotNull('cluster_id')
            ->where('qty_rounded', '>', 0)
            ->where(function ($query) {
                $query
                    ->where('is_cluster_split', true)
                    ->orWhere('destination_type', 'cluster');
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildClusterDraftPreviewGroups(AutoSupplyPlan $plan): array
    {
        return $this->clusterDraftLinesQuery($plan)
            ->when($this->selectedPlanClusterIds($plan) !== [], function ($query) use ($plan) {
                $query->whereIn('cluster_id', $this->selectedPlanClusterIds($plan));
            })
            ->orderBy('cluster_id')
            ->orderBy('sku')
            ->get()
            ->groupBy('cluster_id')
            ->map(function ($linesInCluster) {
                $first = $linesInCluster->first();

                return [
                    'cluster_id' => (string) $first->cluster_id,
                    'cluster_name' => (string) $first->cluster_name,
                    'items_count' => $linesInCluster->count(),
                    'sku_count' => $linesInCluster->pluck('sku')->unique()->count(),
                    'items' => $linesInCluster->map(fn ($line) => [
                        'sku' => (string) $line->sku,
                        'offer_id' => $line->offer_id,
                        'product_name' => $line->product_name,
                        'quantity' => (int) $line->qty_rounded,
                    ])->values()->all(),
                    'total_qty' => (int) $linesInCluster->sum('qty_rounded'),
                    'expected_savings_rub' => round((float) $linesInCluster->sum('expected_savings_rub'), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @return array<string, mixed>
     */
    private function buildClusterDraftPreviewSummary(
        AutoSupplyPlan $plan,
        array $groups,
        \Carbon\CarbonInterface $expiresAt,
        ?int $dropOffPointWarehouseIdOverride = null
    ): array
    {
        $selectedClusterIds = $this->selectedPlanClusterIds($plan);
        $previewClusterIds = array_values(array_filter(
            array_map(static fn (array $group): int => (int) ($group['cluster_id'] ?? 0), $groups),
            static fn (int $clusterId): bool => $clusterId > 0
        ));
        $totalQty = array_sum(array_map(static fn (array $group): int => (int) ($group['total_qty'] ?? 0), $groups));
        $totalSku = array_sum(array_map(static fn (array $group): int => (int) ($group['items_count'] ?? 0), $groups));
        $supplyMethod = $this->draftSupplyMethod($plan);
        $dropOffPointWarehouseId = $supplyMethod === 'crossdock'
            ? ($dropOffPointWarehouseIdOverride ?? $this->draftDropOffPointWarehouseId($plan))
            : null;
        $previewFingerprint = $this->clusterDraftPreviewStateFingerprint($plan, $groups, $dropOffPointWarehouseId);
        $warnings = [];
        $qualityGate = $this->planQualityAllowsOzonDraft($plan);
        $missingSelectedClusterIds = [];

        if ($groups === []) {
            $warnings[] = 'В плане нет строк с кластерами Ozon, поэтому черновик создать нельзя.';
        }

        if ($selectedClusterIds !== []) {
            $missingSelectedClusterIds = array_values(array_diff($selectedClusterIds, $previewClusterIds));
            if ($missingSelectedClusterIds !== []) {
                $warnings[] = 'Часть выбранных кластеров не попала в предпросмотр, потому что в плане нет строк к поставке для этих кластеров: ' . implode(', ', $missingSelectedClusterIds) . '. Создание черновика остановлено, чтобы не создать частичную поставку.';
            }
        } else {
            $warnings[] = 'В плане не зафиксирован список выбранных кластеров: предпросмотр показывает все кластеры, которые есть в строках плана.';
        }

        if (! $qualityGate['allowed']) {
            $warnings[] = $qualityGate['summary']
                ?: 'Аудит качества плана требует ручной проверки перед созданием черновиков Ozon.';
        }
        if ($supplyMethod === 'crossdock' && $dropOffPointWarehouseId === null) {
            $warnings[] = 'Для кросс-докинга Ozon укажите ID точки отгрузки: без неё сервер не будет создавать черновик.';
        }

        $notes = [
            'Это безопасный предпросмотр: на этом шаге запрос в Ozon на создание черновика не отправляется.',
            'После подтверждения сервер повторно сверит состав плана. Если количество или SKU изменились, создание будет остановлено.',
            'Автобронирование слотов не выполняется: сервис только создаёт черновик после ручного подтверждения.',
        ];

        $draftCreationAllowed = $groups !== []
            && $qualityGate['allowed']
            && $missingSelectedClusterIds === []
            && ($supplyMethod !== 'crossdock' || $dropOffPointWarehouseId !== null);

        $summary = [
            'human_status' => $groups === []
                ? 'Черновик Ozon создать нельзя: нет строк с кластерами'
                : 'Предпросмотр готов: проверьте кластеры, SKU и количество перед созданием черновиков Ozon',
            'safe_flow' => 'preview_only',
            'ozon_api_called' => false,
            'autobooking' => false,
            'draft_creation_blocked' => ! $draftCreationAllowed,
            'draft_creation_policy' => 'Только после ручного подтверждения предпросмотра',
            'destination_label' => 'кластеры Ozon',
            'supply_method' => $supplyMethod,
            'supply_method_label' => $supplyMethod === 'crossdock' ? 'Кросс-докинг Ozon' : 'Прямая поставка Ozon',
            'drop_off_point_warehouse_id' => $dropOffPointWarehouseId,
            'selected_cluster_ids' => $selectedClusterIds,
            'selected_clusters_count' => count($selectedClusterIds),
            'missing_selected_cluster_ids' => $missingSelectedClusterIds,
            'selected_clusters_complete' => $missingSelectedClusterIds === [],
            'preview_cluster_ids' => $previewClusterIds,
            'preview_clusters_count' => count($groups),
            'total_drafts' => count($groups),
            'total_qty' => $totalQty,
            'total_sku' => $totalSku,
            'preview_fingerprint_short' => substr($previewFingerprint, 0, 12),
            'safety_checks' => [
                [
                    'key' => 'preview_only',
                    'label' => 'Предпросмотр не создаёт черновик в Ozon',
                    'passed' => true,
                ],
                [
                    'key' => 'selected_clusters_locked',
                    'label' => $selectedClusterIds === []
                        ? 'План создан без фиксированного списка кластеров: используются все кластеры из строк плана'
                        : ($missingSelectedClusterIds === []
                            ? 'Выбранные кластеры зафиксированы и полностью попали в предпросмотр'
                            : 'Не все выбранные кластеры попали в предпросмотр: создание частичного черновика запрещено'),
                    'passed' => $missingSelectedClusterIds === [],
                ],
                [
                    'key' => 'fingerprint_will_be_verified',
                    'label' => 'Перед созданием сервер повторно сверит SKU, количество и кластеры по контрольной подписи предпросмотра',
                    'passed' => true,
                ],
                [
                    'key' => 'no_autobooking',
                    'label' => 'Автобронирование слотов не выполняется',
                    'passed' => true,
                ],
                [
                    'key' => 'plan_quality_audit',
                    'label' => $qualityGate['allowed']
                        ? 'Аудит качества плана разрешает создание черновика'
                        : 'Аудит качества плана требует ручной проверки: черновик создавать нельзя',
                    'passed' => $qualityGate['allowed'],
                ],
                [
                    'key' => 'crossdock_drop_off_configured',
                    'label' => $supplyMethod === 'crossdock'
                        ? ($dropOffPointWarehouseId !== null
                            ? 'Кросс-докинг включён: точка отгрузки указана'
                            : 'Кросс-докинг включён, но точка отгрузки не указана')
                        : 'Кросс-докинг не включён: будет создан прямой черновик Ozon',
                    'passed' => $supplyMethod !== 'crossdock' || $dropOffPointWarehouseId !== null,
                ],
            ],
            'draft_creation_allowed' => $draftCreationAllowed,
            'quality_audit_status' => $qualityGate['status'],
            'quality_audit_summary' => $qualityGate['summary'],
            'quality_audit_actions' => $qualityGate['actions'],
            'quality_audit_examples' => $qualityGate['examples'],
            'quality_audit_manual_review_reason' => $qualityGate['manual_review_reason_ru'],
            'blocking_reasons_ru' => $warnings,
            'required_user_action_ru' => $groups === []
                ? 'Пересчитайте план или проверьте выбранные кластеры: сейчас нечего передавать в черновик Ozon.'
                : 'Проверьте кластеры, SKU, количество и введите точную фразу подтверждения перед созданием черновиков Ozon.',
            'confirmation_expires_at' => $expiresAt->toISOString(),
            'confirmation_phrase' => $this->clusterDraftConfirmationPhrase($supplyMethod),
            'confirmation_note' => 'Создание черновиков Ozon доступно только после просмотра предпросмотра. Перед созданием сервер повторно сверит SKU, количество и кластеры плана.',
            'acceptance_audit' => $this->buildClusterDraftAcceptanceAudit(
                allowed: $draftCreationAllowed,
                stage: 'preview',
                selectedClusterIds: $selectedClusterIds,
                previewClusterIds: $previewClusterIds,
                supplyMethod: $supplyMethod,
                dropOffPointWarehouseId: $dropOffPointWarehouseId,
                qualityAllowed: (bool) $qualityGate['allowed'],
                warnings: $warnings,
            ),
            'warnings' => $warnings,
            'notes' => $notes,
        ];

        $summary['safe_flow_contract'] = $this->buildClusterDraftSafeFlowContract($summary);

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function buildClusterDraftSafeFlowContract(array $summary): array
    {
        $supplyMethod = (string) ($summary['supply_method'] ?? 'direct');
        $draftCreationAllowed = (bool) ($summary['draft_creation_allowed'] ?? false);
        $blockingReasons = array_values((array) ($summary['blocking_reasons_ru'] ?? []));
        $confirmationPhrase = (string) ($summary['confirmation_phrase'] ?? $this->clusterDraftConfirmationPhrase($supplyMethod));
        $isCrossdock = $supplyMethod === 'crossdock';
        $primaryAction = $isCrossdock ? 'Создать кросс-докинг Ozon' : 'Создать черновики Ozon';

        return [
            'version' => 'ozon-draft-safe-flow-ui-1',
            'title_ru' => $isCrossdock
                ? 'Безопасное создание кросс-докинга Ozon'
                : 'Безопасное создание черновиков Ozon',
            'status' => $draftCreationAllowed ? 'ready_for_confirmation' : 'blocked',
            'status_ru' => $draftCreationAllowed
                ? 'Можно создать после ручного подтверждения'
                : 'Создание заблокировано до устранения причин',
            'primary_action_ru' => $primaryAction,
            'primary_action_enabled' => $draftCreationAllowed,
            'disabled_reason_ru' => $draftCreationAllowed
                ? null
                : ($blockingReasons[0] ?? 'Предпросмотр не разрешает создание черновика Ozon. Проверьте предупреждения.'),
            'manual_policy_ru' => 'Автобронирование не выполняется: сервис создаёт только черновик после предпросмотра и ручного подтверждения.',
            'steps_ru' => [
                'Проверить предпросмотр: кластеры, SKU и количество.',
                'Проверить защиту данных и убедиться, что план не заблокирован.',
                $isCrossdock
                    ? 'Проверить точку отгрузки для кросс-докинга Ozon.'
                    : 'Проверить, что будет создан прямой черновик Ozon.',
                'Ввести точную фразу подтверждения.',
                $primaryAction . '.',
            ],
            'frontend_flags' => [
                'show_confirmation_input' => true,
                'show_blocking_reasons' => ! $draftCreationAllowed,
                'show_no_autobooking_notice' => true,
                'show_drop_off_selector' => $isCrossdock && ($summary['drop_off_point_warehouse_id'] ?? null) === null,
                'can_submit_create' => $draftCreationAllowed,
            ],
            'payload_requirements' => [
                [
                    'field' => 'confirmation_token',
                    'required' => true,
                    'source_ru' => 'Возьмите из ответа предпросмотра: data.confirmation_token.',
                ],
                [
                    'field' => 'confirmation_text',
                    'required' => true,
                    'source_ru' => 'Пользователь должен ввести точную фразу подтверждения.',
                    'expected_value' => $confirmationPhrase,
                ],
                [
                    'field' => 'drop_off_point_warehouse_id',
                    'required' => $isCrossdock,
                    'source_ru' => $isCrossdock
                        ? 'Для кросс-докинга точка отгрузки должна быть сохранена в параметрах плана до открытия предпросмотра.'
                        : 'Для прямого черновика не требуется.',
                    'current_value' => $summary['drop_off_point_warehouse_id'] ?? null,
                ],
            ],
            'confirmation_phrase' => $confirmationPhrase,
            'confirmation_phrase_ru' => 'Введите точно: ' . $confirmationPhrase,
            'blocking_checks_ru' => collect((array) ($summary['acceptance_audit'] ?? []))
                ->filter(fn (array $check): bool => (bool) ($check['passed'] ?? false) === false)
                ->map(fn (array $check): string => (string) ($check['details_ru'] ?? $check['title_ru'] ?? 'Проверка не пройдена'))
                ->values()
                ->all(),
            'next_action_ru' => $draftCreationAllowed
                ? 'Покажите пользователю фразу подтверждения и активируйте кнопку создания после ввода точного текста.'
                : 'Покажите причины блокировки и не отправляйте запрос создания, пока preview не станет разрешённым.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildClusterDraftAcceptanceAudit(
        bool $allowed,
        string $stage,
        array $selectedClusterIds,
        array $previewClusterIds,
        string $supplyMethod,
        ?int $dropOffPointWarehouseId,
        bool $qualityAllowed,
        array $warnings = []
    ): array {
        $isCreateStage = $stage === 'create';
        $hasLines = $previewClusterIds !== [];
        $crossdockConfigured = $supplyMethod !== 'crossdock' || $dropOffPointWarehouseId !== null;
        $missingSelectedClusterIds = $selectedClusterIds === []
            ? []
            : array_values(array_diff($selectedClusterIds, $previewClusterIds));
        $unexpectedClusterIds = $selectedClusterIds === []
            ? []
            : array_values(array_diff($previewClusterIds, $selectedClusterIds));
        $selectedClustersPassed = $missingSelectedClusterIds === [] && $unexpectedClusterIds === [];

        return [
            [
                'key' => 'preview_loaded',
                'status' => 'passed',
                'passed' => true,
                'title_ru' => 'Предпросмотр построен',
                'details_ru' => $hasLines
                    ? 'Система собрала строки плана по выбранным кластерам.'
                    : 'В предпросмотре нет строк для создания черновика.',
            ],
            [
                'key' => 'ozon_api_call',
                'status' => $isCreateStage ? 'passed' : 'pending',
                'passed' => $isCreateStage,
                'title_ru' => $isCreateStage
                    ? 'Вызов Ozon разрешён после подтверждения'
                    : 'Запрос в Ozon ещё не отправлялся',
                'details_ru' => $isCreateStage
                    ? 'Сервер дошёл до создания черновика только после подтверждённого preview.'
                    : 'На этапе предпросмотра сервер ничего не создаёт в Ozon.',
            ],
            [
                'key' => 'manual_confirmation',
                'status' => $isCreateStage ? 'passed' : 'required',
                'passed' => $isCreateStage,
                'title_ru' => $isCreateStage
                    ? 'Ручное подтверждение принято'
                    : 'Нужно ручное подтверждение',
                'details_ru' => $isCreateStage
                    ? 'Пользователь ввёл точную фразу подтверждения.'
                    : 'Без точной фразы подтверждения черновики не будут созданы.',
            ],
            [
                'key' => 'fingerprint',
                'status' => $isCreateStage ? 'passed' : 'pending',
                'passed' => $isCreateStage,
                'title_ru' => $isCreateStage
                    ? 'Контрольная подпись preview совпала'
                    : 'Контрольная подпись будет проверена перед созданием',
                'details_ru' => $isCreateStage
                    ? 'SKU, количество, кластеры и способ поставки не изменились после предпросмотра.'
                    : 'Если SKU, количество, кластеры или способ поставки изменятся, создание остановится.',
            ],
            [
                'key' => 'selected_clusters',
                'status' => $selectedClustersPassed ? 'passed' : 'failed',
                'passed' => $selectedClustersPassed,
                'title_ru' => 'Кластеры сверены',
                'details_ru' => match (true) {
                    $selectedClusterIds === [] => 'План создан без фиксированного списка кластеров: используются все кластеры из строк плана.',
                    $missingSelectedClusterIds !== [] => 'Не все выбранные кластеры попали в предпросмотр: отсутствуют ' . implode(', ', $missingSelectedClusterIds) . '.',
                    $unexpectedClusterIds !== [] => 'В предпросмотр попали кластеры вне выбора пользователя: ' . implode(', ', $unexpectedClusterIds) . '.',
                    default => 'В черновик допускаются только выбранные кластеры: ' . implode(', ', $selectedClusterIds) . '.',
                },
                'selected_cluster_ids' => $selectedClusterIds,
                'preview_cluster_ids' => $previewClusterIds,
                'missing_selected_cluster_ids' => $missingSelectedClusterIds,
                'unexpected_cluster_ids' => $unexpectedClusterIds,
            ],
            [
                'key' => 'quality_gate',
                'status' => $qualityAllowed ? 'passed' : 'failed',
                'passed' => $qualityAllowed,
                'title_ru' => $qualityAllowed
                    ? 'Защита данных разрешает создание'
                    : 'Защита данных остановила создание',
                'details_ru' => $qualityAllowed
                    ? 'Качество плана достаточно для draft-flow.'
                    : 'План требует ручной проверки перед созданием черновика Ozon.',
            ],
            [
                'key' => 'crossdock_drop_off',
                'status' => $crossdockConfigured ? 'passed' : 'failed',
                'passed' => $crossdockConfigured,
                'title_ru' => $supplyMethod === 'crossdock'
                    ? 'Точка кросс-докинга проверена'
                    : 'Кросс-докинг не включён',
                'details_ru' => $supplyMethod === 'crossdock'
                    ? ($dropOffPointWarehouseId !== null
                        ? 'Будет использована точка отгрузки Ozon #' . $dropOffPointWarehouseId . '.'
                        : 'Для кросс-докинга нужна точка отгрузки Ozon.')
                    : 'Будет создан прямой черновик Ozon.',
            ],
            [
                'key' => 'final_permission',
                'status' => $allowed ? ($isCreateStage ? 'passed' : 'ready') : 'blocked',
                'passed' => $allowed,
                'title_ru' => $allowed
                    ? ($isCreateStage ? 'Создание прошло через safe-flow' : 'Черновик можно создать после подтверждения')
                    : 'Создание черновика заблокировано',
                'details_ru' => $allowed
                    ? 'Ограничения safe-flow выполнены.'
                    : (reset($warnings) ?: 'Устраните предупреждения в предпросмотре.'),
            ],
        ];
    }

    /**
     * @return array{
     *   allowed: bool,
     *   status: string|null,
     *   summary: string|null,
     *   actions: array<int, mixed>,
     *   examples: array<int, mixed>,
     *   manual_review_reason_ru: string|null
     * }
     */
    private function planQualityAllowsOzonDraft(AutoSupplyPlan $plan): array
    {
        $audit = $plan->plan_quality_audit;
        if (! is_array($audit) || $audit === []) {
            return [
                'allowed' => true,
                'status' => null,
                'summary' => null,
                'actions' => [],
                'examples' => [],
                'manual_review_reason_ru' => null,
            ];
        }

        $status = isset($audit['status']) ? (string) $audit['status'] : null;
        $acceptanceGates = is_array($audit['acceptance_gates'] ?? null) ? $audit['acceptance_gates'] : [];
        $allowed = array_key_exists('can_create_ozon_draft', $acceptanceGates)
            ? (bool) $acceptanceGates['can_create_ozon_draft']
            : $status !== 'bad';

        return [
            'allowed' => $allowed,
            'status' => $status,
            'summary' => isset($audit['summary_ru'])
                ? (string) $audit['summary_ru']
                : (isset($audit['status_label']) ? (string) $audit['status_label'] : null),
            'actions' => array_slice(array_values((array) ($audit['actions'] ?? [])), 0, 4),
            'examples' => array_slice(array_values((array) ($audit['examples'] ?? [])), 0, 4),
            'manual_review_reason_ru' => isset($acceptanceGates['manual_review_reason_ru'])
                ? (string) $acceptanceGates['manual_review_reason_ru']
                : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $groups
     */
    private function clusterDraftPreviewFingerprint(array $groups): string
    {
        $normalized = collect($groups)
            ->map(function (array $group): array {
                $items = collect($group['items'] ?? [])
                    ->map(fn (array $item): array => [
                        'sku' => (string) ($item['sku'] ?? ''),
                        'offer_id' => (string) ($item['offer_id'] ?? ''),
                        'quantity' => (int) ($item['quantity'] ?? 0),
                    ])
                    ->sortBy([['sku', 'asc'], ['offer_id', 'asc']])
                    ->values()
                    ->all();

                return [
                    'cluster_id' => (string) ($group['cluster_id'] ?? ''),
                    'items' => $items,
                    'total_qty' => (int) ($group['total_qty'] ?? 0),
                ];
            })
            ->sortBy('cluster_id')
            ->values()
            ->all();

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Fingerprint безопасного preview: учитывает не только строки, но и режим
     * создания draft. Нельзя подтвердить direct-preview, а затем создать
     * cross-docking draft с другой точкой отгрузки.
     *
     * @param list<array<string, mixed>> $groups
     */
    private function clusterDraftPreviewStateFingerprint(AutoSupplyPlan $plan, array $groups, ?int $dropOffPointWarehouseIdOverride = null): string
    {
        $supplyMethod = $this->draftSupplyMethod($plan);
        $dropOffPointWarehouseId = $supplyMethod === 'crossdock'
            ? ($dropOffPointWarehouseIdOverride ?? $this->draftDropOffPointWarehouseId($plan))
            : null;

        return hash('sha256', json_encode([
            'lines_fingerprint' => $this->clusterDraftPreviewFingerprint($groups),
            'selected_cluster_ids' => $this->selectedPlanClusterIds($plan),
            'supply_method' => $supplyMethod,
            'drop_off_point_warehouse_id' => $dropOffPointWarehouseId,
            'quality_audit_fingerprint' => $this->clusterDraftQualityAuditFingerprint($plan),
        ], JSON_UNESCAPED_UNICODE));
    }

    private function clusterDraftQualityAuditFingerprint(AutoSupplyPlan $plan): string
    {
        return hash('sha256', json_encode(
            $plan->plan_quality_audit,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function draftSupplyMethod(AutoSupplyPlan $plan): string
    {
        $params = is_array($plan->params ?? null) ? $plan->params : [];
        $method = (string) ($params['draft_supply_method'] ?? $params['supply_method'] ?? 'direct');

        return $this->normalizeDraftSupplyMethod($method);
    }

    private function normalizeDraftSupplyMethod(string $method): string
    {
        $method = strtolower(trim($method));

        return in_array($method, ['crossdock', 'cross_dock', 'cross-dock'], true) ? 'crossdock' : 'direct';
    }

    private function draftDropOffPointWarehouseId(AutoSupplyPlan $plan): ?int
    {
        $params = is_array($plan->params ?? null) ? $plan->params : [];
        $value = $params['drop_off_point_warehouse_id']
            ?? $params['crossdock_drop_off_point_warehouse_id']
            ?? $params['dropoff_warehouse_id']
            ?? null;

        return $this->normalizeDropOffPointWarehouseId($value);
    }

    private function requestDropOffPointWarehouseId(Request $request): ?int
    {
        return $this->normalizeDropOffPointWarehouseId(
            $request->input('drop_off_point_warehouse_id', $request->input('crossdock_drop_off_point_warehouse_id'))
        );
    }

    private function normalizeDropOffPointWarehouseId(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function clusterDraftConfirmationPhrase(?string $supplyMethod = null): string
    {
        return $supplyMethod === 'crossdock'
            ? 'СОЗДАТЬ КРОСС-ДОКИНГ OZON'
            : 'СОЗДАТЬ ЧЕРНОВИКИ OZON';
    }

    private function clusterDraftConfirmationCacheKey(AutoSupplyPlan $plan, string $token): string
    {
        return "auto_supply_plan:{$plan->id}:cluster_draft_confirmation:{$token}";
    }

    /**
     * @return list<int>
     */
    private function selectedPlanClusterIds(AutoSupplyPlan $plan): array
    {
        return array_values(array_filter(
            array_map('intval', (array) ($plan->params['cluster_ids'] ?? [])),
            fn (int $clusterId) => $clusterId > 0
        ));
    }

    /**
     * Извлекает ozon_sku (числовой ID) из `products.ozon_data.sku`.
     */
    private function resolveOzonSku(?Product $product): int
    {
        if ($product === null) {
            return 0;
        }
        $ozonData = is_array($product->ozon_data ?? null) ? $product->ozon_data : [];
        $sku = $ozonData['sku'] ?? ($ozonData['product_id'] ?? null);
        return (int) ($sku ?? 0);
    }

    /**
     * DELETE /api/auto-supply-plans/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);

        $deleted = \DB::transaction(function () use ($plan): bool {
            $lockedPlan = AutoSupplyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($lockedPlan->supplies()->exists()) {
                return false;
            }

            $lockedPlan->lines()->delete();
            $lockedPlan->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'message' => 'Нельзя удалить план, по которому уже созданы поставки.',
                'error' => 'plan_already_materialized',
            ], 409);
        }

        return response()->json(['message' => 'План удалён']);
    }

    /**
     * PATCH /api/auto-supply-plans/{planId}/lines/{lineId}
     * Ручная корректировка количества в строке плана
     */
    public function updateLine(Request $request, string $planId, int $lineId): JsonResponse
    {
        $plan = $this->authorizedPlan($request, $planId);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }

        $validated = $request->validate([
            'qty_rounded' => 'sometimes|integer|min:0',
            'is_excluded' => 'sometimes|boolean',
            'cluster_id' => 'sometimes|integer|min:1',
            'cluster_name' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:2000',
            'reason' => 'required|string|max:1000',
        ]);
        if (! array_key_exists('qty_rounded', $validated)
            && ! array_key_exists('is_excluded', $validated)
            && ! array_key_exists('cluster_id', $validated)
            && ! array_key_exists('comment', $validated)) {
            abort(422, 'Передайте количество, признак исключения, кластер назначения или комментарий');
        }

        $newQty = array_key_exists('qty_rounded', $validated)
            ? (int) $validated['qty_rounded']
            : null;
        $isExcluded = array_key_exists('is_excluded', $validated)
            ? (bool) $validated['is_excluded']
            : null;
        $targetClusterId = array_key_exists('cluster_id', $validated)
            ? (string) $validated['cluster_id']
            : null;
        $userId = $this->requestUserId($request);
        $result = \DB::transaction(function () use (
            $plan,
            $lineId,
            $newQty,
            $isExcluded,
            $targetClusterId,
            $validated,
            $userId
        ): ?array {
            $lockedPlan = AutoSupplyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($lockedPlan->supplies()->exists()) {
                return null;
            }

            $line = $this->planLinesQueryForSelectedClusters($lockedPlan)->findOrFail($lineId);

            // Показанная строка — агрегат SUM(qty_rounded) по группе (sku[, cluster_id]).
            $siblings = $this->aggregatedSiblingLines($lockedPlan, $line);
            $oldQty = (int) $siblings->sum('qty_rounded');
            $oldExcluded = $siblings->every(fn (AutoSupplyPlanLine $sibling): bool => $sibling->is_excluded);
            $oldClusterId = $line->cluster_id !== null ? (string) $line->cluster_id : null;
            $targetClusterName = null;

            if ($targetClusterId !== null && $targetClusterId !== $oldClusterId) {
                if ($lockedPlan->marketplace !== 'ozon') {
                    abort(422, 'Изменение кластера назначения поддерживается только для Ozon-плана');
                }

                $targetCluster = OzonWarehouseCluster::query()
                    ->where('cluster_id', $targetClusterId)
                    ->first();
                $clusterAlreadyUsedByPlan = $lockedPlan->lines()
                    ->where('cluster_id', $targetClusterId)
                    ->exists();
                if ($targetCluster === null && ! $clusterAlreadyUsedByPlan) {
                    abort(422, 'Кластер назначения отсутствует в актуальной карте складов Ozon');
                }

                $duplicateExists = $lockedPlan->lines()
                    ->where('sku', $line->sku)
                    ->where('cluster_id', $targetClusterId)
                    ->whereNotIn('id', $siblings->pluck('id'))
                    ->exists();
                if ($duplicateExists) {
                    abort(422, 'Для этого товара уже есть строка в выбранном кластере. Измените количество существующей строки.');
                }

                $targetClusterName = trim((string) ($validated['cluster_name'] ?? ''));
                if ($targetClusterName === '') {
                    $targetClusterName = $targetCluster?->cluster_name;
                }
                $targetClusterName = $targetClusterName ?: 'Кластер ' . $targetClusterId;

                foreach ($siblings as $sibling) {
                    $sibling->update([
                        'cluster_id' => $targetClusterId,
                        'cluster_name' => $targetClusterName,
                        'destination_type' => 'cluster',
                        'destination_id' => 'cluster:' . $targetClusterId,
                        'destination' => $targetClusterName,
                        'warehouse_id' => 'cluster:' . $targetClusterId,
                        'warehouse_name' => $targetClusterName,
                    ]);
                }

                $params = is_array($lockedPlan->params) ? $lockedPlan->params : [];
                $selectedClusters = collect((array) ($params['cluster_ids'] ?? []))
                    ->map(fn ($clusterId): int => (int) $clusterId)
                    ->push((int) $targetClusterId);
                if (
                    $oldClusterId !== null
                    && ! $lockedPlan->lines()->where('cluster_id', $oldClusterId)->exists()
                ) {
                    $selectedClusters = $selectedClusters->reject(
                        fn (int $clusterId): bool => $clusterId === (int) $oldClusterId
                    );
                }
                $selectedClusters = $selectedClusters
                    ->filter(fn (int $clusterId): bool => $clusterId > 0)
                    ->unique()
                    ->values()
                    ->all();
                $params['cluster_ids'] = $selectedClusters;
                $lockedPlan->params = $params;
                $lockedPlan->save();
            }

            if ($newQty !== null) {
                $this->redistributeQtyAcrossLines($siblings, $newQty);
            }
            foreach ($siblings as $sibling) {
                $effectiveExcluded = $isExcluded
                    ?? ($newQty !== null && $newQty > 0 ? false : (bool) $sibling->is_excluded);
                $sibling->update([
                    'is_excluded' => $effectiveExcluded,
                    'manual_comment' => $validated['comment'] ?? $sibling->manual_comment,
                    'manual_override_json' => array_filter([
                        'qty_rounded' => $newQty,
                        'is_excluded' => $isExcluded,
                        'cluster_id' => $targetClusterId,
                        'cluster_name' => $targetClusterName,
                        'reason' => $validated['reason'],
                    ], static fn ($value): bool => $value !== null),
                    'manual_updated_by' => $userId,
                    'manual_updated_at' => now(),
                ]);
            }

            AutoSupplyPlanAdjustment::query()->create([
                'auto_supply_plan_id' => $lockedPlan->id,
                'auto_supply_plan_line_id' => $line->id,
                'user_id' => $userId,
                'action' => $targetClusterId !== null && $targetClusterId !== $oldClusterId
                    ? 'destination_change'
                    : ($isExcluded === true
                        ? 'exclude'
                        : ($isExcluded === false ? 'include' : ($newQty !== null ? 'quantity_change' : 'comment'))),
                'old_values_json' => [
                    'qty_rounded' => $oldQty,
                    'is_excluded' => $oldExcluded,
                    'cluster_id' => $oldClusterId,
                ],
                'new_values_json' => [
                    'qty_rounded' => $newQty ?? $oldQty,
                    'is_excluded' => $isExcluded ?? ($newQty !== null && $newQty > 0 ? false : $oldExcluded),
                    'cluster_id' => $targetClusterId ?? $oldClusterId,
                    'cluster_name' => $targetClusterName,
                ],
                'reason' => $validated['reason'],
            ]);

            $review = $this->refreshPlanAfterManualChange($lockedPlan);

            // Возвращаем каноническую строку с агрегированным qty.
            $fresh = $line->fresh();
            $fresh->setAttribute('qty_rounded', $newQty ?? $oldQty);
            $fresh->setAttribute(
                'is_excluded',
                $isExcluded ?? ($newQty !== null && $newQty > 0 ? false : $oldExcluded)
            );

            return [
                'line' => $fresh,
                'old_qty' => $oldQty,
                'new_qty' => $newQty ?? $oldQty,
                'is_excluded' => $fresh->is_excluded,
                'old_cluster_id' => $oldClusterId,
                'new_cluster_id' => $targetClusterId ?? $oldClusterId,
                'plan_total_qty' => $review['total_qty'],
                'plan_total_supply_cost' => $review['total_supply_cost'],
                'budget_overrun' => $review['budget_overrun'],
            ];
        });

        if ($result === null) {
            return response()->json([
                'message' => 'Нельзя изменить план, по которому уже созданы поставки. Создайте новый план.',
                'error' => 'plan_already_materialized',
            ], 409);
        }

        return response()->json([
            'message' => 'Количество обновлено',
            'data' => $result,
        ]);
    }

    private function requestUserId(Request $request): ?int
    {
        $userId = $request->user()?->getAuthIdentifier();

        return is_numeric($userId) ? (int) $userId : null;
    }

    private function assertOzonPlanApprovedForExport(AutoSupplyPlan $plan): void
    {
        if (
            $plan->marketplace === 'ozon'
            && (
                $plan->business_status !== AutoSupplyPlan::BUSINESS_STATUS_APPROVED
                || ! $plan->approval_fingerprint
            )
        ) {
            abort(409, 'Сначала выполните проверку и утвердите текущую версию Ozon-плана');
        }
    }

    /**
     * Строки, которые показанная (агрегированная) строка плана объединяет:
     * Ozon группирует по (sku, cluster_id), остальные МП — по sku
     * (см. aggregatedPlanLinesQuery). Учитывает scope выбранных кластеров.
     */
    private function aggregatedSiblingLines(AutoSupplyPlan $plan, AutoSupplyPlanLine $line): \Illuminate\Support\Collection
    {
        return $this->planLinesQueryForSelectedClusters($plan)
            ->where('sku', $line->sku)
            ->when(
                $plan->marketplace === 'ozon',
                fn ($q) => $line->cluster_id === null
                    ? $q->whereNull('cluster_id')
                    : $q->where('cluster_id', $line->cluster_id)
            )
            ->get();
    }

    /**
     * Распределяет новое суммарное количество по подлежащим строкам агрегата
     * пропорционально текущим. Метод наибольшего остатка гарантирует
     * sum(qty_rounded) === $newTotal — план и все экспорты совпадут с введённым
     * значением, а пер-складская разбивка сохраняется для matrix/by-warehouse экспортов.
     * Кратность pack соблюдается, когда введённая сумма ей кратна; иначе приоритет —
     * точное сохранение суммы (ручной ввод пользователя авторитетен).
     */
    private function redistributeQtyAcrossLines(\Illuminate\Support\Collection $lines, int $newTotal): void
    {
        $lines = $lines->values();
        $count = $lines->count();

        if ($count === 0) {
            return;
        }

        if ($count === 1) {
            $this->applyManualQuantity($lines[0], $newTotal);

            return;
        }

        $pack = 1;
        foreach ($lines as $l) {
            $inputs = is_array($l->explain_json) ? ($l->explain_json['inputs'] ?? []) : [];
            $p = (int) ($inputs['pack_multiple'] ?? 1);
            if ($p > 1) {
                $pack = $p;
                break;
            }
        }

        $unit = ($pack > 1 && $newTotal % $pack === 0) ? $pack : 1;
        $totalUnits = intdiv($newTotal, $unit);

        $oldSum = 0;
        foreach ($lines as $l) {
            $oldSum += (int) $l->qty_rounded;
        }

        // Веса = текущее распределение; если вся группа в нуле — кладём всё в первую
        // (каноническую MIN(id)) строку, остальные оставляем нулевыми.
        $weights = $oldSum > 0
            ? $lines->map(fn ($l) => (int) $l->qty_rounded)->all()
            : array_map(fn ($i) => $i === 0 ? 1 : 0, range(0, $count - 1));
        $weightSum = array_sum($weights);

        $floors = [];
        $frac = [];
        $assigned = 0;
        foreach ($weights as $i => $w) {
            $exact = $totalUnits * $w / $weightSum;
            $floors[$i] = (int) floor($exact);
            $frac[$i] = $exact - $floors[$i];
            $assigned += $floors[$i];
        }

        // Остаток единиц раздаём строкам с наибольшей дробной частью.
        $remainder = $totalUnits - $assigned;
        if ($remainder > 0) {
            $order = array_keys($frac);
            usort($order, fn ($a, $b) => $frac[$b] <=> $frac[$a]);
            foreach (array_slice($order, 0, $remainder) as $i) {
                $floors[$i]++;
            }
        }

        foreach ($lines as $i => $l) {
            $this->applyManualQuantity($l, $floors[$i] * $unit);
        }
    }

    private function applyManualQuantity(AutoSupplyPlanLine $line, int $newQty): void
    {
        $oldQty = max(0, (int) $line->qty_rounded);
        $explain = is_array($line->explain_json) ? $line->explain_json : [];
        $candidate = is_array($explain['optimizer_rejection'] ?? null)
            ? $explain['optimizer_rejection']
            : [];
        $baseQty = $oldQty > 0
            ? $oldQty
            : max(0, (int) ($candidate['candidate_qty'] ?? $line->original_qty_rounded ?? 0));
        $candidateEconomics = is_array($candidate['candidate_economics'] ?? null)
            ? $candidate['candidate_economics']
            : [];
        $ratio = $baseQty > 0 ? $newQty / $baseQty : null;

        $scale = static function (mixed $current, mixed $candidateValue) use ($ratio): ?float {
            $base = $current !== null ? (float) $current : ($candidateValue !== null ? (float) $candidateValue : null);

            return $base !== null && $ratio !== null ? round($base * $ratio, 2) : null;
        };

        $cost = $line->cost_price !== null
            ? round((float) $line->cost_price * $newQty, 2)
            : $scale($line->supply_cost_estimate, $candidateEconomics['supply_cost_estimate'] ?? null);
        $dailyDemand = max(0.0, (float) $line->demand_daily);
        $coverAfter = $dailyDemand > 0
            ? round(((int) $line->current_stock + (int) $line->in_transit + $newQty) / $dailyDemand, 2)
            : null;

        $explain['manual_quantity_recalculation'] = [
            'previous_qty' => $oldQty,
            'new_qty' => $newQty,
            'base_qty' => $baseQty,
            'pack_multiple' => max(1, (int) ($explain['inputs']['pack_multiple'] ?? 1)),
            'recalculated_at' => now()->toIso8601String(),
        ];

        $line->update([
            'qty_rounded' => $newQty,
            'supply_cost_estimate' => $cost,
            'expected_revenue' => $scale($line->expected_revenue, $candidateEconomics['expected_revenue'] ?? null),
            'expected_profit' => $scale($line->expected_profit, $candidateEconomics['expected_profit'] ?? null),
            'cover_days_after' => $coverAfter,
            'explain_json' => $explain,
        ]);
    }

    /**
     * Пересчитывает финансовую сводку после ручного review и одновременно
     * инвалидирует прежнее утверждение/fingerprint.
     *
     * @return array<string, int|float|string|null>
     */
    private function refreshPlanAfterManualChange(AutoSupplyPlan $plan): array
    {
        $active = $plan->lines()->where('is_excluded', false);
        $totalQty = (int) (clone $active)->sum('qty_rounded');
        $totalSupplyCost = round((float) (clone $active)->sum('supply_cost_estimate'), 2);
        $totalExpectedRevenue = round((float) (clone $active)->sum('expected_revenue'), 2);
        $totalExpectedProfit = round((float) (clone $active)->sum('expected_profit'), 2);
        $budgetLimit = (float) ($plan->budget_limit ?? 0);
        $budgetOverrun = $budgetLimit > 0
            ? round(max(0, $totalSupplyCost - $budgetLimit), 2)
            : 0.0;

        $result = is_array($plan->result_json) ? $plan->result_json : [];
        $economics = is_array($result['economics_summary'] ?? null) ? $result['economics_summary'] : [];
        $selection = is_array($result['selection_summary'] ?? null) ? $result['selection_summary'] : [];
        $result['economics_summary'] = array_merge($economics, [
            'total_supply_cost' => $totalSupplyCost,
            'total_expected_revenue' => $totalExpectedRevenue,
            'total_expected_profit' => $totalExpectedProfit,
            'budget_limit' => $budgetLimit > 0 ? $budgetLimit : null,
            'budget_used' => $budgetLimit > 0 ? $totalSupplyCost : null,
            'budget_overrun' => $budgetOverrun,
            'manually_recalculated' => true,
            'recalculated_at' => now()->toIso8601String(),
        ]);
        $result['selection_summary'] = array_merge($selection, [
            'budget_used' => $budgetLimit > 0 ? $totalSupplyCost : null,
            'budget_overrun' => $budgetOverrun,
            'manual_adjustments_count' => $plan->adjustments()->count(),
        ]);

        $plan->update([
            'total_qty' => $totalQty,
            'result_json' => $result,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_REVIEW_REQUIRED,
            'approved_at' => null,
            'approved_by' => null,
            'approval_fingerprint' => null,
            'materialized_at' => null,
            'validation_json' => null,
            'validated_at' => null,
            'validation_fingerprint' => null,
        ]);

        return [
            'total_qty' => $totalQty,
            'total_supply_cost' => $totalSupplyCost,
            'budget_limit' => $budgetLimit > 0 ? $budgetLimit : null,
            'budget_overrun' => $budgetOverrun,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_REVIEW_REQUIRED,
        ];
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/ozon
     *
     * Колонки: "артикул", "имя (необязательно)", "количество"
     * Группировка: SUM(qty_rounded) по offer_id
     */
    public function exportOzon(Request $request, string $id): StreamedResponse
    {
        $plan = $this->authorizedPlan($request, $id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }
        $this->assertOzonPlanApprovedForExport($plan);

        $lines = $this->planLinesQueryForSelectedClusters($plan)
            ->where('is_excluded', false)
            ->get();

        // Группируем по offer_id
        $grouped = [];
        foreach ($lines as $line) {
            $offerId = $line->offer_id ?? $line->sku;
            if (empty($offerId) || $line->qty_rounded <= 0) {
                continue;
            }
            if (! isset($grouped[$offerId])) {
                $grouped[$offerId] = [
                    'offer_id' => $offerId,
                    'name' => $line->product_name,
                    'qty' => 0,
                ];
            }
            $grouped[$offerId]['qty'] += $line->qty_rounded;
        }

        // Убираем строки с итоговым qty < 1
        $grouped = array_filter($grouped, fn ($item) => $item['qty'] >= 1);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ozon Supply');

        // Заголовки строго по шаблону Ozon
        $sheet->setCellValue('A1', 'артикул');
        $sheet->setCellValue('B1', 'имя (необязательно)');
        $sheet->setCellValue('C1', 'количество');

        $row = 2;
        foreach ($grouped as $item) {
            $sheet->setCellValue("A{$row}", $item['offer_id']);
            $sheet->setCellValue("B{$row}", $item['name'] ?? '');
            $sheet->setCellValue("C{$row}", $item['qty']);
            $row++;
        }

        $filename = "ozon_supply_plan_{$plan->id}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/wb
     *
     * Колонки: "Баркод", "Количество"
     * Группировка: SUM(qty_rounded) по barcode
     * Ошибка если barcode null или дубли
     */
    public function exportWb(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }

        $lines = $plan->lines()->get();

        // Собираем все SKU для поиска barcode
        $skus = $lines->pluck('sku')->unique()->toArray();
        $products = Product::where('integration_id', $plan->integration_id)
            ->where('marketplace', 'wildberries')
            ->whereIn('sku', $skus)
            ->get(['sku', 'barcode'])
            ->keyBy('sku');

        $grouped = [];
        $errors = [];

        // Проверяем однозначность offer_id → barcode
        $skuToBarcodes = [];
        foreach ($lines as $line) {
            $product = $products->get($line->sku);
            $barcode = $product?->barcode ?? $line->barcode;
            if ($barcode) {
                $skuToBarcodes[$line->sku][$barcode] = true;
            }
        }

        foreach ($lines as $line) {
            if ($line->qty_rounded <= 0) {
                continue;
            }

            $product = $products->get($line->sku);
            $barcode = $product?->barcode ?? $line->barcode;

            if (empty($barcode)) {
                $errors[] = [
                    'sku' => $line->sku,
                    'product_name' => $line->product_name,
                    'error' => 'Баркод не найден',
                ];

                continue;
            }

            // Один offer_id → несколько баркодов (размеры)
            if (isset($skuToBarcodes[$line->sku]) && count($skuToBarcodes[$line->sku]) > 1) {
                $errors[] = [
                    'sku' => $line->sku,
                    'product_name' => $line->product_name,
                    'barcodes' => array_keys($skuToBarcodes[$line->sku]),
                    'error' => 'Несколько баркодов для одного SKU, нужна детализация',
                ];

                continue;
            }

            if (! isset($grouped[$barcode])) {
                $grouped[$barcode] = [
                    'barcode' => $barcode,
                    'qty' => 0,
                ];
            }
            $grouped[$barcode]['qty'] += $line->qty_rounded;
        }

        // Убираем строки с итоговым qty < 1
        $grouped = array_filter($grouped, fn ($item) => $item['qty'] >= 1);

        // Сохраняем ошибки в план
        if (! empty($errors)) {
            $plan->update(['export_errors' => $errors]);
        }

        if (empty($grouped)) {
            return response()->json([
                'message' => 'Нет данных для экспорта WB',
                'errors' => $errors,
            ], 422);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('WB Supply');

        // Заголовки строго по шаблону WB
        $sheet->setCellValue('A1', 'Баркод');
        $sheet->setCellValue('B1', 'Количество');

        $row = 2;
        foreach ($grouped as $item) {
            $sheet->setCellValue("A{$row}", $item['barcode']);
            $sheet->setCellValue("B{$row}", $item['qty']);
            $row++;
        }

        $filename = "wb_supply_plan_{$plan->id}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/ozon-matrix
     *
     * Формат матрицы Ozon FBO: строки = артикулы, столбцы = склады
     * Шаблон для загрузки в ЛК Ozon (Поставки → Создать поставку)
     */
    public function exportOzonMatrix(Request $request, string $id): StreamedResponse
    {
        $plan = $this->authorizedPlan($request, $id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }
        $this->assertOzonPlanApprovedForExport($plan);

        $lines = $this->planLinesQueryForSelectedClusters($plan)
            ->where('is_excluded', false)
            ->where('qty_rounded', '>', 0)
            ->get();

        if ($lines->isEmpty()) {
            abort(422, 'Нет данных для экспорта');
        }

        // Собираем уникальные склады (сортируем по имени)
        $warehouseMap = [];
        foreach ($lines as $line) {
            $whName = $line->warehouse_name ?: $line->warehouse_id ?: 'Неизвестный';
            if (! isset($warehouseMap[$whName])) {
                $warehouseMap[$whName] = $whName;
            }
        }
        ksort($warehouseMap);
        $warehouseNames = array_values($warehouseMap);

        // Собираем матрицу: offer_id → warehouse_name → qty
        $matrix = [];
        $offerMeta = [];
        foreach ($lines as $line) {
            $offerId = $line->offer_id ?? $line->sku;
            $whName = $line->warehouse_name ?: $line->warehouse_id ?: 'Неизвестный';

            if (! isset($matrix[$offerId])) {
                $matrix[$offerId] = [];
                $offerMeta[$offerId] = $line->product_name;
            }
            $matrix[$offerId][$whName] = ($matrix[$offerId][$whName] ?? 0) + $line->qty_rounded;
        }

        // Сортируем артикулы
        ksort($matrix);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Поставка Ozon');

        // === Заголовки ===
        $sheet->setCellValue('A1', 'артикул');

        $colIndex = 2; // B = 2
        foreach ($warehouseNames as $whName) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue("{$colLetter}1", $whName);
            // Автоширина
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            $colIndex++;
        }

        // Стиль заголовков
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex - 1);
        $headerRange = "A1:{$lastColLetter}1";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E8F5E9'],
            ],
            'borders' => [
                'bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN],
            ],
        ]);
        $sheet->getColumnDimension('A')->setAutoSize(true);

        // === Данные ===
        $row = 2;
        foreach ($matrix as $offerId => $warehouseQty) {
            $sheet->setCellValue("A{$row}", $offerId);

            $colIndex = 2;
            foreach ($warehouseNames as $whName) {
                $qty = $warehouseQty[$whName] ?? 0;
                if ($qty > 0) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
                    $sheet->setCellValue("{$colLetter}{$row}", $qty);
                }
                $colIndex++;
            }
            $row++;
        }

        // Freeze header
        $sheet->freezePane('B2');

        $filename = "ozon_supply_matrix_{$plan->id}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/ozon-by-warehouse
     *
     * ZIP-архив с отдельными XLSX шаблонами по каждому складу (городу)
     * Каждый файл — шаблон Ozon FBO: "артикул", "имя (необязательно)", "количество"
     */
    public function exportOzonByWarehouse(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $plan = $this->authorizedPlan($request, $id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }
        $this->assertOzonPlanApprovedForExport($plan);

        $lines = $this->planLinesQueryForSelectedClusters($plan)
            ->where('is_excluded', false)
            ->where('qty_rounded', '>', 0)
            ->get();

        if ($lines->isEmpty()) {
            return response()->json(['message' => 'Нет данных для экспорта'], 422);
        }

        // Группируем по складам
        $byWarehouse = [];
        foreach ($lines as $line) {
            $whName = $line->warehouse_name ?: $line->warehouse_id ?: 'Неизвестный';
            if (! isset($byWarehouse[$whName])) {
                $byWarehouse[$whName] = [];
            }
            $offerId = $line->offer_id ?? $line->sku;
            if (! isset($byWarehouse[$whName][$offerId])) {
                $byWarehouse[$whName][$offerId] = [
                    'offer_id' => $offerId,
                    'name' => $line->product_name,
                    'qty' => 0,
                ];
            }
            $byWarehouse[$whName][$offerId]['qty'] += $line->qty_rounded;
        }

        ksort($byWarehouse);

        // Создаём ZIP
        $tempFile = tempnam(sys_get_temp_dir(), 'supply_zip_');
        $zip = new \ZipArchive;
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($byWarehouse as $whName => $items) {
            // Убираем строки с qty < 1
            $items = array_filter($items, fn ($item) => $item['qty'] >= 1);
            if (empty($items)) {
                continue;
            }

            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Поставка');

            $sheet->setCellValue('A1', 'артикул');
            $sheet->setCellValue('B1', 'имя (необязательно)');
            $sheet->setCellValue('C1', 'количество');

            // Стиль заголовков
            $sheet->getStyle('A1:C1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 10],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E8F5E9'],
                ],
            ]);
            $sheet->getColumnDimension('A')->setAutoSize(true);
            $sheet->getColumnDimension('B')->setAutoSize(true);

            $row = 2;
            foreach ($items as $item) {
                $sheet->setCellValue("A{$row}", $item['offer_id']);
                $sheet->setCellValue("B{$row}", $item['name'] ?? '');
                $sheet->setCellValue("C{$row}", $item['qty']);
                $row++;
            }

            // Записываем XLSX во временный файл
            $tmpXlsx = tempnam(sys_get_temp_dir(), 'xlsx_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tmpXlsx);
            $spreadsheet->disconnectWorksheets();

            // Безопасное имя файла
            $safeName = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '', $whName);
            $safeName = trim($safeName) ?: 'warehouse';
            $zip->addFile($tmpXlsx, "{$safeName}.xlsx");
        }

        $zip->close();

        $zipContent = file_get_contents($tempFile);
        @unlink($tempFile);

        // Удаляем временные xlsx файлы
        foreach (glob(sys_get_temp_dir().'/xlsx_*') as $f) {
            @unlink($f);
        }

        $filename = "ozon_supply_by_warehouse_{$plan->id}.zip";

        return new StreamedResponse(function () use ($zipContent) {
            echo $zipContent;
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length' => strlen($zipContent),
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Стрим XLSX файла
     */
    private function streamXlsx(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
