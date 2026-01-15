<?php

namespace App\Http\Controllers\Api;

use App\Domains\Supplies\Services\SupplyCalculationService;
use App\Domains\Supplies\Services\SupplyOptimizationService;
use App\Domains\Supplies\Services\SupplyRecommendationService;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Shipment;
use App\Models\SupplyRecommendation;
use App\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplyRecommendationController extends Controller
{
    public function __construct(
        private SupplyRecommendationService $recommendationService,
        private SupplyCalculationService $calculationService,
        private SupplyOptimizationService $optimizationService,
        private ShipmentService $shipmentService
    ) {}

    /**
     * GET /api/supply-recommendations
     * Список активных рекомендаций
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupplyRecommendation::query();

        // Фильтры
        if ($request->has('marketplace')) {
            $query->marketplace($request->marketplace);
        }

        if ($request->has('integration_id')) {
            $query->where('integration_id', $request->integration_id);
        }

        if ($request->has('priority')) {
            $query->priority($request->priority);
        }

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        // Сортировка по приоритету
        $recommendations = $query
            ->orderByRaw("CASE priority 
                WHEN 'urgent' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                ELSE 4 END")
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('limit', 20));

        // Статистика
        $stats = $this->recommendationService->getStats(
            $request->has('integration_id') ? (int) $request->integration_id : null
        );

        return response()->json([
            'message' => 'Success',
            'data' => $recommendations,
            'stats' => $stats,
        ]);
    }

    /**
     * GET /api/supply-recommendations/{id}
     * Детали рекомендации
     */
    public function show(string $id): JsonResponse
    {
        $recommendation = SupplyRecommendation::with('integration')->findOrFail($id);

        // Добавляем расчёт транспорта
        $trucks = $this->optimizationService->selectOptimalTruck(
            $recommendation->total_volume ?? 0,
            $recommendation->total_weight ?? 0
        );

        return response()->json([
            'message' => 'Success',
            'data' => [
                'recommendation' => $recommendation,
                'trucks' => $trucks,
            ],
        ]);
    }

    /**
     * POST /api/supply-recommendations/generate
     * Сгенерировать рекомендации для интеграции
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        // Проверяем маркетплейс
        if (!in_array($integration->marketplace, ['wildberries', 'ozon'])) {
            return response()->json([
                'message' => 'Поддерживаются только Wildberries и Ozon',
            ], 422);
        }

        $recommendations = $this->recommendationService->generateForIntegration($integration);

        return response()->json([
            'message' => 'Рекомендации сгенерированы',
            'data' => [
                'count' => $recommendations->count(),
                'recommendations' => $recommendations,
            ],
        ]);
    }

    /**
     * POST /api/supply-recommendations/{id}/apply
     * Применить рекомендацию (создать поставку)
     */
    public function apply(string $id): JsonResponse
    {
        $recommendation = SupplyRecommendation::findOrFail($id);

        if ($recommendation->is_used) {
            return response()->json([
                'message' => 'Рекомендация уже использована',
            ], 422);
        }

        if ($recommendation->is_dismissed) {
            return response()->json([
                'message' => 'Рекомендация отклонена',
            ], 422);
        }

        // Создаём поставку из рекомендации
        $items = collect($recommendation->recommended_items ?? [])->map(fn($item) => [
            'sku' => $item['sku'],
            'quantity' => $item['quantity'],
            'cost_price' => $item['cost_price'] ?? null,
            'volume_per_unit' => $item['volume_per_unit'] ?? null,
            'weight_per_unit' => $item['weight_per_unit'] ?? null,
            'priority' => $item['priority'] ?? 'medium',
        ])->toArray();

        $shipment = $this->shipmentService->create([
            'name' => "Поставка по рекомендации #{$recommendation->id}",
            'marketplace' => $recommendation->marketplace,
            'shipment_type' => 'fbo',
            'warehouse_name' => $recommendation->warehouse_name,
            'supplier_id' => null, // Будет выбран позже
            'items' => $items,
        ]);

        // Помечаем рекомендацию как использованную
        $recommendation->markAsUsed($shipment->id);

        return response()->json([
            'message' => 'Поставка создана из рекомендации',
            'data' => [
                'shipment' => $shipment->load('items'),
                'recommendation' => $recommendation->fresh(),
            ],
        ]);
    }

    /**
     * POST /api/supply-recommendations/{id}/dismiss
     * Отклонить рекомендацию
     */
    public function dismiss(Request $request, string $id): JsonResponse
    {
        $recommendation = SupplyRecommendation::findOrFail($id);

        if ($recommendation->is_used) {
            return response()->json([
                'message' => 'Рекомендация уже использована',
            ], 422);
        }

        $reason = $request->get('reason');
        $recommendation->dismiss($reason);

        return response()->json([
            'message' => 'Рекомендация отклонена',
            'data' => $recommendation->fresh(),
        ]);
    }

    /**
     * GET /api/supply-recommendations/by-warehouse
     * Рекомендации сгруппированные по складам
     */
    public function byWarehouse(Request $request): JsonResponse
    {
        $query = SupplyRecommendation::active();

        if ($request->has('marketplace')) {
            $query->marketplace($request->marketplace);
        }

        if ($request->has('integration_id')) {
            $query->where('integration_id', $request->integration_id);
        }

        $recommendations = $query->get();

        // Группируем по складам
        $grouped = $recommendations->groupBy('warehouse_id')->map(function ($items, $warehouseId) {
            $first = $items->first();
            return [
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $first->warehouse_name,
                'marketplace' => $first->marketplace,
                'recommendations_count' => $items->count(),
                'total_items' => $items->sum('total_items'),
                'total_quantity' => $items->sum('total_quantity'),
                'total_cost' => $items->sum('total_cost'),
                'urgent_count' => $items->where('priority', 'urgent')->count(),
                'high_count' => $items->where('priority', 'high')->count(),
                'recommendations' => $items->values(),
            ];
        })->values();

        return response()->json([
            'message' => 'Success',
            'data' => $grouped,
        ]);
    }

    /**
     * GET /api/supply-recommendations/stats
     * Статистика по рекомендациям
     */
    public function stats(Request $request): JsonResponse
    {
        $integrationId = $request->has('integration_id') 
            ? (int) $request->integration_id 
            : null;

        $stats = $this->recommendationService->getStats($integrationId);

        return response()->json([
            'message' => 'Success',
            'data' => $stats,
        ]);
    }
}
