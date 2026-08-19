<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;

class EmailMailDraftLock extends Model
{
    protected $table = 'email_mail_draft_locks';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'session_id',
        'expires_at',
        'version_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
