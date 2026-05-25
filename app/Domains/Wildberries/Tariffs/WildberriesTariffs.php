<?php

namespace App\Domains\Wildberries\Tariffs;

use App\Domains\Marketplace\Contracts\TariffsProviderInterface;

/**
 * Тарифы логистики Wildberries
 * 
 * Актуальные тарифы с декабря 2024
 */
class WildberriesTariffs implements TariffsProviderInterface
{
    /**
     * Базовые тарифы логистики по объёму (литры)
     * FBO и FBS используют одинаковые базовые ставки
     */
    private const VOLUME_TARIFFS = [
        ['max_volume' => 1, 'rate' => 55],
        ['max_volume' => 2, 'rate' => 60],
        ['max_volume' => 3, 'rate' => 65],
        ['max_volume' => 5, 'rate' => 75],
        ['max_volume' => 10, 'rate' => 95],
        ['max_volume' => 15, 'rate' => 120],
        ['max_volume' => 20, 'rate' => 145],
        ['max_volume' => 25, 'rate' => 170],
        ['max_volume' => 30, 'rate' => 195],
        ['max_volume' => 40, 'rate' => 245],
        ['max_volume' => 50, 'rate' => 295],
        ['max_volume' => 75, 'rate' => 395],
        ['max_volume' => 100, 'rate' => 495],
        ['max_volume' => PHP_FLOAT_MAX, 'rate' => 495, 'per_liter_above' => 5], // свыше 100л
    ];

    /**
     * Тариф за хранение (₽ за литр в день)
     */
    private const STORAGE_RATE_PER_LITER_PER_DAY = 0.07;

    /**
     * Эквайринг
     */
    private const ACQUIRING_RATE = 0.02; // 2%

    /**
     * Получить тарифы логистики
     */
    public function getLogisticsTariffs(string $scheme): array
    {
        return [
            'scheme' => $scheme,
            'volume_tariffs' => self::VOLUME_TARIFFS,
            'storage_rate' => self::STORAGE_RATE_PER_LITER_PER_DAY,
            'acquiring_rate' => self::ACQUIRING_RATE,
        ];
    }

    /**
     * Рассчитать стоимость логистики
     * 
     * Схемы WB:
     * - FBO/FBW: Склад WB — полная логистика WB
     * - FBS: Ваш склад, логистика WB — полная логистика WB
     * - DBS: Своя доставка — логистика 0 (продавец сам доставляет)
     * - EDBS: Экспресс своя — логистика 0 (продавец сам доставляет)
     * - DBW: Курьер WB от вас — используются тарифы DBW (курьер WB)
     */
    public function calculateLogisticsCost(string $scheme, float $volume, float $weight, array $options = []): float
    {
        $scheme = strtoupper($scheme);
        
        // DBS и EDBS — продавец сам доставляет, логистика WB = 0
        if (in_array($scheme, ['DBS', 'EDBS'])) {
            return 0;
        }
        
        // WB использует объём в литрах
        $volumeInLiters = $volume;
        $officialCost = $this->calculateOfficialBoxLogisticsCost($scheme, $volumeInLiters, $options['tariff_breakdown']['box'] ?? null);
        if ($officialCost !== null) {
            return $officialCost;
        }
        
        // DBW — курьер WB забирает у продавца (тарифы примерно на 20% выше FBS)
        $dbwMultiplier = ($scheme === 'DBW') ? 1.2 : 1.0;
        
        foreach (self::VOLUME_TARIFFS as $tier) {
            if ($volumeInLiters <= $tier['max_volume']) {
                $baseCost = $tier['rate'];
                
                // Для больших объёмов добавляем за каждый литр сверху
                if (isset($tier['per_liter_above'])) {
                    $prevMaxVolume = 100;
                    $extraLiters = $volumeInLiters - $prevMaxVolume;
                    $baseCost += $extraLiters * $tier['per_liter_above'];
                }
                
                return $baseCost * $dbwMultiplier;
            }
        }

        // Fallback для очень больших объёмов
        return (495 + ($volumeInLiters - 100) * 5) * $dbwMultiplier;
    }

    /**
     * Получить коэффициенты
     * WB не использует коэффициенты времени доставки как Ozon
     */
    public function getCoefficients(string $scheme, array $options = []): array
    {
        return [
            'delivery_coefficient' => 1.0, // Нет коэффициента
        ];
    }

    /**
     * Рассчитать стоимость хранения
     */
    public function calculateStorageCost(float $volumeInLiters, int $daysInStock): float
    {
        return $volumeInLiters * self::STORAGE_RATE_PER_LITER_PER_DAY * $daysInStock;
    }

    /**
     * Рассчитать стоимость обратной логистики (возврат)
     * Обратная логистика = базовая логистика (без коэффициентов)
     */
    public function calculateReturnLogisticsCost(float $volume, float $weight, array $options = []): float
    {
        $return = $options['tariff_breakdown']['return'] ?? null;
        if (is_array($return)) {
            $base = $this->firstNumeric($return, ['base', 'return_base', 'returnDeliveryBase', 'boxDeliveryBase']);
            $liter = $this->firstNumeric($return, ['liter', 'return_liter', 'returnDeliveryLiter', 'boxDeliveryLiter']);
            if ($base !== null) {
                return $this->calculateBasePlusLiter($volume, $base, $liter ?? 0.0);
            }
        }

        return $this->calculateLogisticsCost('FBO', $volume, $weight, $options);
    }

    private function calculateOfficialBoxLogisticsCost(string $scheme, float $volumeInLiters, mixed $boxTariff): ?float
    {
        if (! is_array($boxTariff)) {
            return null;
        }

        $isMarketplaceDelivery = in_array($scheme, ['FBS', 'DBW'], true);
        $baseKeys = $isMarketplaceDelivery
            ? ['delivery_marketplace_base', 'boxDeliveryMarketplaceBase', 'delivery_base', 'boxDeliveryBase']
            : ['delivery_base', 'boxDeliveryBase'];
        $literKeys = $isMarketplaceDelivery
            ? ['delivery_marketplace_liter', 'boxDeliveryMarketplaceLiter', 'delivery_liter', 'boxDeliveryLiter']
            : ['delivery_liter', 'boxDeliveryLiter'];

        $base = $this->firstNumeric($boxTariff, $baseKeys);
        if ($base === null) {
            return null;
        }

        $liter = $this->firstNumeric($boxTariff, $literKeys) ?? 0.0;

        return $this->calculateBasePlusLiter($volumeInLiters, $base, $liter);
    }

    private function calculateBasePlusLiter(float $volumeInLiters, float $base, float $liter): float
    {
        $chargeableVolume = max(1.0, ceil($volumeInLiters));
        $extraLiters = max(0.0, $chargeableVolume - 1.0);

        return round($base + ($extraLiters * $liter), 2);
    }

    private function firstNumeric(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }

            if (is_numeric($data[$key])) {
                return (float) $data[$key];
            }

            if (is_string($data[$key])) {
                $normalized = str_replace(',', '.', $data[$key]);
                if (is_numeric($normalized)) {
                    return (float) $normalized;
                }
            }
        }

        return null;
    }
}
