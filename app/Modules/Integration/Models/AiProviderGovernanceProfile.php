<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderGovernanceProfile extends Model
{
    protected $fillable = [
        'ai_provider_id',
        'purpose',
        'recipient_name',
        'processing_regions',
        'support_regions',
        'dpa_status',
        'dpa_reference',
        'subprocessor_notes',
        'transfer_assessment',
        'retention_declaration',
        'training_declaration',
        'dpia_status',
        'dpia_rationale',
        'allowed_processing_modes',
        'maximum_data_profile',
        'is_approved',
        'is_active',
        'expires_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'processing_regions' => 'array',
        'support_regions' => 'array',
        'allowed_processing_modes' => 'array',
        'is_approved' => 'boolean',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function isComplete(): bool
    {
        return filled($this->purpose)
            && filled($this->recipient_name)
            && count($this->processing_regions ?? []) > 0
            && $this->dpa_status === 'approved'
            && filled($this->transfer_assessment)
            && filled($this->retention_declaration)
            && filled($this->training_declaration)
            && in_array($this->dpia_status, ['completed', 'not_required'], true)
            && filled($this->dpia_rationale)
            && $this->reviewed_by !== null
            && $this->reviewed_at !== null;
    }
}
