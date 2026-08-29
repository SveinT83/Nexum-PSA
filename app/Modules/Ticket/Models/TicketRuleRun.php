<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketRuleRun extends TicketRuleEvidence
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NO_CHANGE = 'no_change';

    public const STATUS_LOOP_BLOCKED = 'loop_blocked';

    protected $casts = [
        'ticket_id' => 'integer',
        'initiator_id' => 'integer',
        'automation_actor_id' => 'integer',
        'authority_generation' => 'integer',
        'mode' => 'string',
        'attempt_number' => 'integer',
        'retry_of_run_id' => 'integer',
        'published_version_ids' => 'array',
        'limits_json' => 'array',
        'counters_json' => 'array',
        'safe_summary_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    /** @return list<string> */
    protected static function terminalStatuses(): array
    {
        return [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_NO_CHANGE,
            self::STATUS_LOOP_BLOCKED,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_run_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketRuleEvent::class, 'run_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(TicketRuleExecution::class, 'run_id');
    }

    public function actionResults(): HasMany
    {
        return $this->hasMany(TicketRuleActionResult::class, 'run_id');
    }

    public function afterCommitResults(): HasMany
    {
        return $this->hasMany(TicketRuleAfterCommitResult::class, 'run_id');
    }
}
