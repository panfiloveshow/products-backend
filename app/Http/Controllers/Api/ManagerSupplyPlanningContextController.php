<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerSupplyPlanningContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerSupplyPlanningContextController extends Controller
{
    public function __construct(private ManagerSupplyPlanningContext $context) {}

    public function show(Request $request): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['integration_id', 'page', 'limit']) !== []) return response()->json(['message' => 'Неподдерживаемые параметры контекста.'], 422);
        $data = $request->validate(['integration_id' => 'required|integer|min:1', 'page' => 'sometimes|integer|min:1|max:100000', 'limit' => 'sometimes|integer|min:1|max:50']);
        $identity = $request->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) return response()->json(['message' => 'Требуется личная сессия.'], 401);
        try {
            return response()->json($this->context->read($identity, $request, (int) $data['integration_id'], (int) ($data['page'] ?? 1), (int) ($data['limit'] ?? 20)));
        } catch (ManagerDataException $error) {
            return response()->json(['message' => $error->getMessage()], $error->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Контекст планирования временно недоступен.'], 503);
        }
    }
}
