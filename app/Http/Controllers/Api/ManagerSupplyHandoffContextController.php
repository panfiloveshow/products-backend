<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerSupplyHandoffContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerSupplyHandoffContextController extends Controller
{
    public function read(Request $request, ManagerSupplyHandoffContext $context): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['integration_id', 'plan_id'])) {
            abort(422);
        }
        $data = $request->validate(['integration_id' => 'required|integer|min:1', 'plan_id' => 'required|uuid']);
        try {
            return response()->json($context->read($request->attributes->get(ManagerIdentity::ATTRIBUTE), (int) $data['integration_id'], $data['plan_id']), 200, ['Cache-Control' => 'private, no-store']);
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось подтвердить состав для передачи человеку.'], 503, ['Cache-Control' => 'private, no-store']);
        }
    }
}
