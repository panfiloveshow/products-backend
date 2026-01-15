<?php

namespace App\Domains\Ozon\Tariffs;

use App\Domains\Marketplace\Contracts\CommissionsProviderInterface;

/**
 * Комиссии Ozon по категориям
 */
class CommissionCalculator implements CommissionsProviderInterface
{
    /**
     * Комиссии по категориям (%)
     * Источник: Ozon Seller → Тарифы
     */
    private const CATEGORY_COMMISSIONS = [
        // Одежда
        'Женская одежда' => 20,
        'Мужская одежда' => 20,
        'Детская одежда' => 20,
        'Нижнее белье' => 20,
        
        // Обувь
        'Женская обувь' => 20,
        'Мужская обувь' => 20,
        'Детская обувь' => 20,
        
        // Электроника
        'Смартфоны' => 5,
        'Планшеты' => 5,
        'Ноутбуки' => 5,
        'Наушники' => 12,
        'Аксессуары для электроники' => 15,
        
        // Красота
        'Декоративная косметика' => 18,
        'Уход за кожей' => 18,
        'Парфюмерия' => 18,
        'Уход за волосами' => 18,
        
        // Дом
        'Текстиль для дома' => 18,
        'Посуда' => 18,
        'Хранение' => 18,
        'Мебель' => 15,
        
        // Продукты
        'Продукты питания' => 10,
        'Напитки' => 10,
        
        // Детские товары
        'Игрушки' => 18,
        'Детское питание' => 10,
        
        // Спорт
        'Спортивная одежда' => 18,
        'Спортивный инвентарь' => 15,
        
        // Авто
        'Автозапчасти' => 12,
        'Автоаксессуары' => 15,
        
        // Default
        'default' => 15,
    ];

    /**
     * Эквайринг
     */
    private const ACQUIRING_RATE = 1.5; // 1.5%

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
