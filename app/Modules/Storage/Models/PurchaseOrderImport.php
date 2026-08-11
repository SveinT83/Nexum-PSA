<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderImport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STAGE_DETECT = 'detect';

    public const STAGE_DETERMINISTIC_EXTRACT = 'deterministic_extract';

    public const STAGE_AI_EXTRACT = 'ai_extract';

    public const STAGE_ITEM_RESOLUTION = 'item_resolution';

    public const STAGE_VALIDATE = 'validate';

    public const STAGE_POLICY = 'policy';

    public const STAGE_FINALIZE = 'finalize';

    protected $table = 'storage_purchase_order_imports';

    protected $fillable = [
        'source_domain',
        'source_type',
        'source_id',
        'email_message_id',
        'signal_id',
        'signal_rule_id',
        'signal_action_key',
        'source_action_hash',
        'source_fingerprint',
        'safe_source_snapshot',
        'trusted_auth_snapshot',
        'vendor_id',
        'profile_id',
        'profile_version_id',
        'ai_profile_candidate_version_id',
        'external_order_number',
        'domain_identity_hash',
        'revision_of_import_id',
        'policy_revision_id',
        'effective_policy_snapshot',
        'effective_policy_checksum',
        'purchase_order_id',
        'status',
        'stage',
        'reason_code',
        'reason_context',
        'extraction_method',
        'canonical_schema_version',
        'parser_version',
        'normalized_document',
        'validation_results',
        'confidence_dimensions',
        'commercial_snapshot',
        'delivery_snapshot',
        'decision',
        'ai_execution_uuid',
        'attempt_count',
        'next_retry_at',
        'locked_at',
        'processed_at',
        'finalized_at',
        'requested_by',
        'last_actor_id',
    ];

    protected $casts = [
        'safe_source_snapshot' => 'array',
        'trusted_auth_snapshot' => 'array',
        'reason_context' => 'array',
        'effective_policy_snapshot' => 'array',
        'normalized_document' => 'array',
        'validation_results' => 'array',
        'confidence_dimensions' => 'array',
        'commercial_snapshot' => 'array',
        'delivery_snapshot' => 'array',
        'attempt_count' => 'integer',
        'next_retry_at' => 'datetime',
        'locked_at' => 'datetime',
        'processed_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $import): void {
            foreach ([
                'source_fingerprint',
                'safe_source_snapshot',
                'trusted_auth_snapshot',
            ] as $attribute) {
                if ($import->isDirty($attribute)) {
                    throw new \DomainException('Supplier-order source evidence is immutable after creation.');
                }
            }
        });
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_RETRY_SCHEDULED,
            self::STATUS_NEEDS_ATTENTION,
            self::STATUS_IMPORTED,
            self::STATUS_DUPLICATE,
            self::STATUS_REJECTED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];
    }

    /** @return list<string> */
    public static function stages(): array
    {
        return [
            self::STAGE_DETECT,
            self::STAGE_DETERMINISTIC_EXTRACT,
            self::STAGE_AI_EXTRACT,
            self::STAGE_ITEM_RESOLUTION,
            self::STAGE_VALIDATE,
            self::STAGE_POLICY,
            self::STAGE_FINALIZE,
        ];
    }

    public function scopeActionable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_RETRY_SCHEDULED,
            self::STATUS_NEEDS_ATTENTION,
            self::STATUS_FAILED,
        ]);
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function signalRule(): BelongsTo
    {
        return $this->belongsTo(SignalRule::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportProfile::class, 'profile_id');
    }

    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportProfileVersion::class, 'profile_version_id');
    }

    public function aiProfileCandidateVersion(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportProfileVersion::class, 'ai_profile_candidate_version_id');
    }

    public function policyRevision(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderAutomationPolicyRevision::class, 'policy_revision_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_import_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderImportLine::class, 'import_id')->orderBy('position');
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(PurchaseOrderImportRepair::class, 'import_id')->orderBy('sequence');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PurchaseOrderImportAttempt::class, 'import_id')
            ->orderBy('attempt_number')
            ->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function lastActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_actor_id');
    }
}
