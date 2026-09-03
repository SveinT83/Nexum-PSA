<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailRuleReprocessRun extends Model
{
    public const STATUS_PREVIEW = 'preview';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'public_id', 'email_rule_id', 'email_rule_version_id', 'parent_run_id', 'actor_id',
        'operation', 'status', 'selection_json', 'selection_hash', 'requested_count',
        'matched_count', 'succeeded_count', 'failed_count', 'overflow', 'expires_at',
        'started_at', 'finished_at', 'cancelled_at',
    ];

    protected $casts = [
        'selection_json' => 'array',
        'overflow' => 'boolean',
        'expires_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(EmailRule::class, 'email_rule_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(EmailRuleVersion::class, 'email_rule_version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmailRuleReprocessItem::class, 'email_rule_reprocess_run_id');
    }
}
