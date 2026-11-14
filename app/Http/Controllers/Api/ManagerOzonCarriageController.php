<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerOzonCarriageService;
use App\Services\Manager\ManagerSessionVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerOzonCarriageController extends Controller
{
    public function __construct(private ManagerOzonCarriageService $operations) {}
    public function methods(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'departure_date']);
        $d = $r->validate(['integration_id' => 'required|integer|min:1', 'departure_date' => 'required|date_format:Y-m-d']);
        return $this->guard(fn () => $this->operations->methods($this->who($r), (int) $d['integration_id'], $d['departure_date']));
    }
    public function context(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', ...$this->selectionKeys()]);
        $d = $r->validate(['integration_id' => 'required|integer|min:1', ...$this->rules()]);
        return $this->guard(fn () => $this->operations->context($this->who($r), (int) $d['integration_id'], $this->selection($d)));
    }
    public function prepare(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'selection', 'context_fingerprint']);
        $rules = []; foreach ($this->rules() as $key => $rule) $rules['selection.'.$key] = $rule;
        $d = $r->validate(['integration_id' => 'required|integer|min:1', 'context_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'selection' => 'required|array:'.implode(',', $this->selectionKeys()), ...$rules]);
        if (count($d['selection']) !== 6) abort(422);
        return $this->guard(fn () => $this->operations->prepare($this->who($r), (int) $d['integration_id'], $this->selection($d['selection']), $d['context_fingerprint']));
    }
    public function show(Request $r, string $operation): JsonResponse { return $this->guard(fn () => $this->operations->expose($this->operations->get($this->who($r), $operation))); }
    public function approve(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), ['fingerprint', 'confirmations']);
        $d = $r->validate(['fingerprint' => 'required|string|size:64', 'confirmations' => 'required|array:'.implode(',', ManagerOzonCarriageService::CHECKS)]);
        return $this->guard(fn () => $this->operations->approve($this->who($r), $operation, $d['fingerprint'], $d['confirmations'],
            fn () => app(ManagerSessionVerifier::class)->verify($r, ['auto_supply.view', 'integrations.view', 'auto_supply.create'])));
    }
    public function cancel(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), ['fingerprint']); $d = $r->validate(['fingerprint' => 'required|string|size:64']);
        return $this->guard(fn () => $this->operations->cancel($this->who($r), $operation, $d['fingerprint']));
    }
    public function reconcile(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), []); return $this->guard(fn () => $this->operations->reconcile($this->who($r), $operation));
    }
    private function selectionKeys(): array { return ['operation_type', 'delivery_method_id', 'departure_at', 'all_blr_traceable', 'carriage_id', 'containers_count']; }
    private function rules(): array
    {
        return ['operation_type' => 'required|in:fbs_carriage_create,fbs_carriage_approve', 'delivery_method_id' => 'nullable|string|regex:/^[1-9][0-9]{0,18}$/D',
            'departure_at' => 'nullable|string|max:35', 'all_blr_traceable' => 'nullable|boolean', 'carriage_id' => 'nullable|string|regex:/^[1-9][0-9]{0,18}$/D',
            'containers_count' => 'nullable|integer|min:1|max:2147483647'];
    }
    private function selection(array $d): array
    {
        return ['operation_type' => $d['operation_type'], 'delivery_method_id' => $d['delivery_method_id'] ?? null, 'departure_at' => $d['departure_at'] ?? null,
            'all_blr_traceable' => isset($d['all_blr_traceable']) ? (bool) $d['all_blr_traceable'] : null,
            'carriage_id' => $d['carriage_id'] ?? null, 'containers_count' => isset($d['containers_count']) ? (int) $d['containers_count'] : null];
    }
    private function who(Request $r): ManagerIdentity { return $r->attributes->get(ManagerIdentity::ATTRIBUTE); }
    private function keys(array $input, array $keys): void { if (array_diff(array_keys($input), $keys)) abort(422); }
    private function guard(callable $callback): JsonResponse
    {
        try { return response()->json($callback(), 200, ['Cache-Control' => 'private, no-store']); }
        catch (ManagerDataException $e) { return response()->json(['message' => $e->getMessage()], $e->httpStatus, ['Cache-Control' => 'private, no-store']); }
        catch (\Throwable) { return response()->json(['message' => 'Журнал перевозки временно недоступен. Перед повтором проверьте результат.'], 503, ['Cache-Control' => 'private, no-store']); }
    }
}
