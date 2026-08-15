<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationHubExecutionStep extends Model
{
    protected $fillable = [
        'execution_id', 'sequence', 'step_key', 'status', 'attempt', 'checkpoint',
        'failure_code', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'sequence' => 'integer', 'attempt' => 'integer', 'checkpoint' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];
}
