<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateProductDimensions extends Command
{
    protected $signature = 'products:update-dimensions {--integration= : ID интеграции}';
    protected $description = 'Обновить габариты товаров из характеристик';

    public function handle(): int
    {
        $query = Product::query();
        
        if ($integrationId = $this->option('integration')) {
            $query->where('integration_id', $integrationId);
        }
        
        $products = $query->get();
        $updated = 0;
        $skipped = 0;
        
        $this->info("Обновление габаритов для {$products->count()} товаров...");
        
        $bar = $this->output->createProgressBar($products->count());
        
        foreach ($products as $product) {
            $chars = $product->characteristics ?? [];
            
            // Извлекаем габариты из характеристик
            $depth = $this->extractNumber($chars['Глубина упаковки'] ?? $chars['Длина упаковки'] ?? null);
            $width = $this->extractNumber($chars['Ширина упаковки'] ?? null);
            $height = $this->extractNumber($chars['Высота упаковки'] ?? null);
            $weight = $this->extractNumber($chars['Вес'] ?? $chars['Вес товара'] ?? $chars['Вес с упаковкой'] ?? null);
            $volumeWeight = $this->extractNumber($chars['Объёмный вес'] ?? null);
            
            // Если объёмный вес не найден, рассчитываем из габаритов
            if (!$volumeWeight && $depth && $width && $height) {
                $volumeWeight = ($depth * $width * $height) / 1000000; // мм³ → л
            }
            
            // Обновляем только если есть хотя бы одно значение
            if ($depth || $width || $height || $weight || $volumeWeight) {
                $product->update([
                    'depth' => $depth ?: $product->depth,
                    'width' => $width ?: $product->width,
                    'height' => $height ?: $product->height,
                    'weight' => $weight ?: $product->weight,
                    'volume_weight' => $volumeWeight ?: $product->volume_weight,
                ]);
                $updated++;
            } else {
                $skipped++;
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();
        
        $this->info("✅ Обновлено: {$updated}, пропущено: {$skipped}");
        
        return Command::SUCCESS;
    }
    
    /**
     * Извлечь число из строки (например "150 мм" → 150)
     */
    private function extractNumber(?string $value): ?float
    {
        if (!$value) {
            return null;
        }
        
        // Убираем пробелы и заменяем запятую на точку
        $value = str_replace([' ', ','], ['', '.'], $value);
        
        // Извлекаем число
        if (preg_match('/[\d.]+/', $value, $matches)) {
            return (float) $matches[0];
        }
        
        return null;
    }
}
