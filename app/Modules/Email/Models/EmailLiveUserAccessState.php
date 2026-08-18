<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLiveUserAccessState extends Model
{
    public const STATUS_SEALED = 'sealed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_BLOCKED = 'blocked';

    public const PHASE_DELEGATIONS = 'delegations';

    public const PHASE_BREAK_GLASS = 'break_glass';

    protected $table = 'email_live_user_access_states';

    protected $fillable = [
        'user_id',
        'authorization_epoch',
        'content_ability_enable_generation',
        'global_authorization_generation_seen',
        'next_boundary_at',
        'last_bounded_refresh_at',
        'recompute_status',
        'recompute_phase',
        'delegation_through_id',
        'break_glass_through_id',
        'recompute_cursor_id',
        'recompute_boundary_at',
        'claim_token',
        'page_through_id',
        'page_row_count',
        'attempt_count',
        'page_count',
        'last_attempt_at',
        'completed_at',
        'error_code',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'authorization_epoch' => 'integer',
        'content_ability_enable_generation' => 'integer',
        'global_authorization_generation_seen' => 'integer',
        'next_boundary_at' => 'datetime',
        'last_bounded_refresh_at' => 'datetime',
        'delegation_through_id' => 'integer',
        'break_glass_through_id' => 'integer',
        'recompute_cursor_id' => 'integer',
        'recompute_boundary_at' => 'datetime',
        'page_through_id' => 'integer',
        'page_row_count' => 'integer',
        'attempt_count' => 'integer',
        'page_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
