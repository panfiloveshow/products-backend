<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerFbsOperationService;
use App\Services\Manager\ManagerIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerFbsOperationController extends Controller
{
    public function __construct(private ManagerFbsOperationService $operations) {}

    public function prepare(Request $request): JsonResponse
    {
        $this->keys($request, ['integration_id', 'external_id', 'operation_type', 'supply_id']);
        $data = $request->validate(['integration_id' => 'required|integer|min:1', 'external_id' => 'required|string|max:100', 'operation_type' => 'required|in:fbs_pack,fbs_assign', 'supply_id' => 'sometimes|string|min:1|max:80']);

        return $this->guard(fn () => $this->operations->prepare($this->identity($request), (int) $data['integration_id'], $data['external_id'], $data['operation_type'], $data['supply_id'] ?? null));
    }

    public function cancellationContext(Request $request): JsonResponse
    {
        $this->keys($request, ['integration_id', 'external_id', 'date_from', 'date_to']);
        $data = $request->validate(['integration_id' => 'required|integer|min:1', 'external_id' => 'required|string|max:100',
            'date_from' => 'sometimes|required_with:date_to|date_format:Y-m-d', 'date_to' => 'sometimes|required_with:date_from|date_format:Y-m-d']);
        $window = isset($data['date_from'], $data['date_to']) ? ['date_from' => $data['date_from'], 'date_to' => $data['date_to']] : null;
        return $this->guard(fn () => $this->operations->cancellationContext($this->identity($request), (int) $data['integration_id'], $data['external_id'], $window));
    }

    public function prepareCancellation(Request $request): JsonResponse
    {
        $this->keys($request, ['integration_id', 'external_id', 'context_fingerprint', 'reason_id', 'comment', 'lookup_window']);
        $data = $request->validate(['integration_id' => 'required|integer|min:1', 'external_id' => 'required|string|max:100',
            'context_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'reason_id' => 'present|nullable|string|regex:/^[1-9][0-9]{0,9}$/D',
            'comment' => 'required|string|max:500', 'lookup_window' => 'present|nullable|array:date_from,date_to',
            'lookup_window.date_from' => 'required_with:lookup_window|date_format:Y-m-d', 'lookup_window.date_to' => 'required_with:lookup_window|date_format:Y-m-d']);
        return $this->guard(fn () => $this->operations->prepare($this->identity($request), (int) $data['integration_id'], $data['external_id'], 'fbs_cancel', null,
            array_intersect_key($data, array_flip(['context_fingerprint', 'reason_id', 'comment', 'lookup_window']))));
    }

    public function show(Request $request, string $operation): JsonResponse
    {
        return $this->guard(fn () => $this->operations->expose($this->operations->get($this->identity($request), $operation)));
    }

    public function approve(Request $request, string $operation): JsonResponse
    {
        $this->keys($request, ['fingerprint', 'confirmations']);
        $data = $request->validate(['fingerprint' => 'required|string|size:64', 'confirmations' => 'sometimes|array:items_and_packing_checked,cancel_target_checked,cancel_impact_checked,not_handed_over_checked', 'confirmations.*' => 'boolean']);

        return $this->guard(fn () => $this->operations->approve($this->identity($request), $operation, $data['fingerprint'], $data['confirmations'] ?? [], fn () => app(\App\Services\Manager\ManagerSessionVerifier::class)->verify($request, ['auto_supply.view', 'integrations.view', 'auto_supply.create'])));
    }

    public function cancel(Request $request, string $operation): JsonResponse
    {
        $this->keys($request, ['fingerprint']);
        $data = $request->validate(['fingerprint' => 'required|string|size:64']);

        return $this->guard(fn () => $this->operations->cancel($this->identity($request), $operation, $data['fingerprint']));
    }

    public function reconcile(Request $request, string $operation): JsonResponse
    {
        $this->keys($request, []);

        return $this->guard(fn () => $this->operations->reconcile($this->identity($request), $operation));
    }

    private function identity(Request $request): ManagerIdentity
    {
        return $request->attributes->get(ManagerIdentity::ATTRIBUTE);
    }

    private function keys(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed)) {
            abort(422, 'В запросе есть неподдерживаемые поля.');
        }
    }

    private function guard(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (ManagerDataException $error) {
            return response()->json(['message' => $error->getMessage()], $error->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось получить состояние операции FBS. Перед повтором проверьте журнал.'], 503);
        }
    }
}
