<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailRuleReprocessItem extends Model
{
    protected $fillable = [
        'email_rule_reprocess_run_id', 'email_message_id', 'email_mailbox_placement_id',
        'email_account_id', 'source_fingerprint', 'status', 'reason_code', 'matched',
        'action_summary_json', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'matched' => 'boolean',
        'action_summary_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailRuleReprocessRun::class, 'email_rule_reprocess_run_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'email_mailbox_placement_id');
    }

    public function actionAttempts(): HasMany
    {
        return $this->hasMany(EmailRuleActionAttempt::class, 'email_rule_reprocess_item_id');
    }
}
