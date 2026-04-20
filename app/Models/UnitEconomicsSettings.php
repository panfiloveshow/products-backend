<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Настройки юнит-экономики (ручной ввод пользователя)
 * 
 * Хранит данные, которые пользователь вводит вручную:
 * - себестоимость
 * - налоги  
 * - рекламные расходы
 * - переопределение % выкупа
 */
class UnitEconomicsSettings extends Model
{
    use HasFactory;

    protected $table = 'unit_economics_settings';

    protected $fillable = [
        'integration_id',
        'sku',
        'cost_price',
        'drr_percent',
        'our_share_percent',
        'tax_percent',
        'vat_percent',
        'redemption_rate_override',
        // WB-специфичные
        'spp_percent',
        // Ozon markup overrides (исключения из наценки за нелокальную продажу)
        'is_select_only',
        'is_size_restricted',
        // Габариты НЕ редактируемые — берутся из API маркетплейса
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'drr_percent' => 'decimal:2',
        'our_share_percent' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'vat_percent' => 'decimal:2',
        'redemption_rate_override' => 'decimal:2',
        'length_mm' => 'decimal:2',
        'width_mm' => 'decimal:2',
        'height_mm' => 'decimal:2',
        'weight_g' => 'decimal:2',
        'spp_percent' => 'decimal:2',
        'is_select_only' => 'boolean',
        'is_size_restricted' => 'boolean',
    ];

    /**
     * Интеграция
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * Получить или создать настройки для товара
     */
    public static function getOrCreate(int $integrationId, string $sku): self
    {
        return self::firstOrCreate(
            ['integration_id' => $integrationId, 'sku' => $sku],
            [
                'cost_price' => 0,
                'drr_percent' => 0,
                'our_share_percent' => 0,
                'tax_percent' => 6,
                'vat_percent' => 0,
                'redemption_rate_override' => null,
            ]
        );
    }

    /**
     * Массовое обновление настроек
     */
    public static function bulkUpdate(int $integrationId, array $settings): int
    {
        $updated = 0;
        
        foreach ($settings as $sku => $data) {
            $setting = self::getOrCreate($integrationId, $sku);
            $setting->update($data);
            $updated++;
        }
        
        return $updated;
    }
}
