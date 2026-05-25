<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WildberriesTariffSnapshot extends Model
{
    protected $fillable = [
        'integration_id',
        'marketplace',
        'tariff_type',
        'effective_date',
        'warehouse_id',
        'warehouse_name',
        'subject_id',
        'subject_name',
        'scheme',
        'payload',
        'fetched_at',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
