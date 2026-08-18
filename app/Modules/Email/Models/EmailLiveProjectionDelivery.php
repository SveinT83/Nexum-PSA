<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLiveProjectionDelivery extends Model
{
    public const AUTHORITY_OWNER = 'owner';

    public const AUTHORITY_GRANT = 'grant';

    public const AUTHORITY_DELEGATION = 'delegation';

    public const AUTHORITY_BREAK_GLASS = 'break_glass';

    public const AUTHORITY_ACTIVE_USER = 'active_user';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_APPENDED = 'appended';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_BLOCKED = 'blocked';

    protected $table = 'email_live_projection_deliveries';

    protected $fillable = [
        'publication_id',
        'source_change_id',
        'user_id',
        'authority_kind',
        'authority_id',
        'authority_enable_generation',
        'content_authority_path_id',
        'frozen_content_authority_generation',
        'frozen_user_authorization_epoch',
        'derived_change_id',
        'derived_stream_id',
        'status',
        'claim_token',
        'attempt_count',
        'last_attempt_at',
        'next_attempt_at',
        'completed_at',
        'error_code',
    ];

    protected $casts = [
        'publication_id' => 'integer',
        'source_change_id' => 'integer',
        'user_id' => 'integer',
        'authority_id' => 'integer',
        'authority_enable_generation' => 'integer',
        'content_authority_path_id' => 'integer',
        'frozen_content_authority_generation' => 'integer',
        'frozen_user_authorization_epoch' => 'integer',
        'derived_change_id' => 'integer',
        'derived_stream_id' => 'integer',
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(EmailLiveProjectionPublication::class, 'publication_id');
    }

    public function sourceChange(): BelongsTo
    {
        return $this->belongsTo(EmailLiveProjectionChange::class, 'source_change_id');
    }

    public function derivedChange(): BelongsTo
    {
        return $this->belongsTo(EmailLiveProjectionChange::class, 'derived_change_id');
    }
}
