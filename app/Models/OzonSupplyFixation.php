<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class OzonSupplyFixation extends Model
{
    use HasFactory;

    protected $table = 'ozon_supply_fixations';

    protected $fillable = [
        'integration_id',
        'supply_id',
        'sku',
        'offer_id',
        'shipping_cluster_id',
        'shipping_cluster_name',
        'fixation_base_date',
        'fixed_until',
        'tariff_version',
        'markup_version',
        'announcement_effective_from',
        'source',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'fixation_base_date' => 'date',
        'fixed_until' => 'date',
        'announcement_effective_from' => 'date',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class, 'supply_id');
    }

    public function scopeForProduct($query, int $integrationId, string $sku)
    {
        return $query->where('integration_id', $integrationId)->where('sku', $sku);
    }

    public function scopeActiveWindow($query, Carbon|string|null $date = null)
    {
        $date = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $query
            ->where('is_active', true)
            ->whereDate('fixation_base_date', '<=', $date)
            ->whereDate('fixed_until', '>=', $date);
    }
}
