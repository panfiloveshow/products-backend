<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerOzonFboPlan;
use App\Services\Manager\ManagerOzonFboService;
use App\Services\Manager\ManagerSessionVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerOzonFboController extends Controller
{
    public function __construct(private ManagerOzonFboService $operations) {}

    public function context(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'selection']);
        $d = $r->validate($this->rules());

        return $this->guard(fn () => $this->operations->context($this->who($r), (int) $d['integration_id'], $d['selection']));
    }

    public function prepare(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'selection', 'context_fingerprint']);
        $d = $r->validate($this->rules() + ['context_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/D']);

        return $this->guard(fn () => $this->operations->prepare($this->who($r), (int) $d['integration_id'], $d['selection'], $d['context_fingerprint']));
    }

    public function show(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), []);

        return $this->guard(fn () => $this->operations->expose($this->operations->get($this->who($r), $operation)));
    }

    public function availability(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), []);

        return $this->guard(fn () => $this->operations->availability($this->who($r), $operation));
    }

    public function slots(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), ['warehouse_id', 'date_from', 'date_to']);
        $d = $r->validate(['warehouse_id' => 'required|string|regex:/^[1-9][0-9]{0,18}$/D', 'date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d']);

        return $this->guard(fn () => $this->operations->slots($this->who($r), $operation, $d['warehouse_id'], $d['date_from'], $d['date_to']));
    }

    public function approve(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), ['fingerprint', 'confirmations']);
        $d = $r->validate(['fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'confirmations' => 'required|array:'.implode(',', ManagerOzonFboService::CHECKS)]);

        return $this->guard(fn () => $this->operations->approve($this->who($r), $operation, $d['fingerprint'], $d['confirmations'],
            fn () => app(ManagerSessionVerifier::class)->verify($r, ['auto_supply.view', 'integrations.view', 'auto_supply.create'])));
    }

    public function cancel(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), ['fingerprint']);
        $d = $r->validate(['fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/D']);

        return $this->guard(fn () => $this->operations->cancel($this->who($r), $operation, $d['fingerprint']));
    }

    public function reconcile(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), []);

        return $this->guard(fn () => $this->operations->reconcile($this->who($r), $operation));
    }

    private function rules(): array
    {
        $r = ['integration_id' => 'required|integer|min:1', 'selection' => 'required|array:'.implode(',', ManagerOzonFboPlan::KEYS).'|size:8',
            'selection.operation_type' => 'required|in:fbo_ozon_draft_create,fbo_ozon_supply_create', 'selection.plan_id' => 'present|nullable|uuid',
            'selection.draft_operation_id' => 'present|nullable|uuid', 'selection.slot_key' => 'present|nullable|string|regex:/^[a-f0-9]{64}$/D'];
        foreach (['native_cluster_id', 'warehouse_id'] as $k) {
            $r['selection.'.$k] = 'present|nullable|string|regex:/^[1-9][0-9]{0,18}$/D';
        }
        foreach (['date_from', 'date_to'] as $k) {
            $r['selection.'.$k] = 'present|nullable|date_format:Y-m-d';
        }

        return $r;
    }

    private function who(Request $r): ManagerIdentity
    {
        return $r->attributes->get(ManagerIdentity::ATTRIBUTE);
    }

    private function keys(array $data, array $keys): void
    {
        if (array_diff(array_keys($data), $keys)) {
            abort(422);
        }
    }

    private function guard(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback(), 200, ['Cache-Control' => 'private, no-store']);
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            return response()->json(['message' => 'Журнал FBO временно недоступен. Перед новым согласованием проверьте результат.'], 503, ['Cache-Control' => 'private, no-store']);
        }
    }
}
