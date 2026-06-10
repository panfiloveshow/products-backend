<?php

namespace App\Domains\Wildberries\Tariffs;

use App\Domains\Marketplace\Contracts\TariffsProviderInterface;

/**
 * Тарифы логистики Wildberries.
 *
 * Официальная fallback-модель по инструкции WB, обновлённой 18.05.2026:
 * - для товаров до 1 литра применяется таблица диапазонов объёма;
 * - для товаров больше 1 литра: первый литр + фактический дополнительный объём.
 * Live-тарифы из WB API snapshots остаются приоритетнее fallback.
 */
class WildberriesTariffs implements TariffsProviderInterface
{
    /**
     * Базовая логистика для товаров до 1 л.
     */
    private const SMALL_VOLUME_TARIFFS = [
        ['max_volume' => 0.2, 'rate' => 23.0],
        ['max_volume' => 0.4, 'rate' => 26.0],
        ['max_volume' => 0.6, 'rate' => 29.0],
        ['max_volume' => 0.8, 'rate' => 30.0],
        ['max_volume' => 1.0, 'rate' => 32.0],
    ];

    private const FIRST_LITER_OVER_ONE = 46.0;

    private const ADDITIONAL_LITER_OVER_ONE = 14.0;

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
            'source' => 'wildberries_official_static_fallback',
            'effective_from' => '2025-09-15',
            'small_volume_tariffs' => self::SMALL_VOLUME_TARIFFS,
            'first_liter_over_one' => self::FIRST_LITER_OVER_ONE,
            'additional_liter_over_one' => self::ADDITIONAL_LITER_OVER_ONE,
            'storage_rate' => self::STORAGE_RATE_PER_LITER_PER_DAY,
            'acceptance_box_per_liter' => 1.7,
            'acceptance_pallet_rate' => 500.0,
            'storage_pallet_daily_rate' => 23.0,
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
        
        $volumeInLiters = $volume;
        $officialCost = $this->calculateOfficialBoxLogisticsCost($scheme, $volumeInLiters, $options['tariff_breakdown']['box'] ?? null);
        if ($officialCost !== null) {
            return $officialCost;
        }
        
        // DBW — курьер WB забирает у продавца (тарифы примерно на 20% выше FBS)
        $dbwMultiplier = ($scheme === 'DBW') ? 1.2 : 1.0;
        
        return $this->calculateOfficialFallbackBase($volumeInLiters) * $dbwMultiplier;
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
    public function calculateStorageCost(float $volumeInLiters, int $daysInStock, array $options = []): float
    {
        $boxTariff = $options['tariff_breakdown']['box'] ?? null;
        if (is_array($boxTariff)) {
            $base = $this->firstNumeric($boxTariff, ['storage_base', 'boxStorageBase']);
            $liter = $this->firstNumeric($boxTariff, ['storage_liter', 'boxStorageLiter']);
            if ($base !== null) {
                return round($this->calculateBasePlusLiter($volumeInLiters, $base, $liter ?? 0.0) * $daysInStock, 2);
            }
        }

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
            // Нулевой return-тариф (base=0, liter=0) = «нет данных», а не бесплатный
            // возврат — падаем на расчёт от базовой логистики FBO.
            if ($base !== null && ($base > 0 || ($liter !== null && $liter > 0))) {
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

        $coef = $this->firstNumeric($boxTariff, $isMarketplaceDelivery
            ? ['delivery_marketplace_coef_percent', 'boxDeliveryMarketplaceCoefExpr']
            : ['delivery_coef_percent', 'boxDeliveryCoefExpr']);

        if (! $isMarketplaceDelivery && $volumeInLiters <= 1.0) {
            $multiplier = $coef !== null && $coef > 0 ? $coef / 100 : 1.0;

            return round($this->calculateOfficialFallbackBase($volumeInLiters) * $multiplier, 2);
        }

        $base = $this->firstNumeric($boxTariff, $baseKeys);
        $liter = $this->firstNumeric($boxTariff, $literKeys);

        // Склады «Маркетплейс: …» (FBS-only) приходят с нулевыми FBO-полями
        // (delivery_base=0, delivery_liter=0). Нулевой тариф означает «склад не
        // обслуживает эту схему», а не бесплатную логистику — игнорируем его и
        // уходим на ветку coef/официальный фолбэк, иначе базовая логистика
        // обнуляется (видели на интеграции 76: тариф «Маркетплейс: Грузия СГТ»).
        if ($base !== null && $base <= 0 && ($liter === null || $liter <= 0)) {
            $base = null;
        }

        if ($base === null) {
            if ($coef === null || $coef <= 0) {
                return null;
            }

            return round($this->calculateOfficialFallbackBase($volumeInLiters) * ($coef / 100), 2);
        }

        $liter ??= self::ADDITIONAL_LITER_OVER_ONE;

        return $this->calculateBasePlusLiter($volumeInLiters, $base, $liter);
    }

    private function calculateBasePlusLiter(float $volumeInLiters, float $base, float $liter): float
    {
        $chargeableVolume = max(1.0, $volumeInLiters);
        $extraLiters = max(0.0, $chargeableVolume - 1.0);

        return round($base + ($extraLiters * $liter), 2);
    }

    private function calculateOfficialFallbackBase(float $volumeInLiters): float
    {
        $volumeInLiters = max(0.001, $volumeInLiters);

        if ($volumeInLiters <= 1.0) {
            foreach (self::SMALL_VOLUME_TARIFFS as $tier) {
                if ($volumeInLiters <= $tier['max_volume']) {
                    return $tier['rate'];
                }
            }

            return 32.0;
        }

        return round(
            self::FIRST_LITER_OVER_ONE + (($volumeInLiters - 1.0) * self::ADDITIONAL_LITER_OVER_ONE),
            2
        );
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
