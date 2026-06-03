<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Сохранённая per-SKU рекламная статистика Ozon (CPC) по периоду.
 *
 * @property int $integration_id
 * @property string $date_from
 * @property string $date_to
 * @property string $status
 * @property array $payload
 * @property \Illuminate\Support\Carbon|null $fetched_at
 */
class OzonAdStat extends Model
{
    protected $table = 'ozon_ad_stats';

    protected $fillable = [
        'integration_id',
        'date_from',
        'date_to',
        'status',
        'payload',
        'fetched_at',
    ];

    protected $casts = [
        'integration_id' => 'integer',
        'payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
