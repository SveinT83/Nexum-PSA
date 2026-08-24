<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMailUserConversationAcknowledgement extends Model
{
    protected $table = 'email_mail_user_conversation_acknowledgements';

    protected $fillable = [
        'email_conversation_id',
        'user_id',
        'last_acknowledged_message_id',
        'acknowledged_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EmailConversation::class, 'email_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
