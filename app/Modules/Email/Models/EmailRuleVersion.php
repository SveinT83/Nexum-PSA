<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailRuleVersion extends Model
{
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'email_rule_id',
        'version_number',
        'status',
        'published_by',
        'published_at',
        'name',
        'description',
        'trigger',
        'routing_phase',
        'rule_kind',
        'owner_id',
        'weight',
        'is_active',
        'stop_processing',
        'conditions_json',
        'actions_json',
        'account_ids_json',
        'snapshot_hash',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'published_at' => 'datetime',
        'weight' => 'integer',
        'owner_id' => 'integer',
        'is_active' => 'boolean',
        'stop_processing' => 'boolean',
        'conditions_json' => 'array',
        'actions_json' => 'array',
        'account_ids_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $changed = array_values(array_diff(array_keys($version->getDirty()), ['updated_at']));
            $validSupersession = $changed === ['status']
                && $version->getOriginal('status') === self::STATUS_PUBLISHED
                && $version->status === self::STATUS_SUPERSEDED;

            if (! $validSupersession) {
                throw new \LogicException('Published Email rule versions are immutable.');
            }
        });
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(EmailRule::class, 'email_rule_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function executionAttempts(): HasMany
    {
        return $this->hasMany(EmailRuleExecutionAttempt::class, 'email_rule_version_id');
    }
}
