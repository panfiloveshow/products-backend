<?php

namespace App\Domains\Marketplace\Contracts;

/**
 * Интерфейс провайдера комиссий маркетплейса
 */
interface CommissionsProviderInterface
{
    /**
     * Получить комиссию по категории
     * 
     * @param string $categoryId ID или код категории
     * @param string|null $scheme Схема работы (влияет на комиссию у некоторых МП)
     * @return float Процент комиссии (например 15.0 для 15%)
     */
    public function getCommissionRate(string $categoryId, ?string $scheme = null): float;

    /**
     * Получить все категории с комиссиями
     * 
     * @return array Массив [category_id => commission_rate]
     */
    public function getAllCommissions(): array;

    /**
     * Получить ставку эквайринга
     */
    public function getAcquiringRate(): float;
}
