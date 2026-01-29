<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Posting;
use App\Services\PostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostingController extends Controller
{
    public function __construct(
        private PostingService $postingService
    ) {}

    /**
     * Список отправлений FBS
     * GET /api/postings
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
            'status' => 'nullable|string',
            'posting_number' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $integrationId = $request->input('integration_id');
        $status = $request->input('status');
        $search = $request->input('posting_number');
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);

        $query = Posting::with('items')
            ->where('integration_id', $integrationId)
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->status($status);
        }

        if ($search) {
            $query->search($search);
        }

        if ($request->input('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->input('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        $postings = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $postings->items(),
            'meta' => [
                'current_page' => $postings->currentPage(),
                'total' => $postings->total(),
                'per_page' => $postings->perPage(),
                'last_page' => $postings->lastPage(),
            ],
        ]);
    }

    /**
     * Статистика отправлений
     * GET /api/postings/statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
        ]);

        $stats = $this->postingService->getStatistics(
            $request->input('integration_id')
        );

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Синхронизация отправлений с маркетплейса
     * POST /api/postings/sync
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
            'status' => 'nullable|string',
            'date_from' => 'nullable|date',
        ]);

        try {
            $result = $this->postingService->sync(
                $request->input('integration_id'),
                $request->input('status'),
                $request->input('date_from')
            );

            return response()->json([
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка синхронизации: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Детали отправления
     * GET /api/postings/{id}
     */
    public function show(string $id): JsonResponse
    {
        $posting = Posting::with('items')->findOrFail($id);

        return response()->json([
            'data' => $posting,
        ]);
    }

    /**
     * Начать сборку отправления
     * POST /api/postings/{id}/assemble
     */
    public function assemble(string $id): JsonResponse
    {
        $posting = Posting::findOrFail($id);

        if (!$posting->canAssemble()) {
            return response()->json([
                'message' => 'Отправление не может быть собрано в текущем статусе',
            ], 422);
        }

        $posting->markAsAssembled();

        return response()->json([
            'data' => $posting->fresh(['items']),
            'message' => 'Сборка начата',
        ]);
    }

    /**
     * Упаковать отправление
     * POST /api/postings/{id}/pack
     */
    public function pack(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'products' => 'nullable|array',
            'products.*.product_id' => 'required_with:products|string',
            'products.*.quantity' => 'required_with:products|integer|min:1',
        ]);

        $posting = Posting::findOrFail($id);

        try {
            // Формируем products для API
            $products = $request->input('products', []);
            
            // Если products не переданы, берём из items
            if (empty($products)) {
                $products = $posting->items->map(fn($item) => [
                    'product_id' => (int) $item->marketplace_sku,
                    'quantity' => $item->quantity,
                ])->toArray();
            }

            $posting = $this->postingService->pack($posting, $products);

            return response()->json([
                'data' => $posting,
                'message' => 'Отправление упаковано',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Отгрузить отправление
     * POST /api/postings/{id}/ship
     */
    public function ship(string $id): JsonResponse
    {
        $posting = Posting::findOrFail($id);

        try {
            $posting = $this->postingService->ship($posting);

            return response()->json([
                'data' => $posting,
                'message' => 'Отправление отгружено',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Отменить отправление
     * POST /api/postings/{id}/cancel
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'cancel_reason_id' => 'required|integer',
            'cancel_reason_message' => 'nullable|string|max:1000',
        ]);

        $posting = Posting::findOrFail($id);

        try {
            $posting = $this->postingService->cancel(
                $posting,
                $request->input('cancel_reason_id'),
                $request->input('cancel_reason_message')
            );

            return response()->json([
                'data' => $posting,
                'message' => 'Отправление отменено',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить этикетку отправления
     * GET /api/postings/{id}/label
     */
    public function label(string $id): JsonResponse
    {
        $posting = Posting::findOrFail($id);

        try {
            $label = $this->postingService->getLabel($posting);

            return response()->json([
                'data' => $label,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка получения этикетки: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Массовое получение этикеток
     * POST /api/postings/bulk-labels
     */
    public function bulkLabels(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
            'posting_ids' => 'required|array|min:1',
            'posting_ids.*' => 'string',
        ]);

        try {
            $result = $this->postingService->getBulkLabels(
                $request->input('integration_id'),
                $request->input('posting_ids')
            );

            return response()->json([
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка получения этикеток: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Массовая отгрузка
     * POST /api/postings/bulk-ship
     */
    public function bulkShip(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
            'posting_ids' => 'required|array|min:1',
            'posting_ids.*' => 'string',
        ]);

        try {
            $result = $this->postingService->bulkShip(
                $request->input('integration_id'),
                $request->input('posting_ids')
            );

            return response()->json([
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка массовой отгрузки: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать акт приёма-передачи
     * POST /api/postings/act/create
     */
    public function createAct(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
            'departure_date' => 'required|date',
        ]);

        try {
            $result = $this->postingService->createAct(
                $request->input('integration_id'),
                $request->input('departure_date')
            );

            return response()->json([
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка создания акта: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Скачать акт приёма-передачи
     * GET /api/postings/act/{actId}/download
     */
    public function downloadAct(Request $request, int $actId): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
        ]);

        try {
            $result = $this->postingService->downloadAct(
                $request->input('integration_id'),
                $actId
            );

            return response()->json([
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка скачивания акта: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить причины отмены
     * GET /api/postings/cancel-reasons
     */
    public function cancelReasons(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
        ]);

        $integration = \App\Models\Integration::find($request->input('integration_id'));

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Интеграция не найдена или не Ozon'], 404);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $reasons = $marketplace->fbsPostings()->getCancelReasons();

            return response()->json(['data' => $reasons]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Получить список возвратов
     * GET /api/postings/returns
     */
    public function returns(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
            'status' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $integration = \App\Models\Integration::find($request->input('integration_id'));

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Интеграция не найдена или не Ozon'], 404);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            
            $filter = [];
            if ($request->filled('status')) {
                $filter['status'] = $request->input('status');
            }
            
            $limit = $request->input('per_page', 50);
            $offset = ($request->input('page', 1) - 1) * $limit;
            
            $result = $marketplace->fbsReturns()->list($filter, $limit, $offset);

            return response()->json([
                'data' => $result['returns'],
                'has_next' => $result['has_next'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
