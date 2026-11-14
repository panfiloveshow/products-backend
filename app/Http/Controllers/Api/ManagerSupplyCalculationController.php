<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerSupplyCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerSupplyCalculationController extends Controller
{
    public function __construct(private ManagerSupplyCalculationService $operations) {}

    public function prepare(Request $request): JsonResponse { return $this->run($request, 'prepare'); }
    public function show(Request $request, string $id): JsonResponse { return $this->run($request, 'get', $id); }
    public function approve(Request $request, string $id): JsonResponse { return $this->run($request, 'approve', $id); }
    public function cancel(Request $request, string $id): JsonResponse { return $this->run($request, 'cancel', $id); }

    private function run(Request $request, string $method, ?string $id = null): JsonResponse
    {
        $allowed = $method === 'prepare' ? ['integration_id', 'parameters'] : ($method === 'get' ? [] : ['fingerprint']);
        if (array_diff(array_keys($request->all()), $allowed) !== []) return response()->json(['message' => 'Неподдерживаемые параметры запроса расчёта.'], 422);
        $data = $request->validate($method === 'prepare'
            ? ['integration_id' => 'required|integer|min:1', 'parameters' => 'required|array']
            : ($method === 'get' ? [] : ['fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]));
        if ($id !== null && ! \Illuminate\Support\Str::isUuid($id)) return response()->json(['message' => 'Неверный ID запроса.'], 422);
        $identity = $request->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) return response()->json(['message' => 'Требуется личная сессия.'], 401);
        try {
            $result = match ($method) {
                'prepare' => $this->operations->prepare($identity, (int) $data['integration_id'], $data['parameters']),
                'get' => $this->operations->get($identity, $id),
                'approve' => $this->operations->approve($identity, $id, $data['fingerprint']),
                'cancel' => $this->operations->cancel($identity, $id, $data['fingerprint']),
            };

            return response()->json($result);
        } catch (ManagerDataException $error) {
            return response()->json(['message' => $error->getMessage()], $error->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось подтвердить состояние расчёта. Проверьте сохранённый запрос перед новым запуском.'], 503);
        }
    }
}
