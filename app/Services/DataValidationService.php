<?php

namespace App\Services;

use App\Models\Product;
use App\Models\InventoryWarehouse;
use Illuminate\Support\Facades\Log;

/**
 * Сервис валидации данных из API маркетплейсов
 * Проверяет корректность данных и логирует аномалии
 */
class DataValidationService
{
    /**
     * Валидация данных товара перед сохранением
     * @return array ['valid' => bool, 'errors' => [], 'warnings' => []]
     */
    public function validateProduct(array $data): array
    {
        $errors = [];
        $warnings = [];
        
        // Обязательные поля
        if (empty($data['sku'])) {
            $errors[] = 'SKU is required';
        }
        
        if (empty($data['name'])) {
            $errors[] = 'Name is required';
        }
        
        // Валидация цены
        if (isset($data['price'])) {
            if (!is_numeric($data['price']) || $data['price'] < 0) {
                $errors[] = 'Invalid price: ' . $data['price'];
            }
            if ($data['price'] > 10000000) {
                $warnings[] = 'Unusually high price: ' . $data['price'];
            }
        }
        
        // Валидация остатков
        if (isset($data['stock'])) {
            if (!is_numeric($data['stock']) || $data['stock'] < 0) {
                $errors[] = 'Invalid stock: ' . $data['stock'];
            }
            if ($data['stock'] > 100000) {
                $warnings[] = 'Unusually high stock: ' . $data['stock'];
            }
        }
        
        // Логируем предупреждения
        if (!empty($warnings)) {
            Log::warning('Product data warnings', [
                'sku' => $data['sku'] ?? 'unknown',
                'warnings' => $warnings,
            ]);
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    
    /**
     * Валидация данных остатков
     */
    public function validateInventory(array $data): array
    {
        $errors = [];
        $warnings = [];
        
        if (empty($data['sku'])) {
            $errors[] = 'SKU is required';
        }
        
        if (empty($data['warehouse_id'])) {
            $errors[] = 'Warehouse ID is required';
        }
        
        if (isset($data['quantity'])) {
            if (!is_numeric($data['quantity']) || $data['quantity'] < 0) {
                $errors[] = 'Invalid quantity: ' . $data['quantity'];
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    
    /**
     * Проверка аномалий в данных (резкие изменения)
     * Вызывается после синхронизации для обнаружения подозрительных изменений
     */
    public function checkAnomalies(string $marketplace): array
    {
        $anomalies = [];
        
        // Проверяем резкие изменения остатков (>50% за день)
        $products = Product::where('marketplace', $marketplace)
            ->with('inventoryWarehouses')
            ->get();
        
        foreach ($products as $product) {
            $currentStock = $product->inventoryWarehouses->sum('quantity');
            $previousStock = $product->stock;
            
            if ($previousStock > 0 && $currentStock > 0) {
                $change = abs($currentStock - $previousStock) / $previousStock * 100;
                
                if ($change > 50) {
                    $anomalies[] = [
                        'type' => 'stock_change',
                        'sku' => $product->sku,
                        'previous' => $previousStock,
                        'current' => $currentStock,
                        'change_percent' => round($change, 1),
                    ];
                }
            }
        }
        
        // Логируем аномалии
        if (!empty($anomalies)) {
            Log::warning('Data anomalies detected', [
                'marketplace' => $marketplace,
                'count' => count($anomalies),
                'anomalies' => array_slice($anomalies, 0, 10), // Первые 10
            ]);
        }
        
        return $anomalies;
    }
    
    /**
     * Проверка целостности данных
     * Находит товары без остатков, остатки без товаров и т.д.
     */
    public function checkDataIntegrity(): array
    {
        $issues = [];
        
        // Товары с stock > 0, но без записей в InventoryWarehouse
        $productsWithoutWarehouse = Product::where('stock', '>', 0)
            ->whereDoesntHave('inventoryWarehouses')
            ->count();
        
        if ($productsWithoutWarehouse > 0) {
            $issues[] = [
                'type' => 'products_without_warehouse',
                'count' => $productsWithoutWarehouse,
                'severity' => 'warning',
            ];
        }
        
        // Записи в InventoryWarehouse для несуществующих товаров
        $orphanedWarehouses = InventoryWarehouse::whereNotIn('sku', Product::pluck('sku'))->count();
        
        if ($orphanedWarehouses > 0) {
            $issues[] = [
                'type' => 'orphaned_warehouse_records',
                'count' => $orphanedWarehouses,
                'severity' => 'error',
            ];
        }
        
        // Товары с отрицательным stock (не должно быть)
        $negativeStock = Product::where('stock', '<', 0)->count();
        
        if ($negativeStock > 0) {
            $issues[] = [
                'type' => 'negative_stock',
                'count' => $negativeStock,
                'severity' => 'error',
            ];
        }
        
        return $issues;
    }
}
