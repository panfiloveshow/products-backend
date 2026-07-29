<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutoSupplyPlanExecution extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'auto_supply_plan_id',
        'integration_id',
        'idempotency_key',
        'status',
        'plan_fingerprint',
        'request_json',
        'result_json',
        'error_json',
        'attempts',
        'initiated_by',
        'confirmed_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'request_json' => 'array',
        'result_json' => 'array',
        'error_json' => 'array',
        'attempts' => 'integer',
        'initiated_by' => 'integer',
        'confirmed_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AutoSupplyPlan::class, 'auto_supply_plan_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class, 'auto_supply_plan_execution_id');
    }
}
