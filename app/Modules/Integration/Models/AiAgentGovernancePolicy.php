<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAgentGovernancePolicy extends Model
{
    protected $fillable = [
        'ai_agent_id',
        'processing_mode',
        'maximum_data_profile',
        'is_approved',
        'expires_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }
}
