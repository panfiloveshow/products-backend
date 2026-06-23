<?php

namespace App\Domains\Uzum\Api;

/**
 * Финансовые эндпоинты Uzum Seller OpenAPI.
 *
 * Главное: /v1/finance/orders отдаёт фактический per-SKU разбор —
 * commission, logisticDeliveryFee (логистический сбор), purchasePrice,
 * sellerProfit. Это авторитетный источник логистики (в продуктовом эндпоинте
 * её нет, габариты приходят null).
 */
class FinanceApi
{
    public function __construct(private readonly UzumClient $client)
    {
    }

    /**
     * Агрегировать финансы по productId за период.
     *
     * В finance-строке нет skuId — только productId + skuTitle, поэтому
     * агрегируем по productId (у НБК один SKU на карточку — точно; для
     * мульти-SKU карточек логистика усредняется по продукту).
     *
     * @return array<int, array{units:int, returns:int, commission:float, logistics:float, cost:float, seller_profit:float, logistics_per_unit:float}>
     *   ключ — productId
     */
    public function aggregateOrdersByProduct(int $shopId, int $dateFromMs, int $dateToMs): array
    {
        $acc = [];
        $page = 0;
        $size = 100;
        $guard = 0;

        do {
            $resp = $this->client->get('/v1/finance/orders', [
                'shopIds' => [$shopId],
                'dateFrom' => $dateFromMs,
                'dateTo' => $dateToMs,
                'page' => $page,
                'size' => $size,
                'group' => 'false',
            ]);

            $payload = $resp['data'] ?? $resp ?? [];
            $items = $payload['orderItems'] ?? [];

            foreach ($items as $it) {
                $pid = (int) ($it['productId'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $a = &$acc[$pid];
                $a['units'] = ($a['units'] ?? 0) + (int) ($it['amount'] ?? 0);
                $a['returns'] = ($a['returns'] ?? 0) + (int) ($it['amountReturns'] ?? 0);
                $a['commission'] = ($a['commission'] ?? 0) + (float) ($it['commission'] ?? 0);
                $a['logistics'] = ($a['logistics'] ?? 0) + (float) ($it['logisticDeliveryFee'] ?? 0);
                $a['cost'] = ($a['cost'] ?? 0) + (float) ($it['purchasePrice'] ?? 0) * (int) ($it['amount'] ?? 0);
                $a['seller_profit'] = ($a['seller_profit'] ?? 0) + (float) ($it['sellerProfit'] ?? 0);
                unset($a);
            }

            $page++;
            $guard++;
            $fetched = count($items);
        } while ($fetched >= $size && $guard < 200);

        // Производные per-unit показатели
        foreach ($acc as $pid => $v) {
            $units = max(1, (int) ($v['units'] ?? 0));
            $acc[$pid]['logistics_per_unit'] = round(($v['logistics'] ?? 0) / $units, 2);
            $acc[$pid]['cost_per_unit'] = round(($v['cost'] ?? 0) / $units, 2);
        }

        return $acc;
    }
}
