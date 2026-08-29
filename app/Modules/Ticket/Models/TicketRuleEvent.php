<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketRuleEvent extends TicketRuleEvidence
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_NO_CHANGE = 'no_change';

    public const STATUS_LOOP_BLOCKED = 'loop_blocked';

    public const LOOP_REASON_REPEATED_EVENT_FINGERPRINT = 'repeated_event_fingerprint';

    public const LOOP_REASON_DEPTH_BUDGET_EXCEEDED = 'depth_budget_exceeded';

    public const LOOP_REASON_EVALUATED_RULE_BUDGET_EXCEEDED = 'evaluated_rule_budget_exceeded';

    public const LOOP_REASON_ACTION_BUDGET_EXCEEDED = 'action_budget_exceeded';

    protected $casts = [
        'run_id' => 'integer',
        'ticket_id' => 'integer',
        'parent_event_id' => 'integer',
        'parent_action_result_id' => 'integer',
        'sequence' => 'integer',
        'changed_fields_json' => 'array',
        'before_json' => 'array',
        'after_json' => 'array',
        'initiator_id' => 'integer',
        'automation_actor_id' => 'integer',
        'chain_depth' => 'integer',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /** @return list<string> */
    protected static function terminalStatuses(): array
    {
        return [
            self::STATUS_PROCESSED,
            self::STATUS_NO_CHANGE,
            self::STATUS_LOOP_BLOCKED,
        ];
    }

    protected static function completionTimestampColumn(): string
    {
        return 'processed_at';
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TicketRuleRun::class, 'run_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    public function parentActionResult(): BelongsTo
    {
        return $this->belongsTo(TicketRuleActionResult::class, 'parent_action_result_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(TicketRuleExecution::class, 'event_id');
    }

    public function actionResults(): HasMany
    {
        return $this->hasMany(TicketRuleActionResult::class, 'event_id');
    }
}
