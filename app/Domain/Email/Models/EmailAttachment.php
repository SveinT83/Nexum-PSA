<?php

namespace App\Domain\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAttachment extends Model
{
    protected $table = 'email_attachments';

    protected $fillable = [
        'message_id', 'filename', 'content_type', 'size_bytes', 'disk', 'path',
        'is_inline', 'cid', 'checksum_sha1',
    ];

    protected $casts = [
        'is_inline' => 'boolean',
        'size_bytes' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'message_id');
    }
}
