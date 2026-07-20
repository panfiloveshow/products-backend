<?php

namespace App\Domains\Wildberries\UnitEconomics;

final class WildberriesSppResolver
{
    public static function resolve(
        ?float $salesSpp,
        ?float $cardSpp,
        ?float $existingSpp,
        ?float $embeddedSpp = null
    ): float {
        return self::resolveWithSource($salesSpp, $cardSpp, $existingSpp, $embeddedSpp)['value'];
    }

    /**
     * @return array{value:float,source:string,fresh:bool}
     */
    public static function resolveWithSource(
        ?float $salesSpp,
        ?float $cardSpp,
        ?float $existingSpp,
        ?float $embeddedSpp = null,
        string $reportSource = 'sales',
        bool $reportExact = false,
        string $cardSource = 'storefront_nm',
    ): array {
        // Продажи — приоритетный источник, но нулевой СПП сначала разрешаем
        // уточнить витриной. Ноль из доступного источника является валидным.
        if ($salesSpp !== null && ($reportExact || $salesSpp > 0)) {
            return ['value' => $salesSpp, 'source' => $reportSource, 'fresh' => true];
        }
        if ($cardSpp !== null) {
            return ['value' => $cardSpp, 'source' => $cardSource, 'fresh' => true];
        }
        if ($salesSpp !== null) {
            return ['value' => $salesSpp, 'source' => $reportSource, 'fresh' => true];
        }

        // Оба текущих источника не дали товар: сохраняем последнее успешное
        // значение, а не превращаем сетевой сбой в СПП=0.
        if ($existingSpp !== null) {
            return ['value' => $existingSpp, 'source' => 'snapshot', 'fresh' => false];
        }
        if ($embeddedSpp !== null) {
            return ['value' => $embeddedSpp, 'source' => 'product', 'fresh' => false];
        }

        return ['value' => 0.0, 'source' => 'none', 'fresh' => false];
    }
}
