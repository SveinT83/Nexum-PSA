<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailLiveProjectionChange extends Model
{
    public const TYPE_MAIL_PROJECTION = 'mail_projection';

    public const TYPE_PERSONAL_STATE = 'personal_state';

    public const TYPE_AUTHORIZATION = 'authorization';

    public const TYPE_COLLABORATION = 'collaboration';

    public const TYPE_TAXONOMY = 'taxonomy';

    public const TYPE_TICKET_LINK = 'ticket_link';

    public const TYPE_ACCOUNT_STATE = 'account_state';

    public const ALLOWED_TYPES = [
        self::TYPE_MAIL_PROJECTION,
        self::TYPE_PERSONAL_STATE,
        self::TYPE_AUTHORIZATION,
        self::TYPE_COLLABORATION,
        self::TYPE_TAXONOMY,
        self::TYPE_TICKET_LINK,
        self::TYPE_ACCOUNT_STATE,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SEALED = 'sealed';

    public const STATUS_BLOCKED = 'blocked';

    protected $table = 'email_live_projection_changes';

    protected $fillable = [
        'stream_id',
        'version',
        'email_account_id',
        'idempotency_key',
        'change_types_json',
        'conversation_ids_json',
        'placement_ids_json',
        'conversation_id_count',
        'placement_id_count',
        'truncated',
        'publication_status',
        'available_at',
        'claim_token',
        'attempt_count',
        'last_attempt_at',
        'next_attempt_at',
        'published_at',
        'sealed_at',
        'retention_ready_at',
        'compact_delivery_count',
        'compact_appended_count',
        'compact_suppressed_count',
        'error_code',
    ];

    protected $casts = [
        'stream_id' => 'integer',
        'version' => 'integer',
        'email_account_id' => 'integer',
        'change_types_json' => 'array',
        'conversation_ids_json' => 'array',
        'placement_ids_json' => 'array',
        'conversation_id_count' => 'integer',
        'placement_id_count' => 'integer',
        'truncated' => 'boolean',
        'available_at' => 'datetime',
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'published_at' => 'datetime',
        'sealed_at' => 'datetime',
        'retention_ready_at' => 'datetime',
        'compact_delivery_count' => 'integer',
        'compact_appended_count' => 'integer',
        'compact_suppressed_count' => 'integer',
    ];

    public function stream(): BelongsTo
    {
        return $this->belongsTo(EmailLiveProjectionStream::class, 'stream_id');
    }

    public function publication(): HasOne
    {
        return $this->hasOne(EmailLiveProjectionPublication::class, 'source_change_id');
    }
}
