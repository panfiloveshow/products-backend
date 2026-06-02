<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoSupplyConstraintFile extends Model
{
    protected $fillable = [
        'integration_id',
        'marketplace',
        'file_name',
        'file_size_bytes',
        'file_hash',
        'parser_version',
        'rows_total',
        'constraints_count',
        'warnings_count',
        'cluster_constraints_json',
        'warehouse_constraints_json',
        'summary_json',
        'warnings_json',
        'parsed_at',
        'last_used_at',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'rows_total' => 'integer',
        'constraints_count' => 'integer',
        'warnings_count' => 'integer',
        'cluster_constraints_json' => 'array',
        'warehouse_constraints_json' => 'array',
        'summary_json' => 'array',
        'warnings_json' => 'array',
        'parsed_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPlanMetadata(): array
    {
        return [
            'constraint_file_id' => $this->id,
            'marketplace' => $this->marketplace,
            'file_name' => $this->file_name,
            'file' => [
                'name' => $this->file_name,
                'size_bytes' => $this->file_size_bytes,
                'sha256' => $this->file_hash,
                'parsed_at' => $this->parsed_at?->toIso8601String(),
            ],
            'summary' => $this->summary_json ?? [],
            'warnings' => $this->warnings_json ?? [],
        ];
    }
}
