<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerFbsExecutionControlService;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerSessionVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerFbsExecutionControlController extends Controller
{
    public function __construct(private ManagerFbsExecutionControlService $controls) {}

    public function context(Request $request): JsonResponse
    {
        $this->keys($request, ['integration_id']);
        $data = $request->validate(['integration_id' => 'required|integer|min:1']);

        return $this->guard(fn () => $this->controls->context($this->who($request), (int) $data['integration_id']));
    }

    public function prepare(Request $request): JsonResponse
    {
        $this->keys($request, ['integration_id', 'context_fingerprint', 'target_mode', 'reason']);
        $d = $request->validate(['integration_id' => 'required|integer|min:1', 'context_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'target_mode' => 'required|in:stopped,manual_only', 'reason' => 'required|string|min:10|max:2000']);

        return $this->guard(fn () => $this->controls->prepare($this->who($request), (int) $d['integration_id'], $d['context_fingerprint'], $d['target_mode'], $d['reason']));
    }

    public function show(Request $request, string $operation): JsonResponse
    {
        $this->keys($request, []);

        return $this->guard(fn () => $this->controls->expose($this->who($request), $this->controls->get($this->who($request), $operation)));
    }

    public function approve(Request $request, string $operation): JsonResponse
    {
        $this->keys($request, ['fingerprint', 'confirmations']);
        $d = $request->validate(['fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'confirmations' => 'required|array:fbs_scope_checked,fbs_effect_checked,fbs_reopening_checked', 'confirmations.*' => 'boolean']);

        return $this->guard(fn () => $this->controls->approve($this->who($request), $operation, $d['fingerprint'], $d['confirmations'],
            fn () => app(ManagerSessionVerifier::class)->verify($request, ['auto_supply.view', 'integrations.view', 'auto_supply.create'])));
    }

    public function cancel(Request $request, string $operation): JsonResponse
    {
        $this->keys($request, ['fingerprint']);
        $d = $request->validate(['fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/D']);

        return $this->guard(fn () => $this->controls->cancel($this->who($request), $operation, $d['fingerprint']));
    }

    private function who(Request $request): ManagerIdentity
    {
        return $request->attributes->get(ManagerIdentity::ATTRIBUTE);
    }

    private function keys(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), $keys)) {
            abort(422, 'Неподдерживаемые поля ограничения FBS.');
        }
    }

    private function guard(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось подтвердить решение FBS. Прочитайте личный журнал перед дальнейшим действием.'], 503);
        }
    }
}
