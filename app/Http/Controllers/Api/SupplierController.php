<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query();

        if ($request->has('search')) {
            $query->search($request->input('search'));
        }

        $suppliers = $query->orderBy('name')->paginate(50);

        return response()->json([
            'data' => [
                'suppliers' => $suppliers->items(),
                'total' => $suppliers->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $supplier = Supplier::with(['shipments' => function ($q) {
            $q->latest()->limit(10);
        }])->findOrFail($id);

        return response()->json([
            'data' => $supplier,
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        return response()->json([
            'data' => $supplier,
            'message' => 'Supplier created successfully',
        ], 201);
    }

    public function update(UpdateSupplierRequest $request, string $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update($request->validated());

        return response()->json([
            'data' => $supplier->fresh(),
            'message' => 'Supplier updated successfully',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        if ($supplier->shipments()->exists()) {
            return response()->json([
                'message' => 'Cannot delete supplier with existing shipments',
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'message' => 'Supplier deleted successfully',
        ]);
    }
}
