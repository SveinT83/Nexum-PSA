<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailSharedDraftEvent extends Model
{
    public const TYPE_SHARED = 'shared';

    public const TYPE_ACQUIRED = 'acquired';

    public const TYPE_EXPIRED_TAKEOVER = 'expired_takeover';

    public const TYPE_RELEASED = 'released';

    public const TYPE_REBASED = 'rebased';

    public const TYPE_STALE = 'stale';

    public const TYPE_DISCARDED = 'discarded';

    public const TYPE_SENT = 'sent';

    protected $table = 'email_shared_draft_events';

    protected $fillable = [
        'public_id',
        'email_composer_draft_id',
        'email_shared_draft_lock_id',
        'actor_id',
        'event_type',
        'fencing_token',
        'content_version',
        'safe_reason_code',
        'idempotency_key',
        'occurred_at',
    ];

    protected $casts = [
        'fencing_token' => 'integer',
        'content_version' => 'integer',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->public_id = $event->public_id ?: (string) Str::uuid();
            $event->occurred_at ??= now();
        });
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(EmailComposerDraft::class, 'email_composer_draft_id');
    }

    public function lock(): BelongsTo
    {
        return $this->belongsTo(EmailSharedDraftLock::class, 'email_shared_draft_lock_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
