<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerFbsQueueService;
use App\Services\Manager\ManagerIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerFbsController extends Controller
{
    public function __construct(private ManagerFbsQueueService $queue) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['integration_id' => 'required|integer|min:1', 'limit' => 'nullable|integer|min:1|max:100', 'cursor' => 'nullable|string|max:8000']);
        $identity = $request->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->queue->read($identity, (int) $data['integration_id'], isset($data['limit']) ? (int) $data['limit'] : null, $data['cursor'] ?? null));
        } catch (ManagerDataException $error) {
            return response()->json(['message' => $error->getMessage()], $error->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Очередь сборки временно недоступна.'], 503);
        }
    }
}
