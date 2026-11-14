<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerOzonFboRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerOzonFboRecoveryController extends Controller
{
    public function __construct(private ManagerOzonFboRecoveryService $recovery) {}

    public function unknown(Request $r): JsonResponse
    {
        $data = $r->validate([
            'integration_id' => 'required|integer|min:1',
            'marketplace' => 'required|in:ozon',
            'cursor' => 'sometimes|nullable|string|max:40',
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->recovery->unknown(
                $identity,
                (int) $data['integration_id'],
                (string) $data['marketplace'],
                $data['cursor'] ?? null,
            ));
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось прочитать удерживаемые операции Ozon FBO.'], 503);
        }
    }

    public function prepareResolution(Request $r, string $operation): JsonResponse
    {
        $data = $r->validate([
            'integration_id' => 'required|integer|min:1',
            'marketplace' => 'required|in:ozon',
            'reason' => 'required|string|min:10|max:2000',
            'support_reference' => 'required|string|min:12|max:256',
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->recovery->prepareResolution(
                $identity,
                $operation,
                (int) $data['integration_id'],
                (string) $data['marketplace'],
                (string) $data['reason'],
                (string) $data['support_reference'],
            ));
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось подготовить ручной разбор Ozon FBO.'], 503);
        }
    }

    public function showResolution(Request $r, string $operation, string $resolution): JsonResponse
    {
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->recovery->getResolution($identity, $operation, $resolution));
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось прочитать ручной разбор Ozon FBO.'], 503);
        }
    }

    public function approveResolution(Request $r, string $operation, string $resolution): JsonResponse
    {
        $data = $r->validate([
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmations' => 'required|array',
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->recovery->approveResolution(
                $identity,
                $operation,
                $resolution,
                $data['fingerprint'],
                (array) $data['confirmations'],
            ));
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось завершить ручной разбор Ozon FBO.'], 503);
        }
    }

    public function cancelResolution(Request $r, string $operation, string $resolution): JsonResponse
    {
        $data = $r->validate([
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->recovery->cancelResolution(
                $identity,
                $operation,
                $resolution,
                $data['fingerprint'],
            ));
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось отменить ручной разбор Ozon FBO.'], 503);
        }
    }

    public function schemeHandoffContext(Request $r): JsonResponse
    {
        $data = $r->validate([
            'integration_id' => 'required|integer|min:1',
            'marketplace' => 'required|in:ozon',
            'plan_id' => 'required|uuid',
            'input_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'scheme_kind' => 'required|in:ozon_crossdock,ozon_dropoff_partner,ozon_partial_availability_fallback',
            'dropoff_point_id' => 'sometimes|nullable|string|max:120',
            'target_cluster_id' => 'sometimes|nullable|string|max:120',
        ]);
        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->recovery->schemeHandoffContext(
                $identity,
                (int) $data['integration_id'],
                (string) $data['marketplace'],
                (string) $data['plan_id'],
                (string) $data['input_version'],
                (string) $data['scheme_kind'],
                $data['dropoff_point_id'] ?? null,
                $data['target_cluster_id'] ?? null,
            ));
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось прочитать контекст непрямой схемы Ozon FBO.'], 503);
        }
    }
}
