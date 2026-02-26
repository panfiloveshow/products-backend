<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipment\IndexShipmentRequest;
use App\Http\Requests\Shipment\StoreShipmentRequest;
use App\Http\Requests\Shipment\UpdateShipmentRequest;
use App\Http\Requests\Shipment\AddItemRequest;
use App\Http\Requests\Shipment\UpdateItemRequest;
use App\Http\Requests\Shipment\BookSlotRequest;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentRecommendation;
use App\Services\ShipmentService;
use Illuminate\Http\JsonResponse;

class ShipmentController extends Controller
{
    public function __construct(
        private ShipmentService $shipmentService
    ) {}

    public function index(IndexShipmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = Shipment::with(['items', 'supplier']);

        if (!empty($validated['status'])) {
            $query->status($validated['status']);
        }

        if (!empty($validated['supplier_id'])) {
            $query->supplier($validated['supplier_id']);
        }

        if (!empty($validated['marketplace'])) {
            $query->marketplace($validated['marketplace']);
        }

        if (!empty($validated['search'])) {
            $query->where('name', 'like', "%{$validated['search']}%");
        }

        $query->dateRange($validated['date_from'] ?? null, $validated['date_to'] ?? null);

        $query->orderBy('created_at', 'desc');

        $limit = $validated['limit'] ?? 50;
        $page = $validated['page'] ?? 1;

        $shipments = $query->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'data' => [
                'shipments' => $shipments->items(),
                'total' => $shipments->total(),
                'page' => $shipments->currentPage(),
                'limit' => $shipments->perPage(),
                'has_more' => $shipments->hasMorePages(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $shipment = Shipment::with(['items', 'supplier'])->findOrFail($id);

        return response()->json([
            'data' => $shipment,
        ]);
    }

    public function store(StoreShipmentRequest $request): JsonResponse
    {
        $shipment = $this->shipmentService->create($request->validated());

        return response()->json([
            'data' => $shipment->load(['items', 'supplier']),
            'message' => 'Shipment created successfully',
        ], 201);
    }

    public function update(UpdateShipmentRequest $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeEdited()) {
            return response()->json([
                'message' => 'Shipment cannot be edited in current status',
            ], 422);
        }

        $shipment->update($request->validated());

        return response()->json([
            'data' => $shipment->fresh(['items', 'supplier']),
            'message' => 'Shipment updated successfully',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeEdited()) {
            return response()->json([
                'message' => 'Shipment cannot be deleted in current status',
            ], 422);
        }

        $shipment->delete();

        return response()->json([
            'message' => 'Shipment deleted successfully',
        ]);
    }

    public function addItem(AddItemRequest $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeEdited()) {
            return response()->json([
                'message' => 'Cannot add items to shipment in current status',
            ], 422);
        }

        $item = $this->shipmentService->addItem($shipment, $request->validated());

        return response()->json([
            'data' => $item,
            'message' => 'Item added successfully',
        ], 201);
    }

    public function updateItem(UpdateItemRequest $request, string $id, string $itemId): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeEdited()) {
            return response()->json([
                'message' => 'Cannot update items in current status',
            ], 422);
        }

        $item = ShipmentItem::where('shipment_id', $id)->findOrFail($itemId);
        $item->update($request->validated());

        return response()->json([
            'data' => $item->fresh(),
            'message' => 'Item updated successfully',
        ]);
    }

    public function removeItem(string $id, string $itemId): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeEdited()) {
            return response()->json([
                'message' => 'Cannot remove items in current status',
            ], 422);
        }

        $item = ShipmentItem::where('shipment_id', $id)->findOrFail($itemId);
        $item->delete();

        return response()->json([
            'message' => 'Item removed successfully',
        ]);
    }

    public function submit(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeSubmitted()) {
            return response()->json([
                'message' => 'Shipment cannot be submitted',
            ], 422);
        }

        $shipment->update(['status' => Shipment::STATUS_PENDING_LOGISTICS]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Shipment submitted for approval',
        ]);
    }

    public function approve(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeApproved()) {
            return response()->json([
                'message' => 'Shipment cannot be approved',
            ], 422);
        }

        $shipment->update([
            'status' => Shipment::STATUS_APPROVED,
            'logistics_approval' => [
                'approved' => true,
                'approved_by' => auth()->id(),
                'approved_by_name' => auth()->user()?->name ?? 'System',
                'approved_at' => now()->toISOString(),
            ],
        ]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Shipment approved',
        ]);
    }

    public function reject(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeApproved()) {
            return response()->json([
                'message' => 'Shipment cannot be rejected',
            ], 422);
        }

        $shipment->update([
            'status' => Shipment::STATUS_REJECTED,
            'logistics_approval' => [
                'approved' => false,
                'approved_by' => auth()->id(),
                'approved_by_name' => auth()->user()?->name ?? 'System',
                'approved_at' => now()->toISOString(),
                'comment' => request('comment'),
            ],
        ]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Shipment rejected',
        ]);
    }

    public function send(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeSent()) {
            return response()->json([
                'message' => 'Shipment cannot be sent',
            ], 422);
        }

        $shipment->update([
            'status' => Shipment::STATUS_SENT,
            'sent_at' => now(),
        ]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Shipment marked as sent',
        ]);
    }

    public function deliver(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!in_array($shipment->status, [Shipment::STATUS_SENT, Shipment::STATUS_IN_TRANSIT])) {
            return response()->json([
                'message' => 'Shipment cannot be marked as delivered',
            ], 422);
        }

        $shipment->update([
            'status' => Shipment::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Shipment marked as delivered',
        ]);
    }

    public function slots(): JsonResponse
    {
        $slots = $this->shipmentService->getAvailableSlots();

        return response()->json([
            'data' => $slots,
        ]);
    }

    public function bookSlot(BookSlotRequest $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        $slot = $this->shipmentService->bookSlot($shipment, $request->validated());

        return response()->json([
            'data' => $slot,
            'message' => 'Slot booked successfully',
        ]);
    }

    public function recommendations(): JsonResponse
    {
        $recommendations = ShipmentRecommendation::active()
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium')")
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $recommendations,
        ]);
    }

    public function createFromRecommendation(string $recommendationId): JsonResponse
    {
        $recommendation = ShipmentRecommendation::findOrFail($recommendationId);

        if ($recommendation->is_used) {
            return response()->json([
                'message' => 'Recommendation already used',
            ], 422);
        }

        $shipment = $this->shipmentService->createFromRecommendation($recommendation);

        return response()->json([
            'data' => $shipment->load(['items', 'supplier']),
            'message' => 'Shipment created from recommendation',
        ], 201);
    }

    public function exportPdf(string $id): JsonResponse
    {
        $shipment = Shipment::with(['items', 'supplier'])->findOrFail($id);
        $url = $this->shipmentService->exportToPdf($shipment);

        return response()->json([
            'data' => ['url' => $url],
        ]);
    }

    public function exportCsv(string $id): JsonResponse
    {
        $shipment = Shipment::with(['items', 'supplier'])->findOrFail($id);
        $url = $this->shipmentService->exportToCsv($shipment);

        return response()->json([
            'data' => ['url' => $url],
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = $this->shipmentService->getStats();

        return response()->json([
            'data' => $stats,
        ]);
    }
}
