<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerOzonFboManualResolution extends Model
{
    protected $table = 'manager_ozon_fbo_manual_resolutions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'confirmations' => 'array',
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
