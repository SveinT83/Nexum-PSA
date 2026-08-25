<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailSharedDraftLock extends Model
{
    protected $table = 'email_shared_draft_locks';

    protected $fillable = [
        'public_id',
        'email_composer_draft_id',
        'draft_generation_id',
        'email_account_id',
        'email_conversation_id',
        'source_email_mailbox_placement_id',
        'holder_id',
        'lease_token_hash',
        'fencing_token',
        'content_version',
        'acquired_at',
        'renewed_at',
        'lease_expires_at',
        'released_at',
        'release_reason_code',
    ];

    protected $hidden = [
        'lease_token_hash',
        'draft_generation_id',
    ];

    protected $casts = [
        'fencing_token' => 'integer',
        'content_version' => 'integer',
        'acquired_at' => 'datetime',
        'renewed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $lock): void {
            $lock->public_id = $lock->public_id ?: (string) Str::uuid();
        });
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(EmailComposerDraft::class, 'email_composer_draft_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EmailConversation::class, 'email_conversation_id');
    }

    public function sourcePlacement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'source_email_mailbox_placement_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_id');
    }

    public function isActive(): bool
    {
        return $this->holder_id !== null
            && $this->lease_expires_at !== null
            && $this->lease_expires_at->isFuture();
    }
}
