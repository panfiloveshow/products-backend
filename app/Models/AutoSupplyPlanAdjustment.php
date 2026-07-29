<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoSupplyPlanAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'auto_supply_plan_id',
        'auto_supply_plan_line_id',
        'user_id',
        'action',
        'old_values_json',
        'new_values_json',
        'reason',
    ];

    protected $casts = [
        'old_values_json' => 'array',
        'new_values_json' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AutoSupplyPlan::class, 'auto_supply_plan_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(AutoSupplyPlanLine::class, 'auto_supply_plan_line_id');
    }
}
