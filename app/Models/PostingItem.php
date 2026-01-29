<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingItem extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'posting_id',
        'sku',
        'marketplace_sku',
        'offer_id',
        'barcode',
        'name',
        'image_url',
        'quantity',
        'price',
        'total_price',
        'commission_amount',
        'commission_percent',
        'payout',
        'weight',
        'volume',
        'status',
        'is_assembled',
        'is_packed',
        'meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'commission_percent' => 'decimal:2',
        'payout' => 'decimal:2',
        'weight' => 'decimal:3',
        'volume' => 'decimal:6',
        'is_assembled' => 'boolean',
        'is_packed' => 'boolean',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (PostingItem $item) {
            $item->total_price = $item->quantity * ($item->price ?? 0);
        });

        static::saved(function (PostingItem $item) {
            $item->posting->recalculateTotals();
        });

        static::deleted(function (PostingItem $item) {
            $item->posting->recalculateTotals();
        });
    }

    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }
}
