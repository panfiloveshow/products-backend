<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * API для работы с категориями Ozon
 */
class CategoriesApi
{
    private array $categoryCache = [];
    private array $attributeNamesCache = [];

    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить дерево категорий (с кэшированием)
     */
    public function getCategoryTree(): array
    {
        return Cache::remember('ozon_category_tree', 3600, function () {
            try {
                $response = $this->client->post('/v1/description-category/tree', [
                    'language' => 'RU',
                ]);

                $categories = [];
                $this->flattenCategories($response['result'] ?? [], $categories);

                return $categories;
            } catch (\Exception $e) {
                Log::error('Ozon getCategoryTree error', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Получить название категории по ID
     */
    public function getCategoryName(int $categoryId): ?string
    {
        if (isset($this->categoryCache[$categoryId])) {
            return $this->categoryCache[$categoryId];
        }

        $tree = $this->getCategoryTree();
        return $tree[$categoryId] ?? null;
    }

    /**
     * Получить атрибуты категории с названиями
     * 
     * POST /v1/description-category/attribute
     * 
     * @param int $categoryId ID категории
     * @param int $typeId ID типа товара (обязательно > 0)
     * @return array Ассоциативный массив [attribute_id => attribute_name]
     */
    public function getCategoryAttributeNames(int $categoryId, int $typeId = 0): array
    {
        // type_id должен быть > 0, иначе API вернёт ошибку
        if ($typeId <= 0) {
            return [];
        }
        
        $cacheKey = "ozon_attr_names_{$categoryId}_{$typeId}";
        
        if (isset($this->attributeNamesCache[$cacheKey])) {
            return $this->attributeNamesCache[$cacheKey];
        }
        
        return Cache::remember($cacheKey, 3600, function () use ($categoryId, $typeId, $cacheKey) {
            try {
                $response = $this->client->post('/v1/description-category/attribute', [
                    'description_category_id' => $categoryId,
                    'language' => 'RU',
                    'type_id' => $typeId,
                ]);
                
                $attributes = [];
                foreach ($response['result'] ?? [] as $attr) {
                    $id = $attr['id'] ?? $attr['attribute_id'] ?? null;
                    $name = $attr['name'] ?? $attr['attribute_name'] ?? null;
                    if ($id && $name) {
                        $attributes[$id] = $name;
                    }
                }
                
                $this->attributeNamesCache[$cacheKey] = $attributes;
                
                Log::debug('Ozon getCategoryAttributeNames', [
                    'category_id' => $categoryId,
                    'type_id' => $typeId,
                    'attributes_count' => count($attributes),
                ]);
                
                return $attributes;
            } catch (\Exception $e) {
                Log::error('Ozon getCategoryAttributeNames error', [
                    'category_id' => $categoryId,
                    'type_id' => $typeId,
                    'error' => $e->getMessage(),
                ]);
                return [];
            }
        });
    }

    /**
     * Преобразовать сырые атрибуты в читаемый формат
     * 
     * @param array $rawAttributes Сырые атрибуты из API [{id, values: [{value}]}]
     * @param int $categoryId ID категории для получения названий атрибутов
     * @param int $typeId ID типа товара
     * @return array Читаемый формат [{name: "Бренд", value: "Baker-Maker"}]
     */
    public function formatAttributes(array $rawAttributes, int $categoryId, int $typeId = 0): array
    {
        $attributeNames = $this->getCategoryAttributeNames($categoryId, $typeId);
        $formatted = [];
        
        foreach ($rawAttributes as $attr) {
            $attrId = $attr['id'] ?? $attr['attribute_id'] ?? null;
            if (!$attrId) continue;
            
            $name = $attributeNames[$attrId] ?? "Атрибут #{$attrId}";
            $values = $attr['values'] ?? [];
            
            // Собираем все значения атрибута
            $valueStrings = [];
            foreach ($values as $val) {
                $value = $val['value'] ?? null;
                if ($value !== null && $value !== '') {
                    $valueStrings[] = $value;
                }
            }
            
            if (!empty($valueStrings)) {
                $formatted[] = [
                    'name' => $name,
                    'value' => implode(', ', $valueStrings),
                ];
            }
        }
        
        return $formatted;
    }

    /**
     * Получить комиссии по категориям
     */
    public function getCommissions(): array
    {
        try {
            $response = $this->client->post('/v1/description-category/tree', [
                'language' => 'RU',
            ]);

            $commissions = [];
            $this->extractCommissions($response['result'] ?? [], $commissions);

            return $commissions;
        } catch (\Exception $e) {
            Log::error('Ozon getCommissions error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Рекурсивно развернуть дерево категорий
     */
    private function flattenCategories(array $categories, array &$result, ?string $parentName = null): void
    {
        foreach ($categories as $category) {
            $id = $category['description_category_id'] ?? $category['category_id'] ?? null;
            $name = $category['category_name'] ?? $category['title'] ?? '';

            if ($id) {
                $fullName = $parentName ? "{$parentName} > {$name}" : $name;
                $result[$id] = $fullName;
                $this->categoryCache[$id] = $fullName;
            }

            if (!empty($category['children'])) {
                $this->flattenCategories($category['children'], $result, $name);
            }
        }
    }

    /**
     * Извлечь комиссии из дерева категорий
     */
    private function extractCommissions(array $categories, array &$result): void
    {
        foreach ($categories as $category) {
            $id = $category['description_category_id'] ?? $category['category_id'] ?? null;
            $name = $category['category_name'] ?? $category['title'] ?? '';

            if ($id && $name) {
                // Комиссия может быть в разных полях в зависимости от версии API
                $commission = $category['commission_percent'] 
                    ?? $category['commission'] 
                    ?? 15; // дефолт 15%
                
                $result[$name] = [
                    'category_id' => $id,
                    'name' => $name,
                    'commission_percent' => (float) $commission,
                ];
            }

            if (!empty($category['children'])) {
                $this->extractCommissions($category['children'], $result);
            }
        }
    }
}
