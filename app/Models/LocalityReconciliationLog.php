<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalityReconciliationLog extends Model
{
    use HasFactory;

    public const VERDICT_MATCH = 'match';
    public const VERDICT_DRIFT = 'drift';
    public const VERDICT_MISMATCH = 'mismatch';

    protected $table = 'locality_reconciliation_log';

    protected $fillable = [
        'integration_id',
        'period_from',
        'period_to',
        'run_at',
        'source',
        'expected_base_logistics',
        'expected_non_local_markup',
        'actual_base_logistics',
        'actual_non_local_markup',
        'base_logistics_diff',
        'markup_diff',
        'base_logistics_diff_percent',
        'markup_diff_percent',
        'verdict',
        'operations_count',
        'postings_matched',
        'postings_missing',
        'details',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'run_at' => 'datetime',
        'expected_base_logistics' => 'decimal:2',
        'expected_non_local_markup' => 'decimal:2',
        'actual_base_logistics' => 'decimal:2',
        'actual_non_local_markup' => 'decimal:2',
        'base_logistics_diff' => 'decimal:2',
        'markup_diff' => 'decimal:2',
        'base_logistics_diff_percent' => 'decimal:2',
        'markup_diff_percent' => 'decimal:2',
        'operations_count' => 'integer',
        'postings_matched' => 'integer',
        'postings_missing' => 'integer',
        'details' => 'array',
    ];
}
