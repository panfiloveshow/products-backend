<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель настроек поставок
 */
class SupplySettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'integration_id',
        'default_sales_window',
        'target_days_a',
        'target_days_b',
        'target_days_c',
        'safety_stock_days',
        'safety_stock_percent',
        'safety_stock_mode',
        'default_lead_time_days',
        'min_order_qty',
        'default_pack_multiple',
        'oos_risk_days',
        'overstock_days',
        'preferred_weekdays',
        'preferred_time_from',
        'preferred_time_to',
        'max_supplies_per_day',
        'max_items_per_supply',
        'max_qty_per_supply',
        'auto_book_slot',
        'auto_book_oos_threshold_days',
        'notify_no_slots',
        'notify_no_slots_days',
        'notify_oos_risk',
        'notify_stuck_supply',
        'notify_stuck_hours',
        'notify_api_errors',
        'notify_acceptance_issues',
        'notification_channels',
        'notification_recipients',
        'excluded_skus',
        'excluded_categories',
        'restricted_skus',
        'is_active',
        'custom_rules',
    ];

    protected $casts = [
        'target_days_a' => 'integer',
        'target_days_b' => 'integer',
        'target_days_c' => 'integer',
        'safety_stock_days' => 'integer',
        'safety_stock_percent' => 'decimal:2',
        'default_lead_time_days' => 'integer',
        'min_order_qty' => 'integer',
        'default_pack_multiple' => 'integer',
        'oos_risk_days' => 'integer',
        'overstock_days' => 'integer',
        'preferred_weekdays' => 'array',
        'max_supplies_per_day' => 'integer',
        'max_items_per_supply' => 'integer',
        'max_qty_per_supply' => 'integer',
        'auto_book_slot' => 'boolean',
        'auto_book_oos_threshold_days' => 'integer',
        'notify_no_slots' => 'boolean',
        'notify_no_slots_days' => 'integer',
        'notify_oos_risk' => 'boolean',
        'notify_stuck_supply' => 'boolean',
        'notify_stuck_hours' => 'integer',
        'notify_api_errors' => 'boolean',
        'notify_acceptance_issues' => 'boolean',
        'notification_channels' => 'array',
        'notification_recipients' => 'array',
        'excluded_skus' => 'array',
        'excluded_categories' => 'array',
        'restricted_skus' => 'array',
        'is_active' => 'boolean',
        'custom_rules' => 'array',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * Получить целевые дни покрытия по приоритету ABC
     */
    public function getTargetDays(string $priority): int
    {
        return match (strtoupper($priority)) {
            'A' => $this->target_days_a,
            'B' => $this->target_days_b,
            'C' => $this->target_days_c,
            default => $this->target_days_b,
        };
    }

    /**
     * Рассчитать страховой запас
     */
    public function calculateSafetyStock(float $avgDailySales): int
    {
        return match ($this->safety_stock_mode) {
            'days' => (int) ceil($avgDailySales * $this->safety_stock_days),
            'percent' => (int) ceil($avgDailySales * $this->target_days_b * ($this->safety_stock_percent / 100)),
            'max' => max(
                (int) ceil($avgDailySales * $this->safety_stock_days),
                (int) ceil($avgDailySales * $this->target_days_b * ($this->safety_stock_percent / 100))
            ),
            default => (int) ceil($avgDailySales * $this->safety_stock_days),
        };
    }

    /**
     * Проверить, исключён ли SKU
     */
    public function isSkuExcluded(string $sku): bool
    {
        return in_array($sku, $this->excluded_skus ?? []);
    }

    /**
     * Проверить, ограничен ли SKU
     */
    public function isSkuRestricted(string $sku): bool
    {
        return in_array($sku, $this->restricted_skus ?? []);
    }

    /**
     * Получить или создать настройки для интеграции
     */
    public static function getOrCreate(int $integrationId): self
    {
        return self::firstOrCreate(
            ['integration_id' => $integrationId],
            [
                'default_sales_window' => '14d',
                'target_days_a' => 21,
                'target_days_b' => 14,
                'target_days_c' => 7,
                'safety_stock_days' => 3,
                'safety_stock_percent' => 10,
                'safety_stock_mode' => 'days',
                'default_lead_time_days' => 3,
                'min_order_qty' => 1,
                'default_pack_multiple' => 1,
                'oos_risk_days' => 3,
                'overstock_days' => 60,
                'preferred_weekdays' => [1, 2, 3, 4, 5],
                'max_supplies_per_day' => 3,
                'max_items_per_supply' => 100,
                'max_qty_per_supply' => 10000,
                'auto_book_slot' => false,
                'auto_book_oos_threshold_days' => 2,
                'notify_no_slots' => true,
                'notify_no_slots_days' => 7,
                'notify_oos_risk' => true,
                'notify_stuck_supply' => true,
                'notify_stuck_hours' => 24,
                'notify_api_errors' => true,
                'notify_acceptance_issues' => true,
                'notification_channels' => ['email'],
                'is_active' => true,
            ]
        );
    }
}
