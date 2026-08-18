<?php

namespace App\Modules\Email\Models;

use App\Modules\Email\Models\Concerns\HasImmutableProviderBindingVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailProviderDeletionCleanupAttempt extends Model
{
    use HasImmutableProviderBindingVersion;

    public const STATUS_CHECKING = 'checking';

    public const STATUS_PROTECTED = 'protected';

    public const STATUS_PURGED = 'purged';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $table = 'email_provider_deletion_cleanup_attempts';

    protected $fillable = [
        'email_provider_placement_finding_id',
        'account_id',
        'provider_binding_version',
        'email_message_id',
        'status',
        'reasons_json',
        'had_raw_payload',
        'local_attachment_file_count',
        'smart_inbox_suggestion_count',
        'failure_code',
        'retry_after',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'email_provider_placement_finding_id' => 'integer',
        'account_id' => 'integer',
        'provider_binding_version' => 'integer',
        'email_message_id' => 'integer',
        'reasons_json' => 'array',
        'had_raw_payload' => 'boolean',
        'local_attachment_file_count' => 'integer',
        'smart_inbox_suggestion_count' => 'integer',
        'retry_after' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(EmailProviderPlacementFinding::class, 'email_provider_placement_finding_id');
    }
}
