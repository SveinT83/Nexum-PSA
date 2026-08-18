<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailLiveProjectionPublication extends Model
{
    public const PHASE_OWNER = 'owner';

    public const PHASE_GRANTS = 'grants';

    public const PHASE_DELEGATIONS = 'delegations';

    public const PHASE_BREAK_GLASS = 'break_glass';

    public const PHASE_ACTIVE_USERS = 'active_users';

    public const PHASE_SEALED = 'sealed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SEALED = 'sealed';

    public const STATUS_BLOCKED = 'blocked';

    protected $table = 'email_live_projection_publications';

    protected $fillable = [
        'source_change_id',
        'source_stream_id',
        'source_stream_type',
        'email_account_id',
        'source_at',
        'frozen_owner_user_id',
        'account_audience_generation',
        'global_active_user_generation',
        'global_content_audience_generation',
        'global_content_ability_generation',
        'grant_through_id',
        'delegation_through_id',
        'break_glass_through_id',
        'active_user_through_id',
        'phase',
        'candidate_cursor_id',
        'status',
        'claim_token',
        'page_through_id',
        'page_row_count',
        'attempt_count',
        'page_count',
        'last_attempt_at',
        'next_attempt_at',
        'completed_at',
        'error_code',
        'delivery_summary_status',
        'delivery_through_id',
        'delivery_cursor_id',
        'delivery_count',
        'delivery_appended_count',
        'delivery_suppressed_count',
        'delivery_claim_token',
        'delivery_page_through_id',
        'delivery_page_row_count',
        'delivery_attempt_count',
        'delivery_page_count',
        'delivery_last_attempt_at',
        'delivery_next_attempt_at',
        'delivery_sealed_at',
        'delivery_error_code',
    ];

    protected $casts = [
        'source_change_id' => 'integer',
        'source_stream_id' => 'integer',
        'email_account_id' => 'integer',
        'source_at' => 'datetime',
        'frozen_owner_user_id' => 'integer',
        'account_audience_generation' => 'integer',
        'global_active_user_generation' => 'integer',
        'global_content_audience_generation' => 'integer',
        'global_content_ability_generation' => 'integer',
        'grant_through_id' => 'integer',
        'delegation_through_id' => 'integer',
        'break_glass_through_id' => 'integer',
        'active_user_through_id' => 'integer',
        'candidate_cursor_id' => 'integer',
        'page_through_id' => 'integer',
        'page_row_count' => 'integer',
        'attempt_count' => 'integer',
        'page_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'delivery_through_id' => 'integer',
        'delivery_cursor_id' => 'integer',
        'delivery_count' => 'integer',
        'delivery_appended_count' => 'integer',
        'delivery_suppressed_count' => 'integer',
        'delivery_page_through_id' => 'integer',
        'delivery_page_row_count' => 'integer',
        'delivery_attempt_count' => 'integer',
        'delivery_page_count' => 'integer',
        'delivery_last_attempt_at' => 'datetime',
        'delivery_next_attempt_at' => 'datetime',
        'delivery_sealed_at' => 'datetime',
    ];

    public function sourceChange(): BelongsTo
    {
        return $this->belongsTo(EmailLiveProjectionChange::class, 'source_change_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EmailLiveProjectionDelivery::class, 'publication_id');
    }
}
