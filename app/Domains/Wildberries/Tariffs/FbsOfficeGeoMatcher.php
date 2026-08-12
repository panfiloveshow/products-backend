<?php

namespace App\Domains\Wildberries\Tariffs;

/**
 * Привязка FBS-склада продавца к тарифной географии WB.
 *
 * WB тарифицирует FBS-логистику строкой «Маркетплейс: {федеральный округ}»
 * по округу СЦ привязки склада продавца (в кабинете: «Склад WB: Москва (СК
 * Обухово)» → ЦФО → 165%). Округ СЦ достаём из своих же box-тарифов: у
 * склада-тёзки («Обухово») в них заполнен geo_name.
 */
final class FbsOfficeGeoMatcher
{
    /**
     * @param  string  $officeName  Имя офиса WB из /api/v3/offices (например «Москва (СК Обухово)»)
     * @param  array<string, array{warehouse_name?:string, geo_name?:?string}>  $tariffWarehouses  box-тарифы по имени склада
     * @return array{warehouse_name:string, geo_name:string}|null
     */
    public static function match(string $officeName, array $tariffWarehouses): ?array
    {
        $officeTokens = self::tokens($officeName);
        if ($officeTokens === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($tariffWarehouses as $warehouse) {
            $name = trim((string) ($warehouse['warehouse_name'] ?? ''));
            $geo = trim((string) ($warehouse['geo_name'] ?? ''));
            if ($name === '' || $geo === '') {
                continue;
            }
            // Строки «Маркетплейс: …» — сами тарифы FBS, к СЦ не относятся.
            if (mb_stripos($name, 'маркетплейс') !== false) {
                continue;
            }

            // Имя склада должно целиком «сидеть» в имени офиса: склад «Обухово»
            // ⊂ офис «Москва (СК Обухово)». Побеждает самое специфичное имя.
            $warehouseTokens = self::tokens($name);
            if ($warehouseTokens === [] || array_diff($warehouseTokens, $officeTokens) !== []) {
                continue;
            }
            if (count($warehouseTokens) > $bestScore) {
                $bestScore = count($warehouseTokens);
                $best = ['warehouse_name' => $name, 'geo_name' => $geo];
            }
        }

        return $best;
    }

    /**
     * Значимые токены имени: «Москва (СК Обухово)» → [москва, обухово].
     * Служебные слова (СК/СЦ/склад/город-дубли в скобках) короче 4 символов
     * отсеиваются длиной.
     *
     * @return list<string>
     */
    private static function tokens(string $name): array
    {
        $normalized = mb_strtolower($name);
        $words = preg_split('/[^a-zа-яё0-9]+/u', $normalized) ?: [];

        return array_values(array_filter($words, static fn (string $w) => mb_strlen($w) >= 4));
    }
}
