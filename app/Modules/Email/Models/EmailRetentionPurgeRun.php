<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailRetentionPurgeRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL_FAILURE = 'partial_failure';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'retention_months',
        'cutoff_at',
        'status',
        'scanned_count',
        'eligible_count',
        'protected_count',
        'purged_count',
        'failed_count',
        'skipped_count',
        'reason_counts_json',
        'failure_code',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'retention_months' => 'integer',
        'cutoff_at' => 'datetime',
        'scanned_count' => 'integer',
        'eligible_count' => 'integer',
        'protected_count' => 'integer',
        'purged_count' => 'integer',
        'failed_count' => 'integer',
        'skipped_count' => 'integer',
        'reason_counts_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function attempts(): HasMany
    {
        return $this->hasMany(EmailRetentionPurgeAttempt::class, 'email_retention_purge_run_id');
    }
}
