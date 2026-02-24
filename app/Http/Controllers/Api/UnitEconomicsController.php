<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitEconomics\IndexUnitEconomicsRequest;
use App\Http\Requests\UnitEconomics\CalculateRequest;
use App\Http\Requests\UnitEconomics\StoreUnitEconomicsRequest;
use App\Http\Requests\UnitEconomics\UpdateUnitEconomicsRequest;
use App\Models\UnitEconomics;
use App\Services\UnitEconomicsService;
use Illuminate\Http\JsonResponse;

class UnitEconomicsController extends Controller
{
    public function __construct(
        private UnitEconomicsService $unitEconomicsService
    ) {}

    public function index(IndexUnitEconomicsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = UnitEconomics::query();

        if (!empty($validated['marketplace'])) {
            $query->marketplace($validated['marketplace']);
        }

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (!empty($validated['integration_id'])) {
            $query->where('integration_id', $validated['integration_id']);
        }

        if (!empty($validated['profitability'])) {
            if ($validated['profitability'] === 'profitable') {
                $query->profitable();
            } elseif ($validated['profitability'] === 'unprofitable') {
                $query->unprofitable();
            }
        }

        $query->marginRange(
            $validated['margin_min'] ?? null,
            $validated['margin_max'] ?? null
        );

        $query->priceRange(
            $validated['price_min'] ?? null,
            $validated['price_max'] ?? null
        );

        $sortField = $validated['sort'] ?? 'sku';
        $sortOrder = $validated['sort_order'] ?? 'asc';
        $query->orderBy($sortField, $sortOrder);

        $limit = $validated['limit'] ?? 50;
        $page = $validated['page'] ?? 1;

        $items = $query->paginate($limit, ['*'], 'page', $page);

        $stats = $this->unitEconomicsService->getStats($validated);

        return response()->json([
            'data' => [
                'items' => $items->items(),
                'total' => $items->total(),
            ],
            'stats' => $stats,
        ]);
    }

    public function byMarketplace(IndexUnitEconomicsRequest $request, string $marketplace): JsonResponse
    {
        $validated = $request->validated();
        $validated['marketplace'] = $marketplace;

        $query = UnitEconomics::marketplace($marketplace);

        if (!empty($validated['integration_id'])) {
            $query->where('integration_id', $validated['integration_id']);
        }

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (!empty($validated['profitability'])) {
            if ($validated['profitability'] === 'profitable') {
                $query->profitable();
            } elseif ($validated['profitability'] === 'unprofitable') {
                $query->unprofitable();
            }
        }

        $query->marginRange(
            $validated['margin_min'] ?? null,
            $validated['margin_max'] ?? null
        );

        $query->priceRange(
            $validated['price_min'] ?? null,
            $validated['price_max'] ?? null
        );

        $sortField = $validated['sort'] ?? 'sku';
        $sortOrder = $validated['sort_order'] ?? 'asc';
        $query->orderBy($sortField, $sortOrder);

        $limit = $validated['limit'] ?? 50;
        $page = $validated['page'] ?? 1;

        $items = $query->paginate($limit, ['*'], 'page', $page);

        $stats = $this->unitEconomicsService->getStatsByMarketplace($marketplace);

        return response()->json([
            'data' => [
                'items' => $items->items(),
                'total' => $items->total(),
            ],
            'stats' => $stats,
        ]);
    }

    public function show(string $marketplace, string $sku): JsonResponse
    {
        $unitEconomics = UnitEconomics::where('marketplace', $marketplace)
            ->where('sku', $sku)
            ->latest()
            ->firstOrFail();

        return response()->json([
            'data' => $unitEconomics,
        ]);
    }

    public function store(StoreUnitEconomicsRequest $request, string $marketplace): JsonResponse
    {
        $validated = $request->validated();
        $validated['marketplace'] = $marketplace;

        $unitEconomics = $this->unitEconomicsService->createOrUpdate($validated);

        return response()->json([
            'data' => $unitEconomics,
            'message' => 'Unit economics created successfully',
        ], 201);
    }

    public function update(UpdateUnitEconomicsRequest $request, string $marketplace, int $id): JsonResponse
    {
        $unitEconomics = UnitEconomics::where('marketplace', $marketplace)
            ->findOrFail($id);

        $unitEconomics->update($request->validated());

        return response()->json([
            'data' => $unitEconomics->fresh(),
            'message' => 'Unit economics updated successfully',
        ]);
    }

    public function destroy(string $marketplace, int $id): JsonResponse
    {
        $unitEconomics = UnitEconomics::where('marketplace', $marketplace)
            ->findOrFail($id);

        $unitEconomics->delete();

        return response()->json([
            'message' => 'Unit economics deleted successfully',
        ]);
    }

    public function calculate(CalculateRequest $request, string $marketplace): JsonResponse
    {
        $validated = $request->validated();
        $validated['marketplace'] = $marketplace;

        $result = $this->unitEconomicsService->calculate($marketplace, $validated);

        return response()->json([
            'data' => $result,
        ]);
    }

    public function comparison(): JsonResponse
    {
        $comparison = $this->unitEconomicsService->getMarketplaceComparison();

        return response()->json([
            'data' => $comparison,
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = $this->unitEconomicsService->getOverallStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    public function statsByMarketplace(string $marketplace): JsonResponse
    {
        $stats = $this->unitEconomicsService->getStatsByMarketplace($marketplace);

        return response()->json([
            'data' => $stats,
        ]);
    }

    public function commissions(string $marketplace): JsonResponse
    {
        $commissions = $this->unitEconomicsService->getCommissions($marketplace);

        return response()->json([
            'data' => $commissions,
        ]);
    }

    public function tariffs(string $marketplace): JsonResponse
    {
        $tariffs = $this->unitEconomicsService->getTariffs($marketplace);

        return response()->json([
            'data' => $tariffs,
        ]);
    }
}
