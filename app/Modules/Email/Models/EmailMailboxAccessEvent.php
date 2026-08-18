<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmailMailboxAccessEvent extends Model
{
    public const TYPE_DELEGATION_CREATED = 'delegation_created';

    public const TYPE_DELEGATION_REVOKED = 'delegation_revoked';

    public const TYPE_DELEGATION_EXPIRED_AT_USE = 'delegation_expired_at_use';

    public const TYPE_BREAK_GLASS_ACTIVATED = 'break_glass_activated';

    public const TYPE_BREAK_GLASS_REVOKED = 'break_glass_revoked';

    public const TYPE_BREAK_GLASS_EXPIRED_AT_USE = 'break_glass_expired_at_use';

    public const TYPE_MAILBOX_VIEW = 'mailbox_view';

    public const TYPE_MESSAGE_VIEW = 'message_view';

    public const TYPE_ATTACHMENT_DOWNLOAD = 'attachment_download';

    public const TYPE_RAW_SOURCE_VIEW = 'raw_source_view';

    public const TYPE_SEARCH_EXECUTION = 'search_execution';

    public $timestamps = false;

    protected $table = 'email_mailbox_access_events';

    protected $fillable = [
        'email_account_id',
        'actor_id',
        'affected_user_id',
        'email_mailbox_delegation_id',
        'email_break_glass_access_id',
        'event_type',
        'operation',
        'resource_type',
        'resource_id',
        'reason_code',
        'metadata_json',
        'idempotency_key',
        'occurred_at',
    ];

    protected $casts = [
        'email_account_id' => 'integer',
        'actor_id' => 'integer',
        'affected_user_id' => 'integer',
        'email_mailbox_delegation_id' => 'integer',
        'email_break_glass_access_id' => 'integer',
        'resource_id' => 'integer',
        'metadata_json' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Mailbox access events are append-only.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Mailbox access events are append-only.');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function affectedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affected_user_id');
    }

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxDelegation::class, 'email_mailbox_delegation_id');
    }

    public function breakGlassAccess(): BelongsTo
    {
        return $this->belongsTo(EmailBreakGlassAccess::class, 'email_break_glass_access_id');
    }
}
