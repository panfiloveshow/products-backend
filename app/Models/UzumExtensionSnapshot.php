<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UzumExtensionSnapshot extends Model
{
    protected $fillable = [
        'integration_id',
        'shop_id',
        'payload_type',
        'raw_payload',
        'items_count',
        'accepted_count',
        'rejected_count',
        'status',
        'error_message',
        'extension_version',
        'extractor_version',
        'collected_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'items_count' => 'integer',
        'accepted_count' => 'integer',
        'rejected_count' => 'integer',
        'collected_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }
}
