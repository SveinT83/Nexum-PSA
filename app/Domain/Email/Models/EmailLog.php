<?php

namespace App\Domain\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    protected $table = 'email_logs';
    public $timestamps = true;

    protected $fillable = [
        'direction', 'account_id', 'email_message_id', 'scope', 'level', 'code', 'message', 'context_json',
    ];

    protected $casts = [
        'context_json' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
