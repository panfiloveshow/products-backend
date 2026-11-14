<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerFbsOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['store_binding', 'resource_key', 'plan'];

    protected $casts = ['plan' => 'array', 'result' => 'array', 'confirmations' => 'array', 'expires_at' => 'immutable_datetime', 'execution_started_at' => 'immutable_datetime', 'native_started_at' => 'immutable_datetime'];
}
