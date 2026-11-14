<?php

declare(strict_types=1);

namespace App\Services\Manager;

/** Exact vendor command for a simple price, without choosing another target. */
final class ManagerPriceNativeChange
{
    public function describe(array $context): array
    {
        $snapshot = $context['snapshot'] ?? [];
        $target = DecimalMoney::minor($context['scenario']['seller_price'] ?? null);
        $id = $snapshot['external_id'] ?? null;
        if ($target <= 0 || ! is_string($id) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || ($snapshot['currency'] ?? null) !== 'RUB') {
            throw new ManagerDataException('Не подтверждена точная команда выбранной цены.', 409);
        }
        $before = $snapshot; unset($before['observed_at']);
        if (($context['marketplace'] ?? null) === 'wildberries') {
            $discount = $snapshot['discount_percent'] ?? null;
            if (! is_int($discount) || $discount < 0 || $discount > 99 || ($snapshot['club_discount_percent'] ?? null) !== 0
                || ($snapshot['editable_size_price'] ?? null) !== false || ($snapshot['wholesale_context_complete'] ?? null) !== true
                || ($snapshot['wholesale_discount_threshold'] ?? null) !== [] || ! is_array($snapshot['sizes'] ?? null) || $snapshot['sizes'] === []) {
                throw new ManagerDataException('Для размеров, клуба и оптовых условий нужна отдельная команда цены.', 409);
            }
            // Native WB base prices are integer RUB. Preserve the displayed
            // seller discount: target kopecks = base RUB * (100 - discount).
            if ($target % (100 - $discount) !== 0) {
                throw new ManagerDataException('WB не может выставить эту цену точно при сохранении текущей скидки. Выберите представимую цену или отдельное изменение скидки.', 422, 'native_target_unrepresentable');
            }
            $baseRub = intdiv($target, 100 - $discount);
            $baseCents = null; $seen = [];
            foreach ($snapshot['sizes'] as $size) {
                $sizeId = $size['size_id'] ?? null;
                $base = DecimalMoney::minor($size['base_price'] ?? null);
                $seller = DecimalMoney::minor($size['seller_price'] ?? null);
                if (! is_string($sizeId) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $sizeId) || isset($seen[$sizeId])
                    || $base <= 0 || $base % 100 !== 0 || ($baseCents !== null && $base !== $baseCents)
                    || intdiv($base, 100) * (100 - $discount) !== $seller || DecimalMoney::minor($size['club_price'] ?? null) !== $seller) {
                    throw new ManagerDataException('Исходные цены всех размеров WB не подтверждают единую точную команду.', 409);
                }
                $baseCents = $base; $seen[$sizeId] = true;
            }
            if ($baseRub <= 0 || $baseRub > 1_000_000_000) throw new ManagerDataException('Базовая цена WB выходит за пределы команды.', 422, 'native_target_out_of_range');
            if ($baseRub * 100 === $baseCents) throw new ManagerDataException('Цена WB не меняется.', 422, 'native_price_unchanged');
            usort($before['sizes'], fn ($a, $b) => strlen($a['size_id']) <=> strlen($b['size_id']) ?: strcmp($a['size_id'], $b['size_id']));
            return ['version' => 'manager_price_native_change_v1', 'marketplace' => 'wildberries', 'resource_id' => $id, 'currency' => 'RUB',
                'before' => $before, 'after' => ['seller_price' => DecimalMoney::format($target), 'base_price' => DecimalMoney::format($baseRub * 100),
                    'discount_percent' => $discount], 'preserved' => ['discount_percent', 'club_discount_percent', 'wholesale_discount_threshold', 'size_ids']];
        }
        if (($context['marketplace'] ?? null) !== 'ozon' || ! is_string($snapshot['offer_id'] ?? null) || $snapshot['offer_id'] === ''
            || ($snapshot['promotion_context_complete'] ?? null) !== true || ($snapshot['auto_action_enabled'] ?? null) !== false
            || ($snapshot['auto_add_to_actions'] ?? null) !== false || ($snapshot['marketing_actions']['actions'] ?? null) !== []
            || ($snapshot['marketing_actions']['ozon_actions_exist'] ?? null) !== false
            || ($snapshot['marketing_seller_price'] ?? null) !== ($snapshot['seller_price'] ?? null)) {
            throw new ManagerDataException('Для акций Ozon нужна отдельная проверенная команда цены.', 409);
        }
        if (DecimalMoney::minor($snapshot['seller_price'] ?? null) === $target) throw new ManagerDataException('Цена Ozon не меняется.', 422, 'native_price_unchanged');
        $old = DecimalMoney::normalize($snapshot['old_price'] ?? null); $min = DecimalMoney::normalize($snapshot['min_price'] ?? null);

        return ['version' => 'manager_price_native_change_v1', 'marketplace' => 'ozon', 'resource_id' => $id, 'currency' => 'RUB',
            'before' => $before, 'after' => ['seller_price' => DecimalMoney::format($target), 'old_price' => $old, 'min_price' => $min],
            'preserved' => ['old_price', 'min_price', 'auto_action_enabled', 'auto_add_to_actions', 'marketing_actions']];
    }
}
