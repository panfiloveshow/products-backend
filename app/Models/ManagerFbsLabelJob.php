<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerFbsLabelJob extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['tasks' => 'array', 'native_started_at' => 'immutable_datetime', 'last_checked_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    protected $hidden = ['store_binding', 'task_id', 'tasks'];
}
