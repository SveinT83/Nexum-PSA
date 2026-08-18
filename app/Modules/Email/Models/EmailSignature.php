<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSignature extends Model
{
    protected $table = 'email_signatures';

    protected $fillable = [
        'user_id',
        'name',
        'body_html',
        'body_text',
        'use_on_compose',
        'use_on_reply',
        'use_on_reply_all',
        'use_on_forward',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'use_on_compose' => 'boolean',
        'use_on_reply' => 'boolean',
        'use_on_reply_all' => 'boolean',
        'use_on_forward' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function enabledForMode(string $mode): bool
    {
        return match ($mode) {
            SendEmailComposerMessage::MODE_COMPOSE => (bool) $this->use_on_compose,
            SendEmailComposerMessage::MODE_REPLY => (bool) $this->use_on_reply,
            SendEmailComposerMessage::MODE_REPLY_ALL => (bool) $this->use_on_reply_all,
            SendEmailComposerMessage::MODE_FORWARD => (bool) $this->use_on_forward,
            default => false,
        };
    }
}
