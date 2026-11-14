<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitEconomicsSyncState extends Model
{
    protected $primaryKey = 'integration_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'requested_version' => 'integer', 'observed_version' => 'integer',
        'published_version' => 'integer', 'publication_revision' => 'integer',
    ];
}
