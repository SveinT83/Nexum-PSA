<?php

namespace App\Modules\LeadIntelligence\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadResearchRun extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'lead_segment_id',
        'status',
        'started_at',
        'finished_at',
        'summary_json',
        'tokens_used',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'summary_json' => 'array',
        'tokens_used' => 'integer',
    ];

    public function segment(): BelongsTo
    {
        return $this->belongsTo(LeadSegment::class, 'lead_segment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(LeadSourceEvidence::class, 'lead_research_run_id');
    }
}

