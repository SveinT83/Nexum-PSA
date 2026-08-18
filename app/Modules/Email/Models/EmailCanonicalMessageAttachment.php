<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCanonicalMessageAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'email_canonical_message_attachments';

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
        'size_bytes' => 'integer',
        'is_inline' => 'boolean',
    ];

    public function canonicalMessage(): BelongsTo
    {
        return $this->belongsTo(EmailCanonicalMessage::class, 'canonical_email_message_id');
    }

    public function sourceAttachment(): BelongsTo
    {
        return $this->belongsTo(EmailAttachment::class, 'source_email_attachment_id');
    }
}
