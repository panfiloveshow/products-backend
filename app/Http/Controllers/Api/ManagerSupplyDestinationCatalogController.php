<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerSupplyDestinationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerSupplyDestinationCatalogController extends Controller
{
    public function __construct(private ManagerSupplyDestinationCatalog $catalog) {}

    public function show(Request $request): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['integration_id', 'page', 'limit', 'snapshot_id', 'cluster_id'])) {
            return response()->json(['message' => 'Неподдерживаемые параметры справочника.'], 422);
        }
        $d = $request->validate(['integration_id' => 'required|integer|min:1', 'page' => 'sometimes|integer|min:1|max:100000',
            'limit' => 'sometimes|integer|min:1|max:50', 'snapshot_id' => 'sometimes|uuid', 'cluster_id' => 'sometimes|string|regex:/^[1-9][0-9]{0,18}$/D']);
        $who = $request->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $who instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->catalog->read($who, (int) $d['integration_id'], (int) ($d['page'] ?? 1), (int) ($d['limit'] ?? 20), $d['snapshot_id'] ?? null, $d['cluster_id'] ?? null));
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Справочник поставок недоступен. Начните новое чтение после проверки доступа.'], 503);
        }
    }
}
