<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketMessage extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'ticket_id',
        'author_id',
        'author_type',
        'type',
        'visibility',
        'subject',
        'body',
        'attachments',
        'metadata',
        'read_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'metadata' => 'array',
        'read_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function fileAttachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }
}
