<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WildberriesSppSnapshot extends Model
{
    protected $fillable = [
        'integration_id',
        'sku',
        'nm_id',
        'spp_percent',
        'seller_price',
        'customer_price',
        'source',
        'observed_at',
    ];

    protected $casts = [
        'spp_percent' => 'decimal:2',
        'seller_price' => 'decimal:2',
        'customer_price' => 'decimal:2',
        'observed_at' => 'datetime',
    ];
}
