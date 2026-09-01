<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketRuleExecution extends TicketRuleEvidence
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_NO_CHANGE = 'no_change';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LOOP_BLOCKED = 'loop_blocked';

    protected $casts = [
        'run_id' => 'integer',
        'event_id' => 'integer',
        'ticket_rule_id' => 'integer',
        'rule_version_id' => 'integer',
        'order_position' => 'integer',
        'attempt_number' => 'integer',
        'retry_of_id' => 'integer',
        'trigger_relevant' => 'boolean',
        'conditions_matched' => 'boolean',
        'condition_evidence_json' => 'array',
        'change_summary_json' => 'array',
        'stop_requested' => 'boolean',
        'stop_applied' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    /** @return list<string> */
    protected static function terminalStatuses(): array
    {
        return [
            self::STATUS_UNMATCHED,
            self::STATUS_SUCCEEDED,
            self::STATUS_NO_CHANGE,
            self::STATUS_FAILED,
            self::STATUS_LOOP_BLOCKED,
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

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TicketRule::class, 'ticket_rule_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TicketRuleVersion::class, 'rule_version_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function actionResults(): HasMany
    {
        return $this->hasMany(TicketRuleActionResult::class, 'execution_id');
    }
}
