<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailRuleActionAttempt extends Model
{
    protected $fillable = [
        'email_rule_reprocess_item_id', 'email_rule_version_id', 'email_message_id',
        'email_mailbox_placement_id', 'action_position', 'action_type', 'action_snapshot_hash',
        'logical_key', 'active_logical_key', 'idempotency_key', 'attempt_number', 'status',
        'reason_code', 'result_json', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'action_position' => 'integer',
        'attempt_number' => 'integer',
        'result_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(EmailRuleReprocessItem::class, 'email_rule_reprocess_item_id');
    }
}
