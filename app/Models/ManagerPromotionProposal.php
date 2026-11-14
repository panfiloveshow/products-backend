<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerPromotionProposal extends Model
{
    protected $table = 'manager_promotion_proposals';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'constraints' => 'array',
        'scenario' => 'array',
        'binding' => 'array',
        'approval' => 'array',
        'executor_receipt' => 'array',
        'events' => 'array',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
