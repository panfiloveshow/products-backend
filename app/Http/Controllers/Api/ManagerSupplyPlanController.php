<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerSupplyPlanReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerSupplyPlanController extends Controller
{
    public function __construct(private ManagerSupplyPlanReader $reader) {}

    public function index(Request $request): JsonResponse
    {
        return $this->read($request, false);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->read($request, true);
    }

    private function read(Request $request, bool $detail): JsonResponse
    {
        $allowed = ['integration_id', 'page', 'limit', ...($detail ? ['plan_id'] : [])];
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            return response()->json(['message' => 'Неподдерживаемые параметры чтения плана.'], 422);
        }
        $data = $request->validate(['integration_id' => 'required|integer|min:1',
            ...($detail ? ['plan_id' => 'required|uuid'] : []), 'page' => 'sometimes|integer|min:1|max:100000', 'limit' => 'sometimes|integer|min:1|max:50']);
        $identity = $request->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) return response()->json(['message' => 'Требуется подтверждённый доступ.'], 401);
        try {
            return response()->json($this->reader->read($identity, $request, (int) $data['integration_id'], $data['plan_id'] ?? null,
                (int) ($data['page'] ?? 1), (int) ($data['limit'] ?? 20)));
        } catch (ManagerDataException $error) {
            return response()->json(['message' => $error->getMessage()], $error->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Чтение плана временно недоступно.'], 503);
        }
    }
}
