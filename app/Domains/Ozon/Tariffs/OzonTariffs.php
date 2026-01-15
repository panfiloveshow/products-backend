<?php

namespace App\Domains\Ozon\Tariffs;

use App\Domains\Marketplace\Contracts\TariffsProviderInterface;

/**
 * Тарифы логистики Ozon
 * 
 * Актуальные тарифы с декабря 2024
 * 
 * Схемы работы:
 * - FBO: Склад Ozon, логистика Ozon
 * - FBS: Ваш склад, логистика Ozon
 * - RFBS: realFBS Standard (своя логистика, вся Россия)
 * - EXPRESS: realFBS Express (экспресс 0-25 км от склада)
 */
class OzonTariffs implements TariffsProviderInterface
{
    /**
     * FBO тарифы логистики по объёму (литры) для товаров от 301₽
     */
    private const FBO_VOLUME_TARIFFS = [
        ['max_volume' => 0.1, 'rate' => 46.77],
        ['max_volume' => 0.2, 'rate' => 50.50],
        ['max_volume' => 0.5, 'rate' => 55.00],
        ['max_volume' => 1.0, 'rate' => 60.00],
        ['max_volume' => 2.0, 'rate' => 70.00],
        ['max_volume' => 3.0, 'rate' => 80.00],
        ['max_volume' => 5.0, 'rate' => 95.00],
        ['max_volume' => 10.0, 'rate' => 120.00],
        ['max_volume' => 15.0, 'rate' => 145.00],
        ['max_volume' => 20.0, 'rate' => 175.00],
        ['max_volume' => PHP_FLOAT_MAX, 'rate' => 175.00, 'per_liter_above' => 10.00],
    ];

    /**
     * FBS тарифы логистики по объёму (литры) для товаров от 301₽
     */
    private const FBS_VOLUME_TARIFFS = [
        ['max_volume' => 1.0, 'rate' => 81.34],
        ['max_volume' => 2.0, 'rate' => 99.64],
        ['max_volume' => 3.0, 'rate' => 117.94],
        ['max_volume' => 190.0, 'rate' => 117.94, 'per_liter_above' => 23.39, 'base_volume' => 3.0],
        ['max_volume' => 1000.0, 'rate' => 4494.04, 'per_liter_above' => 6.10, 'base_volume' => 190.0],
        ['max_volume' => PHP_FLOAT_MAX, 'rate' => 9432.87], // свыше 1000л — фикс
    ];

    /**
     * Обработка отправления FBS (за литр)
     */
    private const FBS_PROCESSING_TARIFFS = [
        ['max_volume' => 1000, 'rate' => 1.9],
        ['max_volume' => 4000, 'rate' => 1.7],
        ['max_volume' => 16000, 'rate' => 1.5],
        ['max_volume' => PHP_FLOAT_MAX, 'rate' => 1.3],
    ];

    /**
     * Базовая обработка FBS
     */
    private const FBS_PROCESSING_BASE = 20.0; // ₽ за отправление

    /**
     * Последняя миля (макс)
     */
    private const LAST_MILE_MAX = 25.0;

    /**
     * Эквайринг
     */
    private const ACQUIRING_RATE = 1.5; // 1.5%

    /**
     * Агентское вознаграждение (RFBS/EXPRESS)
     */
    private const AGENT_FEE = 20.0;

    /**
     * Возмещение за экспресс-доставку (EXPRESS своя служба)
     */
    private const EXPRESS_COMPENSATION = 199.0;

    /**
     * Обработка возврата
     */
    private const RETURN_PROCESSING = 15.0;

    /**
     * Получить тарифы логистики по схеме
     */
    public function getLogisticsTariffs(string $scheme): array
    {
        return match (strtoupper($scheme)) {
            'FBO' => [
                'scheme' => 'FBO',
                'volume_tariffs' => self::FBO_VOLUME_TARIFFS,
                'last_mile_max' => self::LAST_MILE_MAX,
                'acquiring_rate' => self::ACQUIRING_RATE,
                'has_coefficient' => true,
                'has_additional_percent' => true,
            ],
            'FBS' => [
                'scheme' => 'FBS',
                'volume_tariffs' => self::FBS_VOLUME_TARIFFS,
                'processing_base' => self::FBS_PROCESSING_BASE,
                'processing_tariffs' => self::FBS_PROCESSING_TARIFFS,
                'last_mile_max' => self::LAST_MILE_MAX,
                'acquiring_rate' => self::ACQUIRING_RATE,
                'has_coefficient' => false,
            ],
            'RFBS' => [
                'scheme' => 'RFBS',
                'agent_fee' => self::AGENT_FEE,
                'acquiring_rate' => self::ACQUIRING_RATE,
                'own_delivery' => true,
            ],
            'EXPRESS' => [
                'scheme' => 'EXPRESS',
                'agent_fee' => self::AGENT_FEE,
                'express_compensation' => self::EXPRESS_COMPENSATION,
                'acquiring_rate' => self::ACQUIRING_RATE,
                'own_delivery' => true,
            ],
            default => throw new \InvalidArgumentException("Unknown scheme: {$scheme}"),
        };
    }

