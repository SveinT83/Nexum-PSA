<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketRule extends Model
{
    use SoftDeletes;

    public const TRIGGER_CREATE = 'on_create';

    public const LIFECYCLE_LEGACY = 'legacy';

    public const LIFECYCLE_PUBLISHED = 'published';

    public const LIFECYCLE_DISABLED = 'disabled';

    public const LIFECYCLE_DELETED = 'deleted';

    public const COMPATIBILITY_UNVERSIONED = 'unversioned';

    public const COMPATIBILITY_ELIGIBLE = 'eligible';

    public const COMPATIBILITY_INVALID = 'invalid';

    public const COMPATIBILITY_AMBIGUOUS = 'ambiguous';

    public const COMPATIBILITY_DRIFTED = 'drifted';

    protected $fillable = [
        'name',
        'description',
        'trigger',
        'weight',
        'is_active',
        'stop_processing',
        'conditions_json',
        'actions_json',
        'created_by',
        'updated_by',
        'last_hit_at',
        'hit_count',
        'lifecycle_status',
        'published_version_id',
        'published_by',
        'published_at',
        'definition_schema_version',
        'definition_checksum',
        'compatibility_status',
        'compatibility_reason_code',
        'compatibility_checked_at',
        'draft_payload_json',
        'draft_checksum',
        'draft_updated_by',
        'draft_updated_at',
        'draft_creation_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'stop_processing' => 'boolean',
        'conditions_json' => 'array',
        'actions_json' => 'array',
        'last_hit_at' => 'datetime',
        'hit_count' => 'integer',
        'published_version_id' => 'integer',
        'published_by' => 'integer',
        'published_at' => 'datetime',
        'definition_schema_version' => 'integer',
        'compatibility_checked_at' => 'datetime',
        'draft_payload_json' => 'array',
        'draft_updated_by' => 'integer',
        'draft_updated_at' => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(TicketRuleLog::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TicketRuleVersion::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(TicketRuleExecution::class);
    }

    public function latestExecution(): HasOne
    {
        return $this->hasOne(TicketRuleExecution::class)->latestOfMany();
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(TicketRuleVersion::class, 'published_version_id');
    }

    /**
     * The raw publisher ID remains durable if the User is later deleted.
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
