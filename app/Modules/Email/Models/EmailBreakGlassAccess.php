<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailBreakGlassAccess extends Model
{
    public const MAX_DURATION_MINUTES = 120;

    protected $table = 'email_break_glass_accesses';

    protected $fillable = [
        'email_account_id',
        'actor_id',
        'can_view_content',
        'can_search',
        'can_download_attachments',
        'can_view_raw_source',
        'reason',
        'starts_at',
        'expires_at',
        'revoked_by',
        'revocation_reason',
        'revoked_at',
        'owner_notification_sent_at',
        'security_notification_sent_at',
    ];

    // Written only by EmailLiveAuthorityCoordinator.
    protected $guarded = ['email_live_enable_generation'];

    protected $casts = [
        'email_account_id' => 'integer',
        'actor_id' => 'integer',
        'can_view_content' => 'boolean',
        'can_search' => 'boolean',
        'can_download_attachments' => 'boolean',
        'can_view_raw_source' => 'boolean',
        'starts_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'revoked_by' => 'integer',
        'revoked_at' => 'immutable_datetime',
        'owner_notification_sent_at' => 'immutable_datetime',
        'security_notification_sent_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @param  Builder<EmailBreakGlassAccess>  $query */
    public function scopeEffective(Builder $query, mixed $at = null): Builder
    {
        $at ??= now();

        return $query
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where('expires_at', '>', $at);
    }

    public function isEffectiveAt(mixed $at = null): bool
    {
        $at ??= now();

        return $this->revoked_at === null
            && $this->starts_at !== null
            && $this->expires_at !== null
            && $this->starts_at->lessThanOrEqualTo($at)
            && $this->expires_at->greaterThan($at);
    }
}
