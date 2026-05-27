<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    protected $fillable = [
        'ticket_id',
        'ticket_message_id',
        'email_attachment_id',
        'uploaded_by',
        'source',
        'filename',
        'original_filename',
        'content_type',
        'size_bytes',
        'disk',
        'path',
        'checksum_sha1',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    public function sourceEmailAttachment(): BelongsTo
    {
        return $this->belongsTo(EmailAttachment::class, 'email_attachment_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
