<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Команда моста Uzum: «дёрни эндпоинт кабинета» (см. UzumExtensionController::commands).
 */
class UzumExtensionCommand extends Model
{
    use HasUuids;

    protected $fillable = [
        'integration_id', 'command_key', 'method', 'path', 'body',
        'status', 'expires_at', 'http_status', 'response', 'error',
    ];

    protected $casts = [
        'body' => 'array',
        'response' => 'array',
        'expires_at' => 'datetime',
    ];
}