    /**
     * Рассчитать стоимость логистики
     */
    public function calculateLogisticsCost(string $scheme, float $volume, float $weight, array $options = []): float
    {
        return match (strtoupper($scheme)) {
            'FBO' => $this->calculateFboLogistics($volume, $options),
            'FBS' => $this->calculateFbsLogistics($volume, $options),
            'RFBS' => $options['own_delivery_cost'] ?? 0, // Своя доставка
            'EXPRESS' => $options['own_delivery_cost'] ?? 0, // Своя доставка
            default => 0,
        };
    }

    /**
     * Рассчитать FBO логистику
     */
    private function calculateFboLogistics(float $volume, array $options = []): float
    {
        $coefficient = $options['delivery_coefficient'] ?? 1.0;
        $additionalPercent = $options['additional_percent'] ?? 0;
        $price = $options['price'] ?? 0;

        // Базовый тариф
        $baseCost = $this->getVolumeCost(self::FBO_VOLUME_TARIFFS, $volume);
        
        // Применяем коэффициент времени доставки
        $logisticsCost = $baseCost * $coefficient;
        
        // Доп. % от цены
        $additionalCost = $price * ($additionalPercent / 100);
        
        // Последняя миля
        $lastMile = min($options['last_mile'] ?? self::LAST_MILE_MAX, self::LAST_MILE_MAX);

        return $logisticsCost + $additionalCost + $lastMile;
    }

    /**
     * Рассчитать FBS логистику
     */
    private function calculateFbsLogistics(float $volume, array $options = []): float
    {
        // Базовая логистика
        $logisticsCost = $this->getVolumeCost(self::FBS_VOLUME_TARIFFS, $volume);
        
        // Обработка отправления
        $processingCost = self::FBS_PROCESSING_BASE + $this->getProcessingCost($volume);
        
        // Последняя миля
        $lastMile = min($options['last_mile'] ?? self::LAST_MILE_MAX, self::LAST_MILE_MAX);

        return $logisticsCost + $processingCost + $lastMile;
    }

    /**
     * Получить стоимость по таблице тарифов
     */
    private function getVolumeCost(array $tariffs, float $volume): float
    {
        foreach ($tariffs as $tier) {
            if ($volume <= $tier['max_volume']) {
                $cost = $tier['rate'];
                
                if (isset($tier['per_liter_above']) && isset($tier['base_volume'])) {
                    $extraLiters = max(0, $volume - $tier['base_volume']);
                    $cost += $extraLiters * $tier['per_liter_above'];
                } elseif (isset($tier['per_liter_above'])) {
                    $prevMaxVolume = 20;
                    $extraLiters = max(0, $volume - $prevMaxVolume);
                    $cost += $extraLiters * $tier['per_liter_above'];
                }
                
                return $cost;
            }
        }

        return end($tariffs)['rate'];
    }

    /**
     * Получить стоимость обработки FBS
     */
    private function getProcessingCost(float $volume): float
    {
        foreach (self::FBS_PROCESSING_TARIFFS as $tier) {
            if ($volume <= $tier['max_volume']) {
                return $volume * $tier['rate'];
            }
        }
        return $volume * 1.3;
    }

    /**
     * Получить коэффициенты
     */
    public function getCoefficients(string $scheme, array $options = []): array
    {
        if (strtoupper($scheme) === 'FBO') {
            return [
                'delivery_coefficient' => $options['delivery_coefficient'] ?? 1.0,
                'additional_percent' => $options['additional_percent'] ?? 0,
            ];
        }

        return [
            'delivery_coefficient' => 1.0,
            'additional_percent' => 0,
        ];
    }

    /**
     * Рассчитать обратную логистику (возврат)
     * Ozon: базовый тариф БЕЗ коэффициента + 15₽ обработка
     */
    public function calculateReturnLogisticsCost(string $scheme, float $volume): float
    {
        $baseCost = match (strtoupper($scheme)) {
            'FBO' => $this->getVolumeCost(self::FBO_VOLUME_TARIFFS, $volume),
            'FBS' => $this->getVolumeCost(self::FBS_VOLUME_TARIFFS, $volume),
            default => 0,
        };

        return $baseCost + self::RETURN_PROCESSING;
    }

    /**
     * Получить агентское вознаграждение
     */
    public function getAgentFee(string $scheme): float
    {
        return in_array(strtoupper($scheme), ['RFBS', 'EXPRESS']) ? self::AGENT_FEE : 0;
    }

    /**
     * Получить возмещение за экспресс
     */
    public function getExpressCompensation(): float
    {
        return self::EXPRESS_COMPENSATION;
    }
}
