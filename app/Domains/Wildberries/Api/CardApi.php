<?php

namespace App\Domains\Wildberries\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Публичный API карточек Wildberries (card.wb.ru).
 *
 * Используется для получения «витринного» СПП (скидки постоянного покупателя)
 * по артикулу (nmId): СПП% = (1 - product / sellerPrice) * 100, где
 * sellerPrice приходит из официального Prices API, а product — фактическая
 * витринная цена покупателя. Поле card.wb.ru `basic` — зачёркнутая цена до
 * скидки продавца, поэтому использовать его как базу СПП нельзя.
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
     * Цена покупателя на витрине (после СПП, без скидки WB Кошелька), руб., по nmId.
     * ponytail: цена из sizes[0] на всю карточку; per-size (optionId = chrtID) — если размеры разойдутся в цене.
     *
     * @param  array<int|string>  $nmIds
     * @return array<string,float> map [nmId => руб.]
     */
    public function getBuyerPricesByNmIds(array $nmIds): array
    {
        $result = [];

        foreach ($this->fetchPrices($nmIds) as $nmId => $price) {
            if (is_numeric($price['product'] ?? null)) {
                $result[$nmId] = round((float) $price['product'] / 100, 2);
            }
        }

        return $result;
    }

    /**
     * Получить витринный СПП по списку nmId.
     *
     * @param  array<int|string>  $nmIds
     * @return array<string,float> map [nmId => spp%]
     */
    public function getSppByNmIds(array $nmIds, array $sellerPricesByNmId = []): array
    {
        $result = [];

        foreach ($this->fetchPrices($nmIds) as $nmId => $price) {
            $basic = $price['basic'] ?? null;
            $buyer = $price['product'] ?? null;     // цена покупателя (после СПП), коп.
            $sellerPrice = $sellerPricesByNmId[$nmId] ?? null; // руб.

            if (! is_numeric($buyer)) {
                // Нет в наличии / нет цены — СПП недоступен.
                continue;
            }

            $baseRub = is_numeric($sellerPrice) && (float) $sellerPrice > 0
                ? (float) $sellerPrice
                : (is_numeric($basic) ? (float) $basic / 100 : 0.0);
            if ($baseRub <= 0) {
                continue;
            }

            $buyerRub = (float) $buyer / 100;
            $spp = round((1 - $buyerRub / $baseRub) * 100, 2);
            $result[$nmId] = max(0.0, $spp);
        }

        return $result;
    }

    /**
     * Блок sizes[0].price (в копейках) по nmId. Ошибки гасятся, nmId без цены пропускаются.
     *
     * @param  array<int|string>  $nmIds
     * @return array<string,array<string,mixed>>
     */
    private function fetchPrices(array $nmIds): array
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

                    // 403 обычно блокирует весь исходящий IP, а не конкретный nmId.
                    // Не отправляем ещё десятки заведомо бесполезных запросов.
                    if ($response->status() === 403) {
                        break;
                    }

                    continue;
                }

                foreach (($response->json('products') ?? []) as $product) {
                    $nmId = isset($product['id']) ? (string) $product['id'] : null;
                    $price = $product['sizes'][0]['price'] ?? null;
                    if ($nmId && is_array($price)) {
                        $result[$nmId] = $price;
                    }
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
