<?php

namespace App\Services\Marketplace;

interface MarketplaceInterface
{
    public function getProducts(): array;
    
    public function getInventory(): array;
    
    public function getWarehouses(): array;
    
    public function getSalesStats(string $dateFrom, string $dateTo): array;
    
    public function getCommissions(): array;
}
