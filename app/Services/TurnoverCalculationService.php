<?php

namespace App\Services;

use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис расчёта корректной оборачиваемости с учётом дней наличия товара
 */
class TurnoverCalculationService
{
    /**
     * Рассчитать days_in_stock за последние N дней из InventoryHistory
     * 
     * @param string $sku
     * @param string $warehouseId
     * @param int $days Период для анализа (по умолчанию 30 дней)
     * @return array ['days_in_stock' => int, 'last_stockout_date' => ?string, 'last_restock_date' => ?string]
     */
    public function calculateDaysInStock(string $sku, string $warehouseId, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();
        
        // Получаем историю остатков за период
        $history = InventoryHistory::where('sku', $sku)
            ->where('warehouse_id', $warehouseId)
            ->where('date', '>=', $startDate)
            ->where('date', '<=', $endDate)
            ->orderBy('date')
            ->get(['date', 'quantity']);
        
        if ($history->isEmpty()) {
            // Нет истории — считаем что товар был в наличии все дни
            return [
                'days_in_stock' => $days,
                'last_stockout_date' => null,
                'last_restock_date' => null,
            ];
        }
        
        $daysInStock = 0;
        $lastStockoutDate = null;
        $lastRestockDate = null;
        $previousQuantity = null;
        
        foreach ($history as $record) {
            if ($record->quantity > 0) {
                $daysInStock++;
                
                // Если предыдущий день был out of stock, это дата пополнения
                if ($previousQuantity !== null && $previousQuantity == 0) {
                    $lastRestockDate = $record->date;
                }
            } else {
                // Товара нет — запоминаем дату
                $lastStockoutDate = $record->date;
            }
            
            $previousQuantity = $record->quantity;
        }
        
        // Если записей меньше чем дней в периоде, добавляем недостающие дни
        // (предполагаем что товар был в наличии в дни без записей)
        $recordedDays = $history->count();
        if ($recordedDays < $days) {
            $daysInStock += ($days - $recordedDays);
        }
        
        return [
            'days_in_stock' => $daysInStock,
            'last_stockout_date' => $lastStockoutDate,
            'last_restock_date' => $lastRestockDate,
        ];
    }
    
    /**
     * Рассчитать эффективную оборачиваемость с учётом дней наличия
     * 
     * @param int $quantity Текущий остаток
     * @param int $sales30Days Продажи за 30 дней
     * @param int $daysInStock Дней в наличии за 30 дней
     * @return array ['effective_daily_sales' => float, 'effective_turnover_days' => float]
     */
    public function calculateEffectiveTurnover(int $quantity, int $sales30Days, int $daysInStock): array
    {
        // Защита от деления на ноль
        if ($daysInStock <= 0) {
            $daysInStock = 1;
        }
        
        // Эффективные среднедневные продажи = продажи / дни в наличии
        // Это показывает реальную скорость продаж когда товар был доступен
        $effectiveDailySales = $sales30Days / $daysInStock;
        
        // Эффективная оборачиваемость = остаток / эффективные продажи
        if ($effectiveDailySales > 0) {
            $effectiveTurnoverDays = round($quantity / $effectiveDailySales, 1);
        } elseif ($quantity > 0) {
            // Нет продаж, но есть остаток — избыток
            $effectiveTurnoverDays = 999;
        } else {
            $effectiveTurnoverDays = 0;
        }
        
        return [
            'effective_daily_sales' => round($effectiveDailySales, 2),
            'effective_turnover_days' => $effectiveTurnoverDays,
        ];
    }
    
    /**
     * Обновить все записи InventoryWarehouse с корректной оборачиваемостью
     * 
     * @param string|null $marketplace Фильтр по маркетплейсу
     * @return array ['updated' => int, 'errors' => int]
     */
    public function recalculateAll(?string $marketplace = null): array
    {
        $updated = 0;
        $errors = 0;
        
        $query = InventoryWarehouse::query();
        
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }
        
        $query->chunk(500, function ($warehouses) use (&$updated, &$errors) {
            foreach ($warehouses as $wh) {
                try {
                    $this->recalculateForWarehouse($wh);
                    $updated++;
                } catch (\Exception $e) {
                    $errors++;
                    Log::warning('Turnover recalculation failed', [
                        'sku' => $wh->sku,
                        'warehouse_id' => $wh->warehouse_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
        
        return ['updated' => $updated, 'errors' => $errors];
    }
    
    /**
     * Пересчитать оборачиваемость для одной записи склада
     */
    public function recalculateForWarehouse(InventoryWarehouse $warehouse): void
    {
        // Получаем дни в наличии из истории
        $stockData = $this->calculateDaysInStock(
            $warehouse->sku,
            $warehouse->warehouse_id,
            30
        );
        
        // Рассчитываем эффективную оборачиваемость
        $turnoverData = $this->calculateEffectiveTurnover(
            $warehouse->quantity,
            $warehouse->sales_30_days ?? 0,
            $stockData['days_in_stock']
        );
        
        // Обновляем запись
        $warehouse->update([
            'days_in_stock_30' => $stockData['days_in_stock'],
            'effective_daily_sales' => $turnoverData['effective_daily_sales'],
            'effective_turnover_days' => $turnoverData['effective_turnover_days'],
            'last_stockout_date' => $stockData['last_stockout_date'],
            'last_restock_date' => $stockData['last_restock_date'],
        ]);
    }
}
