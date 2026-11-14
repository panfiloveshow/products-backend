<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerFinancialSettingsService;
use App\Services\Manager\ManagerIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerFinancialSettingsController extends Controller
{
    public function __construct(private ManagerFinancialSettingsService $settings) {}
    public function prepare(Request $r): JsonResponse { return $this->run($r, 'prepare'); }
    public function show(Request $r, string $id): JsonResponse { return $this->run($r, 'get', $id); }
    public function approve(Request $r, string $id): JsonResponse { return $this->run($r, 'approve', $id); }
    public function cancel(Request $r, string $id): JsonResponse { return $this->run($r, 'cancel', $id); }

    private function run(Request $r, string $method, ?string $id = null): JsonResponse
    {
        $allowed = $method === 'prepare' ? ['integration_id', 'product_id', 'values'] : ($method === 'get' ? [] : ['fingerprint', ...($method === 'approve' ? ['confirmations'] : [])]);
        if (array_diff(array_keys($r->all()), $allowed) !== []) return response()->json(['message' => 'Неподдерживаемые параметры финансовых данных.'], 422);
        $data = $r->validate($method === 'prepare' ? ['integration_id' => 'required|integer|min:1', 'product_id' => 'required|uuid', 'values' => 'required|array|min:1']
            : ($method === 'get' ? [] : ['fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], ...($method === 'approve' ? [
                'confirmations' => 'required|array:financial_inputs_checked', 'confirmations.financial_inputs_checked' => 'required|accepted',
            ] : [])]));
        if ($method === 'approve' && ($data['confirmations']['financial_inputs_checked'] ?? null) !== true) return response()->json(['message' => 'Подтвердите данные продавца.'], 422);
        if ($id !== null && ! \Illuminate\Support\Str::isUuid($id)) return response()->json(['message' => 'Неверный ID предложения.'], 422);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) return response()->json(['message' => 'Требуется личная сессия.'], 401);
        try {
            $result = match ($method) {
                'prepare' => $this->settings->prepare($identity, (int) $data['integration_id'], $data['product_id'], $data['values']),
                'get' => $this->settings->get($identity, $id),
                'approve' => $this->settings->approve($identity, $id, $data['fingerprint']),
                'cancel' => $this->settings->cancel($identity, $id, $data['fingerprint']),
            };

            return response()->json($result);
        } catch (ManagerDataException $error) { return response()->json(['message' => $error->getMessage()], $error->httpStatus); }
        catch (\Throwable) { return response()->json(['message' => 'Не удалось подтвердить сохранение финансовых данных. Проверьте прежнее предложение.'], 503); }
    }
}
