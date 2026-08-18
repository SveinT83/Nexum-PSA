<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessageUserState extends Model
{
    protected $table = 'email_message_user_states';

    protected $fillable = [
        'email_message_id',
        'user_id',
        'access_epoch',
        'last_opened_placement_id',
        'is_unread',
        'opened_count',
        'first_opened_at',
        'last_opened_at',
        'marked_read_at',
        'marked_unread_at',
    ];

    protected $casts = [
        'is_unread' => 'boolean',
        'access_epoch' => 'integer',
        'opened_count' => 'integer',
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'marked_read_at' => 'datetime',
        'marked_unread_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lastOpenedPlacement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'last_opened_placement_id');
    }
}
