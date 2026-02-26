<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WbBarcodeCost extends Model
{
    protected $table = 'wb_barcode_costs';

    protected $fillable = [
        'integration_id',
        'nm_id',
        'barcode',
        'chrt_id',
        'size_name',
        'cost_price',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
    ];

    public static function getCostMap(int $integrationId): array
    {
        return static::where('integration_id', $integrationId)
            ->whereNotNull('cost_price')
            ->get()
            ->mapWithKeys(fn($r) => [$r->barcode => (float) $r->cost_price])
            ->toArray();
    }
}
