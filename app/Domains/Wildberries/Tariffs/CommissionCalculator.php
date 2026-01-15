<?php

namespace App\Domains\Wildberries\Tariffs;

use App\Domains\Marketplace\Contracts\CommissionsProviderInterface;

/**
 * Комиссии Wildberries по категориям
 */
class CommissionCalculator implements CommissionsProviderInterface
{
    /**
     * Комиссии по категориям (%)
     * Источник: Личный кабинет WB → Тарифы
     * 
     * Формат: category_id => commission_percent
     */
    private const CATEGORY_COMMISSIONS = [
        // Одежда
        'Блузки и рубашки' => 17,
        'Брюки' => 17,
        'Верхняя одежда' => 17,
        'Джемперы, свитеры и кардиганы' => 17,
        'Джинсы' => 17,
        'Костюмы' => 17,
        'Платья' => 17,
        'Толстовки и олимпийки' => 17,
        'Футболки и топы' => 17,
        'Юбки' => 17,
        
        // Обувь
        'Босоножки и сандалии' => 17,
        'Ботинки' => 17,
        'Кроссовки и кеды' => 17,
        'Сапоги и полусапожки' => 17,
        'Туфли' => 17,
        
        // Электроника
        'Наушники и гарнитуры' => 12,
        'Смартфоны' => 5,
        'Планшеты' => 5,
        'Ноутбуки' => 5,
        'Аксессуары для телефонов' => 15,
        
        // Красота
        'Декоративная косметика' => 17,
        'Уход за кожей' => 17,
        'Парфюмерия' => 17,
        'Уход за волосами' => 17,
        
        // Дом
        'Постельное бельё' => 17,
        'Полотенца' => 17,
        'Посуда' => 17,
        'Хранение вещей' => 17,
        
        // Детские товары
        'Детская одежда' => 17,
        'Детская обувь' => 17,
        'Игрушки' => 17,
        
        // Продукты
        'Продукты питания' => 12,
        'Напитки' => 12,
        
        // Спорт
        'Спортивная одежда' => 17,
        'Спортивный инвентарь' => 15,
        
        // Default
        'default' => 15,
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
        // Ищем точное совпадение
        if (isset(self::CATEGORY_COMMISSIONS[$categoryId])) {
            return (float) self::CATEGORY_COMMISSIONS[$categoryId];
        }

        // Ищем частичное совпадение
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
