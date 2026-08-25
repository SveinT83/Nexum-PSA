<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmailRuleExecutionAttempt extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_NOT_RUN = 'not_run';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'email_rule_id',
        'email_rule_version_id',
        'email_message_id',
        'email_mailbox_placement_id',
        'routing_phase',
        'status',
        'reason_code',
        'idempotency_key',
        'matched',
        'stop_processing',
        'conditions_json',
        'actions_json',
        'action_results_json',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'matched' => 'boolean',
        'stop_processing' => 'boolean',
        'conditions_json' => 'array',
        'actions_json' => 'array',
        'action_results_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            if ($attempt->getRawOriginal('finished_at') !== null && $attempt->isDirty()) {
                throw new LogicException('Completed Email rule execution attempts are immutable.');
            }
        });
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(EmailRule::class, 'email_rule_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(EmailRuleVersion::class, 'email_rule_version_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'email_mailbox_placement_id');
    }
}
