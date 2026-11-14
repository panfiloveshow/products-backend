<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerSessionVerifier;
use App\Services\Manager\ManagerWbSupplyGateway;
use App\Services\Manager\ManagerWbSupplyService;
use App\Services\Manager\ManagerWbSupplyDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerWbSupplyController extends Controller
{
    public function __construct(private ManagerWbSupplyService $operations, private ManagerWbSupplyDocumentService $documents) {}
    public function documentContext(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'supply_id', 'document_kind', 'box_id']);
        $d = $r->validate(['integration_id' => 'required|integer|min:1', 'supply_id' => 'required|string|max:80', 'document_kind' => 'required|in:supply_qr,box_label', 'box_id' => 'present|nullable|string|max:80']);
        return $this->guard(fn () => $this->documents->prepare($this->who($r), (int) $d['integration_id'], $d['supply_id'], $d['document_kind'], $d['box_id']));
    }
    public function downloadDocument(Request $r)
    {
        $this->keys($r->all(), ['download_ticket']); $d = $r->validate(['download_ticket' => 'required|string|max:8000']);
        try {
            $file = $this->documents->download($this->who($r), $d['download_ticket'], fn () => app(ManagerSessionVerifier::class)->verify($r, ['auto_supply.view', 'integrations.view', 'auto_supply.export']));
            return response($file['bytes'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (ManagerDataException $e) { return response()->json(['message' => $e->getMessage()], $e->httpStatus, ['Cache-Control' => 'private, no-store']); }
        catch (\Throwable) { return response()->json(['message' => 'Не удалось проверить документ WB.'], 503, ['Cache-Control' => 'private, no-store']); }
    }
    public function listing(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'cursor']);
        $d = $r->validate(['integration_id' => 'required|integer|min:1', 'cursor' => 'nullable|string|regex:/^(0|[1-9][0-9]{0,18})$/D']);
        return $this->guard(fn () => $this->operations->listing($this->who($r), (int) $d['integration_id'], $d['cursor'] ?? '0'));
    }
    public function points(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'supply_id', 'city', 'office_types', 'shipping_date']);
        $d = $r->validate(['integration_id' => 'required|integer|min:1', 'supply_id' => 'required|string|max:80', 'city' => 'required|string|max:200',
            'office_types' => 'required|array|min:1|max:5', 'office_types.*' => 'required|string|in:sc,sw,pp,pfc,gpp|distinct', 'shipping_date' => 'required|date_format:Y-m-d']);
        return $this->guard(fn () => $this->operations->points($this->who($r), (int) $d['integration_id'], $d['supply_id'], $d['city'], $d['office_types'], $d['shipping_date']));
    }
    public function context(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'selection']); $d = $r->validate($this->rules());
        return $this->guard(fn () => $this->operations->context($this->who($r), (int) $d['integration_id'], $d['selection']));
    }
    public function prepare(Request $r): JsonResponse
    {
        $this->keys($r->all(), ['integration_id', 'selection', 'context_fingerprint']);
        $d = $r->validate($this->rules() + ['context_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/D']);
        return $this->guard(fn () => $this->operations->prepare($this->who($r), (int) $d['integration_id'], $d['selection'], $d['context_fingerprint']));
    }
    public function show(Request $r, string $operation): JsonResponse { return $this->guard(fn () => $this->operations->expose($this->operations->get($this->who($r), $operation))); }
    public function approve(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), ['fingerprint', 'confirmations']); $d = $r->validate(['fingerprint' => 'required|string|size:64', 'confirmations' => 'required|array:'.implode(',', ManagerWbSupplyService::CHECKS)]);
        return $this->guard(fn () => $this->operations->approve($this->who($r), $operation, $d['fingerprint'], $d['confirmations'],
            fn () => app(ManagerSessionVerifier::class)->verify($r, ['auto_supply.view', 'integrations.view', 'auto_supply.create'])));
    }
    public function cancel(Request $r, string $operation): JsonResponse
    {
        $this->keys($r->all(), ['fingerprint']); $d = $r->validate(['fingerprint' => 'required|string|size:64']);
        return $this->guard(fn () => $this->operations->cancel($this->who($r), $operation, $d['fingerprint']));
    }
    public function reconcile(Request $r, string $operation): JsonResponse { $this->keys($r->all(), []); return $this->guard(fn () => $this->operations->reconcile($this->who($r), $operation)); }
    private function rules(): array { return ['integration_id' => 'required|integer|min:1', 'selection' => 'required|array:'.implode(',', ManagerWbSupplyGateway::KEYS).'|size:12']; }
    private function who(Request $r): ManagerIdentity { return $r->attributes->get(ManagerIdentity::ATTRIBUTE); }
    private function keys(array $data, array $keys): void { if (array_diff(array_keys($data), $keys)) abort(422); }
    private function guard(callable $fn): JsonResponse
    {
        try { return response()->json($fn(), 200, ['Cache-Control' => 'private, no-store']); }
        catch (ManagerDataException $e) { return response()->json(['message' => $e->getMessage()], $e->httpStatus, ['Cache-Control' => 'private, no-store']); }
        catch (\Throwable) { return response()->json(['message' => 'Журнал поставок WB временно недоступен. Перед повтором проверьте результат.'], 503, ['Cache-Control' => 'private, no-store']); }
    }
}
