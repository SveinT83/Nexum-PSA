<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketRuleActionResult extends TicketRuleEvidence
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_NO_CHANGE = 'no_change';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_RUN = 'not_run';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_QUEUED = 'queued';

    protected $casts = [
        'run_id' => 'integer',
        'event_id' => 'integer',
        'execution_id' => 'integer',
        'ticket_id' => 'integer',
        'ticket_rule_id' => 'integer',
        'rule_version_id' => 'integer',
        'position' => 'integer',
        'attempt_number' => 'integer',
        'retry_of_id' => 'integer',
        'action_snapshot_json' => 'array',
        'change_json' => 'array',
        'authorization_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    /** @return list<string> */
    protected static function terminalStatuses(): array
    {
        return [
            self::STATUS_SUCCEEDED,
            self::STATUS_NO_CHANGE,
            self::STATUS_FAILED,
            self::STATUS_NOT_RUN,
            self::STATUS_ROLLED_BACK,
            self::STATUS_QUEUED,
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TicketRuleRun::class, 'run_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(TicketRuleEvent::class, 'event_id');
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(TicketRuleExecution::class, 'execution_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TicketRuleVersion::class, 'rule_version_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TicketRule::class, 'ticket_rule_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function afterCommitResults(): HasMany
    {
        return $this->hasMany(TicketRuleAfterCommitResult::class, 'action_result_id');
    }
}
