<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagerSupplyCalculation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['credential_binding', 'parameters', 'fingerprint'];
    protected $casts = ['parameters' => 'array', 'events' => 'array', 'queue_attempts' => 'integer',
        'expires_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'plan_created_at' => 'immutable_datetime',
        'queued_at' => 'immutable_datetime', 'run_started_at' => 'immutable_datetime', 'run_finished_at' => 'immutable_datetime',
        'last_queue_attempt_at' => 'immutable_datetime'];
}
