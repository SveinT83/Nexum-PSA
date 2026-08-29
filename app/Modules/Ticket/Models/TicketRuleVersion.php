<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TicketRuleVersion extends Model
{
    public const STATUS_COMPATIBILITY = 'compatibility';

    public const STATUS_PUBLISHED = 'published';

    public const PROVENANCE_LEGACY_BACKFILL = 'legacy_backfill';

    public const PROVENANCE_ADMIN_PUBLISH = 'admin_publish';

    protected $fillable = [
        'ticket_rule_id',
        'version_number',
        'status',
        'definition_schema_version',
        'trigger_key',
        'weight',
        'stop_processing',
        'name',
        'description',
        'definition_json',
        'definition_checksum',
        'source_is_active',
        'source_trigger',
        'source_hit_count',
        'source_last_hit_at',
        'source_created_by',
        'source_updated_by',
        'source_created_at',
        'source_updated_at',
        'source_deleted_at',
        'published_by',
        'published_at',
        'provenance',
        'provenance_batch_uuid',
        'provenance_key',
        'provenance_recorded_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'definition_schema_version' => 'integer',
        'weight' => 'integer',
        'stop_processing' => 'boolean',
        'definition_json' => 'array',
        'source_is_active' => 'boolean',
        'source_hit_count' => 'integer',
        'source_last_hit_at' => 'datetime',
        'source_created_by' => 'integer',
        'source_updated_by' => 'integer',
        'source_created_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'source_deleted_at' => 'datetime',
        'published_by' => 'integer',
        'published_at' => 'datetime',
        'provenance_recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Ticket Rule versions are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Ticket Rule versions are immutable.');
        });
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TicketRule::class, 'ticket_rule_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(TicketRuleExecution::class, 'rule_version_id');
    }

    public function actionResults(): HasMany
    {
        return $this->hasMany(TicketRuleActionResult::class, 'rule_version_id');
    }

    /**
     * The raw publisher ID remains durable if the User is later deleted.
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
