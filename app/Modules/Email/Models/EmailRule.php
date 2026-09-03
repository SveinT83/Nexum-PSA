<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class EmailRule extends Model
{
    use SoftDeletes;

    public const TRIGGER_INBOUND = 'on_inbound';

    public const ROUTING_PHASE_NORMAL = 'normal';

    public const ROUTING_PHASE_PRECLASSIFICATION = 'preclassification';

    public const ROUTING_PHASE_PERSONAL = 'personal';

    public const KIND_ADMIN = 'admin';

    public const KIND_PERSONAL_SIMPLE = 'personal_simple';

    public const LIFECYCLE_DRAFT = 'draft';

    public const LIFECYCLE_PUBLISHED = 'published';

    public const LIFECYCLE_DISABLED = 'disabled';

    protected $fillable = [
        'name',
        'description',
        'trigger',
        'routing_phase',
        'rule_kind',
        'owner_id',
        'weight',
        'is_active',
        'lifecycle_status',
        'published_version_id',
        'stop_processing',
        'conditions_json',
        'actions_json',
        'created_by',
        'updated_by',
        'published_by',
        'published_at',
        'last_hit_at',
        'hit_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'stop_processing' => 'boolean',
        'conditions_json' => 'array',
        'actions_json' => 'array',
        'routing_phase' => 'string',
        'rule_kind' => 'string',
        'owner_id' => 'integer',
        'lifecycle_status' => 'string',
        'published_at' => 'datetime',
        'last_hit_at' => 'datetime',
        'hit_count' => 'integer',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(EmailRuleLog::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EmailRuleVersion::class, 'email_rule_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(EmailRuleVersion::class, 'published_version_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function executionAttempts(): HasMany
    {
        return $this->hasMany(EmailRuleExecutionAttempt::class, 'email_rule_id');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(EmailRuleDraft::class, 'email_rule_id');
    }

    public function reprocessRuns(): HasMany
    {
        return $this->hasMany(EmailRuleReprocessRun::class, 'email_rule_id');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(EmailAccount::class, 'email_rule_accounts', 'email_rule_id', 'email_account_id')
            ->withTimestamps();
    }

    /**
     * @param  Builder<EmailRule>  $query
     * @return Builder<EmailRule>
     */
    public function scopeAdminManaged(Builder $query): Builder
    {
        if (! Schema::hasColumn('email_rules', 'rule_kind')) {
            return $query;
        }

        return $query->where('rule_kind', self::KIND_ADMIN);
    }

    /**
     * @param  Builder<EmailRule>  $query
     * @return Builder<EmailRule>
     */
    public function scopePersonalSimple(Builder $query): Builder
    {
        if (! Schema::hasColumn('email_rules', 'rule_kind')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('rule_kind', self::KIND_PERSONAL_SIMPLE);
    }

    public function isAdminManaged(): bool
    {
        return ! Schema::hasColumn('email_rules', 'rule_kind')
            || $this->rule_kind === self::KIND_ADMIN;
    }

    public function isPersonalSimple(): bool
    {
        return Schema::hasColumn('email_rules', 'rule_kind')
            && $this->rule_kind === self::KIND_PERSONAL_SIMPLE;
    }
}
