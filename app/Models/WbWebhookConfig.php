<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WbWebhookConfig extends Model
{
    protected $table = 'wb_webhook_configs';

    protected $fillable = [
        'integration_id',
        'webhook_url',
        'secret_key',
        'is_active',
        'last_event_at',
        'events_count',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'last_event_at' => 'datetime',
        'events_count'  => 'integer',
    ];
}
