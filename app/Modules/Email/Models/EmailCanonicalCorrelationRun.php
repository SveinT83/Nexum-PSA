<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCanonicalCorrelationRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'email_canonical_correlation_runs';

    protected $guarded = [];

    protected $casts = [
        'account_scope_json' => 'array',
        'frozen_min_message_id' => 'integer',
        'frozen_max_message_id' => 'integer',
        'message_cap' => 'integer',
        'group_cap' => 'integer',
        'pair_cap' => 'integer',
        'per_group_cap' => 'integer',
        'evidence_snapshot_byte_cap' => 'integer',
        'evidence_run_byte_cap' => 'integer',
        'scoped_evidence_bytes' => 'integer',
        'evidence_bytes_processed' => 'integer',
        'cursor_message_id' => 'integer',
        'scoped_message_count' => 'integer',
        'groups_processed' => 'integer',
        'pairs_processed' => 'integer',
        'candidate_count' => 'integer',
        'strong_count' => 'integer',
        'possible_count' => 'integer',
        'ambiguous_count' => 'integer',
        'different_count' => 'integer',
        'cancelled_by' => 'integer',
        'initial_scope_verified_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(
            EmailCanonicalCorrelationCandidate::class,
            'email_canonical_correlation_run_id',
        );
    }
}
