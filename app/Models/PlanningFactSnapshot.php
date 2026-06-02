<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanningFactSnapshot extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'auto_supply_plan_id',
        'integration_id',
        'marketplace',
        'status',
        'captured_at',
        'params_json',
        'facts_freshness_json',
        'planning_sources_json',
        'demand_facts_json',
        'stock_facts_json',
        'supply_facts_json',
        'economics_facts_json',
        'constraints_facts_json',
        'summary_json',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'params_json' => 'array',
        'facts_freshness_json' => 'array',
        'planning_sources_json' => 'array',
        'demand_facts_json' => 'array',
        'stock_facts_json' => 'array',
        'supply_facts_json' => 'array',
        'economics_facts_json' => 'array',
        'constraints_facts_json' => 'array',
        'summary_json' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AutoSupplyPlan::class, 'auto_supply_plan_id');
    }
}
