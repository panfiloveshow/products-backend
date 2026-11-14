<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ManagerPromotionController extends Controller
{
    public function __construct(private ManagerPromotionService $promotions) {}

    public function index(Request $r): JsonResponse
    {
        $data = $r->validate([
            'integration_id' => 'required|integer|min:1',
            'marketplace' => 'required|in:wildberries,ozon',
            'status' => 'sometimes|nullable|string|max:40',
            'page' => 'sometimes|integer|min:1|max:1000',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->promotions->list(
                $identity,
                (int) $data['integration_id'],
                (string) $data['marketplace'],
                $data['status'] ?? null,
                (int) ($data['page'] ?? 1),
                (int) ($data['limit'] ?? 50),
            ));
        } catch (ManagerDataException $e) {
            return $this->error($e);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось прочитать список акций.'], 503);
        }
    }

    public function candidateContext(Request $r): JsonResponse
    {
        $data = $r->validate([
            'integration_id' => 'required|integer|min:1',
            'marketplace' => 'required|in:wildberries,ozon',
            'promotion_id' => 'required|string|max:80',
            'product_id' => 'required|uuid',
            'fulfillment_type' => 'required|in:FBO,FBS,RFBS,EXPRESS,DBS,EDBS,DBW',
            'action' => 'required|in:enter,exit',
            'target_promo_price' => ['required', 'string', 'max:20', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'],
            'min_margin_percent' => 'sometimes|string|max:20',
            'min_profit_rub' => 'sometimes|string|max:20',
            'max_change_percent' => 'sometimes|string|max:20',
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }

        $constraints = null;
        if (isset($data['min_margin_percent']) || isset($data['min_profit_rub']) || isset($data['max_change_percent'])) {
            $constraints = [
                'min_margin_percent' => $data['min_margin_percent'] ?? '',
                'min_profit_rub' => $data['min_profit_rub'] ?? '',
                'max_change_percent' => $data['max_change_percent'] ?? '',
            ];
        }

        try {
            $res = $this->promotions->candidateContext(
                $identity,
                (int) $data['integration_id'],
                (string) $data['marketplace'],
                trim((string) $data['promotion_id']),
                (string) $data['product_id'],
                (string) $data['fulfillment_type'],
                (string) $data['action'],
                (string) $data['target_promo_price'],
                $constraints,
                (string) $r->bearerToken(),
            );
            unset($res['_control_mode'], $res['_binding']);

            return response()->json($res);
        } catch (ManagerDataException $e) {
            return $this->error($e);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось рассчитать участие товара в акции.'], 503);
        }
    }

    public function prepare(Request $r): JsonResponse
    {
        $data = $r->validate([
            'integration_id' => 'required|integer|min:1',
            'marketplace' => 'required|in:wildberries,ozon',
            'promotion_id' => 'required|string|max:80',
            'product_id' => 'required|uuid',
            'fulfillment_type' => 'required|in:FBO,FBS,RFBS,EXPRESS,DBS,EDBS,DBW',
            'action' => 'required|in:enter,exit',
            'target_promo_price' => 'required|string|max:20',
            'context_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'constraints' => 'required|array',
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->promotions->prepare(
                $identity,
                (int) $data['integration_id'],
                (string) $data['marketplace'],
                trim((string) $data['promotion_id']),
                (string) $data['product_id'],
                (string) $data['fulfillment_type'],
                (string) $data['action'],
                (string) $data['target_promo_price'],
                (string) $data['context_fingerprint'],
                (array) $data['constraints'],
                (string) $r->bearerToken(),
            ));
        } catch (ManagerDataException $e) {
            return $this->error($e);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось подготовить предложение по акции.'], 503);
        }
    }

    public function show(Request $r, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Неверный ID предложения по акции.'], 422);
        }
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->promotions->get($identity, $id));
        } catch (ManagerDataException $e) {
            return $this->error($e);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось прочитать предложение по акции.'], 503);
        }
    }

    public function cancel(Request $r, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Неверный ID предложения по акции.'], 422);
        }
        $data = $r->validate([
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->promotions->cancel($identity, $id, $data['fingerprint']));
        } catch (ManagerDataException $e) {
            return $this->error($e);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось отменить предложение по акции.'], 503);
        }
    }

    public function approve(Request $r, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Неверный ID предложения по акции.'], 422);
        }
        $data = $r->validate([
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmations' => 'required|array',
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->promotions->approve(
                $identity,
                $id,
                $data['fingerprint'],
                (array) $data['confirmations'],
                (string) $r->bearerToken(),
            ));
        } catch (ManagerDataException $e) {
            return $this->error($e);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось применить предложение по акции.'], 503);
        }
    }

    private function error(ManagerDataException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            ...($e->reason === null ? [] : ['code' => $e->reason]),
        ], $e->httpStatus);
    }
}
