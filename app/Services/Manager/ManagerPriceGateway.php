<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Product;
use Illuminate\Support\Facades\Http;

/** Read-only vendor snapshot. Keeps sizes, club price and promotion context. */
final class ManagerPriceGateway
{
    public function snapshot(ManagerStore $store, Product $product): array
    {
        try {
            return $store->marketplace === 'wildberries' ? $this->wb($store, $product) : $this->ozon($store, $product);
        } catch (ManagerDataException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ManagerDataException('Не удалось прочитать текущие цены выбранного товара.');
        }
    }

    private function wb(ManagerStore $store, Product $product): array
    {
        $id = (string) ($product->wb_data['nmID'] ?? $product->wb_data['nmId'] ?? '');
        if (! preg_match('/^[1-9][0-9]{0,15}$/D', $id)) {
            throw new ManagerDataException('Не подтверждён nmID товара WB.', 422);
        }
        $response = Http::withHeaders(['Authorization' => $store->apiKey])->acceptJson()->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false])
            ->get('https://discounts-prices-api.wildberries.ru/api/v2/list/goods/filter', ['filterNmID' => $id, 'limit' => 100, 'offset' => 0]);
        $items = $response->json('data.listGoods');
        if (! $response->successful() || $response->json('error') !== false || ! is_array($items) || count($items) !== 1 || (string) ($items[0]['nmID'] ?? '') !== $id) {
            throw new ManagerDataException('WB не подтвердил цену именно выбранного товара.');
        }
        $item = $items[0];
        // Associative JSON decoding collapses {} and [] into the same PHP
        // value. Only an explicit JSON list confirms wholesale conditions.
        $wire = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        $wholesaleComplete = is_array($wire->data->listGoods[0]->wholesaleDiscountThreshold ?? null);
        if (($item['currencyIsoCode4217'] ?? '') !== 'RUB' || ! is_int($item['discount'] ?? null) || $item['discount'] < 0 || $item['discount'] > 99
            || ! is_int($item['clubDiscount'] ?? null) || $item['clubDiscount'] < 0 || $item['clubDiscount'] > 100
            || ! is_bool($item['editableSizePrice'] ?? null) || ! is_array($item['sizes'] ?? null) || ! $item['sizes']) {
            throw new ManagerDataException('Неполный снимок валюты, скидок или размеров WB.');
        }
        $sizes = [];
        $ids = [];
        foreach ($item['sizes'] as $size) {
            $sizeId = (string) ($size['sizeID'] ?? '');
            if (! preg_match('/^[1-9][0-9]{0,18}$/D', $sizeId) || isset($ids[$sizeId])) {
                throw new ManagerDataException('Непроверенные размеры WB.');
            }
            $ids[$sizeId] = true;
            $sizes[] = ['size_id' => $sizeId, 'name' => (string) ($size['techSizeName'] ?? ''),
                'base_price' => DecimalMoney::normalize($size['price'] ?? null),
                'seller_price' => DecimalMoney::normalize($size['discountedPrice'] ?? null),
                'club_price' => DecimalMoney::normalize($size['clubDiscountedPrice'] ?? null)];
        }

        return ['external_id' => $id, 'currency' => 'RUB', 'discount_percent' => $item['discount'], 'club_discount_percent' => $item['clubDiscount'],
            'editable_size_price' => $item['editableSizePrice'], 'sizes' => $sizes,
            'wholesale_discount_threshold' => $wholesaleComplete ? $item['wholesaleDiscountThreshold'] : null,
            'wholesale_context_complete' => $wholesaleComplete, 'observed_at' => now()->utc()->toRfc3339String()];
    }

    private function ozon(ManagerStore $store, Product $product): array
    {
        $id = (string) ($product->ozon_data['product_id'] ?? '');
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || $product->sku === '') {
            throw new ManagerDataException('Не подтверждены product_id и offer_id Ozon.', 422);
        }
        $response = Http::withHeaders(['Client-Id' => $store->clientId, 'Api-Key' => $store->apiKey])->acceptJson()->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false])
            ->post('https://api-seller.ozon.ru/v5/product/info/prices', ['filter' => ['product_id' => [$id], 'visibility' => 'ALL'], 'limit' => 1, 'cursor' => '']);
        $items = $response->json('items');
        if (! $response->successful() || ! is_array($items) || count($items) !== 1 || (string) ($items[0]['product_id'] ?? '') !== $id || ($items[0]['offer_id'] ?? '') !== $product->sku) {
            throw new ManagerDataException('Ozon не подтвердил цены выбранного product_id и offer_id.');
        }
        $item = $items[0];
        $price = $item['price'] ?? [];
        $autoAction = $price['auto_action_enabled'] ?? null;
        $autoAdd = $price['auto_add_to_ozon_actions_list_enabled'] ?? null;
        $marketing = $item['marketing_actions'] ?? null;
        if (($price['currency_code'] ?? '') !== 'RUB' || ($autoAction !== null && ! is_bool($autoAction))
            || ($autoAdd !== null && ! is_bool($autoAdd)) || ($marketing !== null && ! is_array($marketing))) {
            throw new ManagerDataException('Непроверенный снимок валюты или параметров акций Ozon.');
        }
        $promotionContextComplete = is_bool($autoAction) && is_bool($autoAdd) && is_array($marketing)
            && is_array($marketing['actions'] ?? null) && array_is_list($marketing['actions']) && is_bool($marketing['ozon_actions_exist'] ?? null);

        return ['external_id' => $id, 'offer_id' => $product->sku, 'currency' => 'RUB',
            'seller_price' => DecimalMoney::normalize($price['price'] ?? null),
            'marketing_seller_price' => DecimalMoney::normalize($price['marketing_seller_price'] ?? null),
            'min_price' => DecimalMoney::normalize($price['min_price'] ?? null), 'old_price' => DecimalMoney::normalize($price['old_price'] ?? null),
            'auto_action_enabled' => $autoAction, 'auto_add_to_actions' => $autoAdd, 'promotion_context_complete' => $promotionContextComplete,
            'marketing_actions' => $marketing, 'observed_at' => now()->utc()->toRfc3339String()];
    }
}
