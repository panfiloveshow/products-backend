<?php

namespace App\Domains\Marketplace\Contracts;

/**
 * Интерфейс провайдера тарифов маркетплейса
 */
interface TariffsProviderInterface
{
    /**
     * Получить тарифы логистики
     * 
     * @param string $scheme Схема работы (FBO, FBS, RFBS, EXPRESS)
     * @return array Массив тарифов
     */
    public function getLogisticsTariffs(string $scheme): array;

    /**
     * Рассчитать стоимость логистики
     * 
     * @param string $scheme Схема работы
     * @param float $volume Объём товара в литрах
     * @param float $weight Вес товара в кг
     * @param array $options Дополнительные параметры (склад, регион и т.д.)
     * @return float Стоимость логистики в рублях
     */
    public function calculateLogisticsCost(string $scheme, float $volume, float $weight, array $options = []): float;

    /**
     * Получить коэффициенты (для Ozon FBO — коэффициент времени доставки)
     */
    public function getCoefficients(string $scheme, array $options = []): array;
}
