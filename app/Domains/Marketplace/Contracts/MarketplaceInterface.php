<?php

namespace App\Domains\Marketplace\Contracts;

use App\Models\Integration;

/**
 * Базовый интерфейс маркетплейса
 */
interface MarketplaceInterface
{
    /**
     * Получить название маркетплейса
     */
    public function getName(): string;

    /**
     * Получить код маркетплейса (wildberries, ozon, yandex_market)
     */
    public function getCode(): string;

    /**
     * Проверить подключение к API
     */
    public function testConnection(Integration $integration): bool;

    /**
     * Получить список поддерживаемых схем работы
     * @return string[] Например: ['FBO', 'FBS'] или ['FBO', 'FBS', 'RFBS', 'EXPRESS']
     */
    public function getSupportedSchemes(): array;
}
