<?php

namespace App\Domains\Wildberries\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Публичный API карточек Wildberries (card.wb.ru).
 *
 * Используется для получения «витринного» СПП (скидки постоянного покупателя)
 * по артикулу (nmId): СПП% = (1 - product / basic) * 100, где
 *  - basic   — цена после скидки продавца (зачёркнутая),
 *  - product — цена для покупателя (после СПП).
 *
 * Эндпоинт неофициальный и без авторизации. Цена возвращается только для товаров
 * в наличии. Все ошибки гасятся (возвращаем пустой результат / пропускаем nmId),
 * чтобы не ломать синхронизацию юнит-экономики.
 */
class CardApi
{
    private const ENDPOINT = 'https://card.wb.ru/cards/v4/detail';

    /** Гео (пункт доставки) — влияет на доступность цены. РФ. */
    private const DEST = -1257786;

    /** Сколько nmId передаём в одном запросе. */
    private const CHUNK = 100;

    /** Таймаут одного запроса, сек. */
    private const TIMEOUT = 8;

    /**
     * Получить витринный СПП по списку nmId.
     *
     * @param  array<int|string>  $nmIds
     * @return array<string,float>  map [nmId => spp%]
     */
    public function getSppByNmIds(array $nmIds): array
    {
        $nmIds = array_values(array_unique(array_filter(
            array_map('strval', $nmIds),
            static fn ($v) => $v !== '' && $v !== '0'
        )));

        if (empty($nmIds)) {
            return [];
        }

        $result = [];

        foreach (array_chunk($nmIds, self::CHUNK) as $chunk) {
            try {
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders(['Accept' => '*/*'])
                    ->get(self::ENDPOINT, [
                        'appType' => 1,
                        'curr' => 'rub',
                        'dest' => self::DEST,
                        'nm' => implode(';', $chunk),
                    ]);

                if (! $response->ok()) {
                    Log::warning('WB CardApi: non-OK response', [
                        'status' => $response->status(),
                        'count' => count($chunk),
                    ]);

                    continue;
                }

                foreach (($response->json('products') ?? []) as $product) {
                    $nmId = isset($product['id']) ? (string) $product['id'] : null;
                    if (! $nmId) {
                        continue;
                    }

                    // Цена лежит в sizes[0].price (в копейках).
                    $price = $product['sizes'][0]['price'] ?? null;
                    $basic = $price['basic'] ?? null;       // после скидки продавца
                    $buyer = $price['product'] ?? null;     // цена покупателя (после СПП)

                    if (! is_numeric($basic) || ! is_numeric($buyer) || (float) $basic <= 0) {
                        // Нет в наличии / нет цены — СПП недоступен.
                        continue;
                    }

                    $spp = round((1 - (float) $buyer / (float) $basic) * 100, 2);
                    $result[$nmId] = max(0.0, $spp);
                }
            } catch (\Throwable $e) {
                Log::warning('WB CardApi: request failed', [
                    'error' => $e->getMessage(),
                    'count' => count($chunk),
                ]);

                continue;
            }
        }

        return $result;
    }
}
