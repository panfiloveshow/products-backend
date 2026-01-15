<?php

namespace App\Http\Controllers\Api;

use App\Domains\Supplies\Services\SupplyCalculationService;
use App\Domains\Supplies\Services\SupplyOptimizationService;
use App\Domains\Supplies\Services\SupplyRecommendationService;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Shipment;
use App\Models\SupplyPlan;
use App\Models\SupplyRecommendation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplyPlanController extends Controller
{
    public function __construct(
        private SupplyCalculationService $calculationService,
        private SupplyOptimizationService $optimizationService,
        private SupplyRecommendationService $recommendationService
    ) {}

    /**
     * GET /api/supply-plans
     * Список планов поставок
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupplyPlan::query();

        if ($request->has('marketplace')) {
            $query->marketplace($request->marketplace);
        }

        if ($request->has('status')) {
            $query->status($request->status);
        }

        if ($request->has('integration_id')) {
            $query->where('integration_id', $request->integration_id);
        }

        $plans = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('limit', 20));

        return response()->json([
            'message' => 'Success',
            'data' => $plans,
        ]);
    }

    /**
     * POST /api/supply-plans
     * Создать план поставок
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'integration_id' => 'nullable|exists:integrations,id',
            'marketplace' => 'required|in:wildberries,ozon',
            'target_days_of_stock' => 'nullable|integer|min:7|max:90',
            'safety_stock_days' => 'nullable|integer|min:1|max:30',
        ]);

        $plan = SupplyPlan::create([
            ...$validated,
            'status' => SupplyPlan::STATUS_DRAFT,
            'created_by' => auth()->id(),
            'created_by_name' => auth()->user()?->name ?? 'System',
        ]);

        return response()->json([
            'message' => 'План поставок создан',
            'data' => $plan,
        ], 201);
    }

    /**
     * GET /api/supply-plans/{id}
     * Детали плана
     */
    public function show(string $id): JsonResponse
    {
        $plan = SupplyPlan::with('shipments')->findOrFail($id);

        return response()->json([
            'message' => 'Success',
            'data' => $plan,
        ]);
    }

    /**
     * PUT /api/supply-plans/{id}
     * Обновить план
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $plan = SupplyPlan::findOrFail($id);

        if (!in_array($plan->status, [SupplyPlan::STATUS_DRAFT])) {
            return response()->json([
                'message' => 'Нельзя редактировать план в статусе ' . $plan->status,
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'period_start' => 'sometimes|date',
            'period_end' => 'sometimes|date|after_or_equal:period_start',
            'target_days_of_stock' => 'nullable|integer|min:7|max:90',
            'safety_stock_days' => 'nullable|integer|min:1|max:30',
        ]);

        $plan->update($validated);

        return response()->json([
            'message' => 'План обновлён',
            'data' => $plan->fresh(),
        ]);
    }

    /**
     * DELETE /api/supply-plans/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $plan = SupplyPlan::findOrFail($id);

        if ($plan->status !== SupplyPlan::STATUS_DRAFT) {
            return response()->json([
                'message' => 'Можно удалить только черновик',
            ], 422);
        }

        $plan->delete();

        return response()->json([
            'message' => 'План удалён',
        ]);
    }

    /**
     * GET /api/supply-plans/{id}/calculate
     * Рассчитать оптимальный состав плана
     */
    public function calculate(string $id): JsonResponse
    {
        $plan = SupplyPlan::findOrFail($id);

        if (!$plan->integration_id) {
            return response()->json([
                'message' => 'Для расчёта необходимо указать интеграцию',
            ], 422);
        }

        $settings = [
            'target_days_of_stock' => $plan->target_days_of_stock ?? 30,
            'safety_stock_days' => $plan->safety_stock_days ?? 7,
        ];

        $calculations = $this->calculationService->calculateForIntegration(
            $plan->integration_id,
            $settings
        );

        $totals = $this->calculationService->calculateTotalCost($calculations);

        // Подбираем транспорт
        $trucks = $this->optimizationService->selectOptimalTruck(
            $totals['total_volume'] ?? 0,
            $totals['total_weight'] ?? 0
        );

        return response()->json([
            'message' => 'Расчёт выполнен',
            'data' => [
                'items' => $calculations->map(fn($c) => $c->toArray())->values(),
                'totals' => $totals,
                'trucks' => $trucks,
            ],
        ]);
    }

    /**
     * POST /api/supply-plans/{id}/approve
     * Утвердить план
     */
    public function approve(string $id): JsonResponse
    {
        $plan = SupplyPlan::findOrFail($id);

        if ($plan->status !== SupplyPlan::STATUS_DRAFT) {
            return response()->json([
                'message' => 'Можно утвердить только черновик',
            ], 422);
        }

        $plan->approve();

        return response()->json([
            'message' => 'План утверждён',
            'data' => $plan->fresh(),
        ]);
    }

    /**
     * POST /api/supply-plans/{id}/cancel
     * Отменить план
     */
    public function cancel(string $id): JsonResponse
    {
        $plan = SupplyPlan::findOrFail($id);

        if ($plan->status === SupplyPlan::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Нельзя отменить завершённый план',
            ], 422);
        }

        $plan->cancel();

        return response()->json([
            'message' => 'План отменён',
            'data' => $plan->fresh(),
        ]);
    }
}
