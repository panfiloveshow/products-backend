<?php

namespace App\Domains\Ozon\Tariffs;

use App\Domains\Marketplace\Contracts\CommissionsProviderInterface;

/**
 * Комиссии Ozon по категориям
 */
class CommissionCalculator implements CommissionsProviderInterface
{
    private OzonPricingMatrix $pricing;

    public function __construct()
    {
        $this->pricing = new OzonPricingMatrix();
    }

    /**
     * Комиссии по категориям (%) — fallback значения для FBO
     * Актуально с 10.11.2025
     * Источник: Ozon Seller → Тарифы
     * 
     * ВАЖНО: Эти значения используются только как fallback.
     * Основные комиссии берутся из API Ozon (/v3/product/info/prices).
     */
    private const CATEGORY_COMMISSIONS = [
        // Одежда (с 10.11.2025 — значительный рост)
        'Женская одежда' => 43,
        'Мужская одежда' => 43,
        'Детская одежда' => 43,
        'Нижнее белье' => 43,
        
        // Обувь
        'Женская обувь' => 22,
        'Мужская обувь' => 22,
        'Детская обувь' => 22,
        
        // Электроника
        'Смартфоны' => 8,
        'Планшеты' => 8,
        'Ноутбуки' => 8,
        'Наушники' => 14,
        'Аксессуары для электроники' => 18,
        
        // Красота
        'Декоративная косметика' => 22,
        'Уход за кожей' => 22,
        'Парфюмерия' => 22,
        'Уход за волосами' => 22,
        
        // Дом
        'Текстиль для дома' => 22,
        'Посуда' => 22,
        'Хранение' => 22,
        'Мебель' => 18,
        
        // Продукты
        'Продукты питания' => 12,
        'Напитки' => 12,
        
        // Детские товары
        'Игрушки' => 22,
        'Детское питание' => 12,
        
        // Спорт
        'Спортивная одежда' => 22,
        'Спортивный инвентарь' => 18,
        
        // Авто
        'Автозапчасти' => 14,
        'Автоаксессуары' => 18,
        
        // Default
        'default' => 18,
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
        $resolved = $this->pricing->resolveCommission($scheme ?? 'FBO', $categoryId, 1500);

        return (float) $resolved['sales_fee_percent'];
    }

    /**
     * Получить все комиссии
     */
    public function getAllCommissions(): array
    {
        return $this->pricing->getConfig()['commissions'] ?? [];
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
