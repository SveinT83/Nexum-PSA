<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketRuleAfterCommitResult extends TicketRuleEvidence
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNRESOLVED = 'unresolved';

    protected $casts = [
        'run_id' => 'integer',
        'action_result_id' => 'integer',
        'ticket_id' => 'integer',
        'attempt_number' => 'integer',
        'retry_of_id' => 'integer',
        'attempt_count' => 'integer',
        'safe_payload_json' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return list<string> */
    protected static function terminalStatuses(): array
    {
        return [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_UNRESOLVED,
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TicketRuleRun::class, 'run_id');
    }

    public function actionResult(): BelongsTo
    {
        return $this->belongsTo(TicketRuleActionResult::class, 'action_result_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }
}
