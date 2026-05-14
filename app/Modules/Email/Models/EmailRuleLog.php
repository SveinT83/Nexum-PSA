<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailRuleLog extends Model
{
    protected $fillable = [
        'email_rule_id',
        'email_message_id',
        'status',
        'actions_json',
        'message',
    ];

    protected $casts = [
        'actions_json' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(EmailRule::class, 'email_rule_id');
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
