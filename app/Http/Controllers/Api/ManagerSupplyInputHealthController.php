<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerSupplyInputHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerSupplyInputHealthController extends Controller
{
    public function __construct(private ManagerSupplyInputHealthService $health) {}

    public function read(Request $request): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['integration_id', 'plan_id', 'page', 'limit'])) {
            abort(422);
        }
        $data = $request->validate(['integration_id' => 'required|integer|min:1', 'plan_id' => 'nullable|uuid', 'page' => 'nullable|integer|min:1|max:100000', 'limit' => 'nullable|integer|min:1|max:100']);
        try {
            return response()->json($this->health->read($request->attributes->get(ManagerIdentity::ATTRIBUTE), (int) $data['integration_id'], $data['plan_id'] ?? null,
                (int) ($data['page'] ?? 1), (int) ($data['limit'] ?? 50)), 200, ['Cache-Control' => 'private, no-store']);
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось подтвердить состояние исходных данных.'], 503, ['Cache-Control' => 'private, no-store']);
        }
    }
}
