<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerPriceProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ManagerPriceProposalController extends Controller
{
    public function __construct(private ManagerPriceProposalService $proposals) {}
    public function prepare(Request $r): JsonResponse { return $this->run($r, 'prepare'); }
    public function show(Request $r, string $id): JsonResponse { return $this->run($r, 'get', $id); }
    public function cancel(Request $r, string $id): JsonResponse { return $this->run($r, 'cancel', $id); }
    public function recheck(Request $r, string $id): JsonResponse { return $this->run($r, 'recheck', $id); }
    public function approve(Request $r, string $id): JsonResponse { return $this->run($r, 'approve', $id); }
    public function reconcile(Request $r, string $id): JsonResponse { return $this->run($r, 'reconcile', $id); }

    public function validateExecution(Request $r, string $id): JsonResponse
    {
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) return response()->json(['message' => 'Требуется личная сессия.'], 401);
        if (! Str::isUuid($id) || strlen($r->getContent()) > 65536) return response()->json(['message' => 'Неверная команда цены.'], 422);
        try {
            // Laravel's form normalization turns empty native strings into
            // null and trims them. Validate the exact executor JSON instead.
            $command = json_decode($r->getContent(), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($command) || array_is_list($command)) return response()->json(['message' => 'Нужна точная JSON-команда цены.'], 422);
            return response()->json($this->proposals->validateExecution($identity, $id, $command, (string) $r->bearerToken()));
        }
        catch (\JsonException) { return response()->json(['message' => 'Неверный JSON команды цены.'], 422); }
        catch (ManagerDataException $e) { return response()->json(['message' => $e->getMessage()], $e->httpStatus); }
        catch (\Throwable) { return response()->json(['message' => 'Финансовое согласование не подтверждено.'], 503); }
    }

    private function run(Request $r, string $method, ?string $id = null): JsonResponse
    {
        $allowed = $method === 'prepare' ? ['integration_id', 'product_id', 'fulfillment_type', 'seller_price', 'constraints'] : (in_array($method, ['get', 'reconcile'], true) ? [] : ['fingerprint', ...($method === 'approve' ? ['review_hash', 'confirmations'] : [])]);
        if (array_diff(array_keys($r->all()), $allowed) !== []) return response()->json(['message' => 'Неподдерживаемые параметры предложения цены.'], 422);
        $data = $r->validate($method === 'prepare' ? [
            'integration_id' => 'required|integer|min:1', 'product_id' => 'required|uuid', 'fulfillment_type' => 'required|string',
            'seller_price' => 'required|string', 'constraints' => 'required|array',
        ] : (in_array($method, ['get', 'reconcile'], true) ? [] : ['fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], ...($method === 'approve' ? [
            'review_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'confirmations' => 'required|array:financial_assumptions_checked,exact_price_and_limits_checked',
        ] : [])]));
        if ($id !== null && ! Str::isUuid($id)) return response()->json(['message' => 'Неверный ID предложения цены.'], 422);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) return response()->json(['message' => 'Требуется личная сессия.'], 401);
        try {
            return response()->json(match ($method) {
                'prepare' => $this->proposals->prepare($identity, (int) $data['integration_id'], $data['product_id'], $data['fulfillment_type'], $data['seller_price'], $data['constraints'], (string) $r->bearerToken()),
                'get' => $this->proposals->get($identity, $id),
                'cancel' => $this->proposals->cancel($identity, $id, $data['fingerprint']),
                'recheck' => $this->proposals->recheck($identity, $id, $data['fingerprint'], (string) $r->bearerToken()),
                'approve' => $this->proposals->approve($identity, $id, $data['fingerprint'], $data['review_hash'], $data['confirmations'], (string) $r->bearerToken()),
                'reconcile' => $this->proposals->reconcile($identity, $id, (string) $r->bearerToken()),
            });
        } catch (ManagerDataException $error) { return response()->json(['message' => $error->getMessage(), ...($error->reason === null ? [] : ['code' => $error->reason])], $error->httpStatus); }
        catch (\Throwable) { return response()->json(['message' => 'Не удалось проверить предложение цены. Прочитайте его состояние перед повторным действием.'], 503); }
    }
}
