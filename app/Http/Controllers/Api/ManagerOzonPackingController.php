<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerOzonPackingService;
use App\Services\Manager\ManagerSessionVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerOzonPackingController extends Controller
{
    public function __construct(private ManagerOzonPackingService $operations) {}
    public function context(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'postings']); $d = $r->validate(['integration_id' => 'required|integer|min:1', 'postings' => 'required|array|min:1|max:100']);
        return $this->guard(fn () => $this->operations->context($this->who($r), (int) $d['integration_id'], $d['postings'], fn () => $this->fresh($r)));
    }
    public function prepare(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'postings', 'context_fingerprint']); $d = $r->validate(['integration_id' => 'required|integer|min:1', 'postings' => 'required|array|min:1|max:100', 'context_fingerprint' => 'required|string|size:64']);
        return $this->guard(fn () => $this->operations->prepare($this->who($r), (int) $d['integration_id'], $d['postings'], $d['context_fingerprint'], fn () => $this->fresh($r, true)));
    }
    public function onePackageContext(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'posting_numbers']); $d = $r->validate(['integration_id' => 'required|integer|min:1', 'posting_numbers' => 'required|array|min:1|max:100']);
        return $this->guard(fn () => $this->operations->onePackageContext($this->who($r), (int) $d['integration_id'], $d['posting_numbers'], fn () => $this->fresh($r)));
    }
    public function prepareOnePackage(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'posting_numbers', 'context_fingerprint']); $d = $r->validate(['integration_id' => 'required|integer|min:1', 'posting_numbers' => 'required|array|min:1|max:100', 'context_fingerprint' => 'required|string|size:64']);
        return $this->guard(fn () => $this->operations->prepareOnePackage($this->who($r), (int) $d['integration_id'], $d['posting_numbers'], $d['context_fingerprint'], fn () => $this->fresh($r, true)));
    }
    public function show(Request $r, string $operation): JsonResponse { return $this->guard(fn () => $this->operations->get($this->who($r), $operation)); }
    public function approve(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), ['fingerprint', 'confirmations']); $d = $r->validate(['fingerprint' => 'required|string|size:64', 'confirmations' => 'required|array:'.implode(',', ManagerOzonPackingService::CHECKS)]);
        return $this->guard(fn () => $this->operations->approve($this->who($r), $operation, $d['fingerprint'], $d['confirmations'], (string) $r->bearerToken(), fn () => $this->fresh($r, true)));
    }
    public function cancel(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), ['fingerprint']); $d = $r->validate(['fingerprint' => 'required|string|size:64']);
        return $this->guard(fn () => $this->operations->cancel($this->who($r), $operation, $d['fingerprint']));
    }
    public function reconcile(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), []);
        return $this->guard(fn () => $this->operations->reconcile($this->who($r), $operation, fn () => $this->fresh($r)));
    }
    private function fresh(Request $r, bool $write = false): ManagerIdentity { return app(ManagerSessionVerifier::class)->verify($r, ['auto_supply.view', 'integrations.view', ...($write ? ['auto_supply.create'] : [])]); }
    private function who(Request $r): ManagerIdentity { return $r->attributes->get(ManagerIdentity::ATTRIBUTE); }
    private function keys(array $v, array $keys): void { if (array_diff(array_keys($v), $keys)) abort(400, 'Неожиданные параметры.'); }
    private function guard(callable $call): JsonResponse
    {
        try { return response()->json($call(), 200, ['Cache-Control' => 'private, no-store']); }
        catch (ManagerDataException $e) { return response()->json(['message' => $e->getMessage()], $e->httpStatus, ['Cache-Control' => 'private, no-store']); }
        catch (\Throwable) { return response()->json(['message' => 'Не удалось проверить групповую сборку Ozon.'], 503, ['Cache-Control' => 'private, no-store']); }
    }
}
