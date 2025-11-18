<?php

namespace App\Domain\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailMessage extends Model
{
    protected $table = 'email_messages';

    protected $fillable = [
        'account_id', 'mailbox', 'imap_uid', 'message_id', 'subject',
        'from_name', 'from_email', 'to_json', 'cc_json', 'headers_json',
        'in_reply_to', 'references', 'received_at', 'size_bytes', 'is_oversize',
        'state', 'labels_json', 'body_html_sanitized', 'body_text',
        'raw_path', 'attachments_count', 'checksum_sha1', 'ticket_id',
    ];

    protected $casts = [
        'to_json' => 'array',
        'cc_json' => 'array',
        'headers_json' => 'array',
        'labels_json' => 'array',
        'received_at' => 'datetime',
        'is_oversize' => 'boolean',
        'attachments_count' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class, 'message_id');
    }
}
