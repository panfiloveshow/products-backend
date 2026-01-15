<?php

namespace App\Domains\YandexMarket\Tariffs;

use App\Domains\Marketplace\Contracts\CommissionsProviderInterface;

/**
 * Комиссии Yandex Market по категориям
 */
class CommissionCalculator implements CommissionsProviderInterface
{
    /**
     * Комиссии по категориям (%)
     */
    private const CATEGORY_COMMISSIONS = [
        // Электроника
        'Смартфоны' => 5,
        'Планшеты' => 5,
        'Ноутбуки' => 5,
        'Телевизоры' => 5,
        'Наушники' => 10,
        'Аксессуары' => 13,
        
        // Одежда
        'Женская одежда' => 15,
        'Мужская одежда' => 15,
        'Детская одежда' => 15,
        
        // Обувь
        'Обувь' => 15,
        
        // Красота
        'Косметика' => 13,
        'Парфюмерия' => 13,
        
        // Дом
        'Мебель' => 12,
        'Текстиль' => 13,
        'Посуда' => 13,
        
        // Продукты
        'Продукты' => 8,
        
        // Детские товары
        'Игрушки' => 13,
        
        // Спорт
        'Спорт' => 13,
        
        // Авто
        'Автотовары' => 10,
        
        // Default
        'default' => 12,
    ];

    /**
     * Эквайринг
     */
    private const ACQUIRING_RATE = 2.0; // 2%

    /**
     * Получить комиссию по категории
     */
    public function getCommissionRate(string $categoryId, ?string $scheme = null): float
    {
        // Точное совпадение
        if (isset(self::CATEGORY_COMMISSIONS[$categoryId])) {
            return (float) self::CATEGORY_COMMISSIONS[$categoryId];
        }

        // Частичное совпадение
        foreach (self::CATEGORY_COMMISSIONS as $category => $rate) {
            if (stripos($categoryId, $category) !== false || stripos($category, $categoryId) !== false) {
                return (float) $rate;
            }
        }

        return (float) self::CATEGORY_COMMISSIONS['default'];
    }

    /**
     * Получить все комиссии
     */
    public function getAllCommissions(): array
    {
        return self::CATEGORY_COMMISSIONS;
    }

    /**
     * Получить ставку эквайринга
     */
    public function getAcquiringRate(): float
    {
        return self::ACQUIRING_RATE;
    }

    /**
     * Рассчитать сумму комиссии
     */
    public function calculateCommission(float $price, string $categoryId): float
    {
        $rate = $this->getCommissionRate($categoryId);
        return $price * ($rate / 100);
    }

    /**
     * Рассчитать сумму эквайринга
     */
    public function calculateAcquiring(float $price): float
    {
        return $price * (self::ACQUIRING_RATE / 100);
    }
}
