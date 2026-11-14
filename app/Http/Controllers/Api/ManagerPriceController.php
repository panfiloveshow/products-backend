<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerIdentity;
use App\Services\Manager\ManagerPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManagerPriceController extends Controller
{
    public function __construct(private ManagerPriceService $prices) {}

    public function context(Request $request): JsonResponse
    {
        return $this->run($request, false);
    }

    public function simulate(Request $request): JsonResponse
    {
        return $this->run($request, true);
    }

    private function run(Request $request, bool $simulate): JsonResponse
    {
        $data = $request->validate([
            'integration_id' => 'required|integer|min:1', 'product_id' => 'required|uuid',
            'fulfillment_type' => 'required|in:FBO,FBS,RFBS,EXPRESS,FBW,DBS,EDBS,DBW',
            ...($simulate ? ['seller_price' => ['required', 'string', 'max:20', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D']] : []),
            ...($simulate ? [
                'constraints' => ['sometimes', 'array:min_margin_percent,min_profit_rub,max_change_percent'],
                'constraints.min_margin_percent' => ['required_with:constraints', 'string', 'max:20'],
                'constraints.min_profit_rub' => ['required_with:constraints', 'string', 'max:20'],
                'constraints.max_change_percent' => ['required_with:constraints', 'string', 'max:20'],
            ] : []),
        ]);
        $identity = $request->attributes->get(ManagerIdentity::ATTRIBUTE);
        if (! $identity instanceof ManagerIdentity) {
            return response()->json(['message' => 'Требуется подтверждённый доступ.'], 401);
        }
        try {
            $arguments = [$identity, (int) $data['integration_id'], $data['product_id'], $data['fulfillment_type']];

            return response()->json($simulate ? $this->prices->simulate(...[...$arguments, $data['seller_price'], $data['constraints'] ?? null]) : $this->prices->context(...$arguments));
        } catch (ManagerDataException $error) {
            return response()->json(['message' => $error->getMessage()], $error->httpStatus);
        } catch (\Throwable) {
            return response()->json(['message' => 'Проверка источников цены временно недоступна.'], 503);
        }
    }
}
