<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailRetentionPurgeAttempt extends Model
{
    public const STATUS_CHECKING = 'checking';

    public const STATUS_PROTECTED = 'protected';

    public const STATUS_PURGED = 'purged';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'email_retention_purge_run_id',
        'email_message_id',
        'account_id',
        'status',
        'reasons_json',
        'had_raw_payload',
        'local_attachment_file_count',
        'failure_code',
        'retry_after',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'email_message_id' => 'integer',
        'account_id' => 'integer',
        'reasons_json' => 'array',
        'had_raw_payload' => 'boolean',
        'local_attachment_file_count' => 'integer',
        'retry_after' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailRetentionPurgeRun::class, 'email_retention_purge_run_id');
    }
}
