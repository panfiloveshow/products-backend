<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerFbsExecutionOperation extends Model
{
    protected $table = 'manager_fbs_execution_operations';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['plan' => 'array', 'result' => 'array', 'expires_at' => 'immutable_datetime'];
    }
}
