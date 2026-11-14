<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerFbsRecoveryService;
use App\Services\Manager\ManagerIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerFbsRecoveryController extends Controller
{
    public function __construct(private ManagerFbsRecoveryService $recovery) {}

    public function unknown(Request $r): JsonResponse
    {
        $data = $r->validate([
            'integration_id' => 'required|integer|min:1',
            'marketplace' => 'required|in:wildberries,ozon',
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
            return response()->json(['message' => 'Не удалось прочитать удерживаемые операции FBS.'], 503);
        }
    }

    public function prepareResolution(Request $r, string $operation): JsonResponse
    {
        $data = $r->validate([
            'integration_id' => 'required|integer|min:1',
            'marketplace' => 'required|in:wildberries,ozon',
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
            return response()->json(['message' => 'Не удалось подготовить ручной разбор FBS.'], 503);
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
            return response()->json(['message' => 'Не удалось прочитать ручной разбор FBS.'], 503);
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
            return response()->json(['message' => 'Не удалось завершить ручной разбор FBS.'], 503);
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
            return response()->json(['message' => 'Не удалось отменить ручной разбор FBS.'], 503);
        }
    }

    public function complexContext(Request $r): JsonResponse
    {
        $data = $r->validate([
            'integration_id' => 'required|integer|min:1',
            'marketplace' => 'required|in:wildberries,ozon',
            'posting_ids' => 'required|string|max:5500',
        ]);
        $postingIds = array_values(array_filter(array_map('trim', explode(',', (string) $data['posting_ids']))));
        if (count($postingIds) < 1 || count($postingIds) > 50) {
            return response()->json(['message' => 'Укажите от 1 до 50 номеров отправлений FBS.'], 422);
        }

        $identity = $r->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется личная сессия.'], 401);
        }
        try {
            return response()->json($this->recovery->complexContext(
                $identity,
                (int) $data['integration_id'],
                (string) $data['marketplace'],
                $postingIds,
            ));
        } catch (ManagerDataException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось прочитать контекст сложных отправлений FBS.'], 503);
        }
    }
}
