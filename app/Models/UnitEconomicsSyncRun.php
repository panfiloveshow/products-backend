<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitEconomicsSyncRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'version' => 'integer', 'requested_version' => 'integer', 'published_version' => 'integer',
        'stages' => 'array', 'required_stages' => 'array',
        'started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'finished_at' => 'datetime',
        'next_retry_at' => 'datetime', 'deadline_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
