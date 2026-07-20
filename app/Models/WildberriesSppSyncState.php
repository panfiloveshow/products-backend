<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WildberriesSppSyncState extends Model
{
    protected $fillable = [
        'integration_id',
        'status',
        'attempt',
        'updated_count',
        'total_count',
        'preserved_count',
        'source',
        'source_counts',
        'message',
        'last_error',
        'requested_at',
        'started_at',
        'finished_at',
        'last_success_at',
        'retry_at',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'updated_count' => 'integer',
        'total_count' => 'integer',
        'preserved_count' => 'integer',
        'source_counts' => 'array',
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_success_at' => 'datetime',
        'retry_at' => 'datetime',
    ];
}
