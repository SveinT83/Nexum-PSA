<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMailboxDelegation extends Model
{
    public const MAX_DURATION_DAYS = 31;

    protected $table = 'email_mailbox_delegations';

    protected $fillable = [
        'email_account_id',
        'owner_id',
        'delegate_id',
        'can_view',
        'can_organize',
        'can_send',
        'can_view_raw_source',
        'reason',
        'starts_at',
        'expires_at',
        'created_by',
        'revoked_by',
        'revocation_reason',
        'revoked_at',
    ];

    protected $casts = [
        'email_account_id' => 'integer',
        'owner_id' => 'integer',
        'delegate_id' => 'integer',
        'can_view' => 'boolean',
        'can_organize' => 'boolean',
        'can_send' => 'boolean',
        'can_view_raw_source' => 'boolean',
        'starts_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'created_by' => 'integer',
        'revoked_by' => 'integer',
        'revoked_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @param  Builder<EmailMailboxDelegation>  $query */
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
