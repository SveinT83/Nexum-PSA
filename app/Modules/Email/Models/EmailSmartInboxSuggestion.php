<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Email\Services\EmailConversationFingerprint;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class EmailSmartInboxSuggestion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_STALE = 'stale';

    public const STATUS_REVOKED = 'revoked';

    public const EFFECT_REVIEW_SUMMARY = 'review_summary';

    public const EFFECT_APPLY_CATEGORY = 'apply_category';

    public const EFFECT_APPLY_TAG = 'apply_tag';

    public const EFFECT_CREATE_TASK = 'create_task';

    public const EFFECT_ARCHIVE_MAIL = 'archive_mail';

    public const EFFECT_MOVE_TO_FOLDER = 'move_to_folder';

    public const SCHEMA_VERSION = 'email.smart_inbox.suggestion.v1';

    protected $table = 'email_smart_inbox_suggestions';

    protected $fillable = [
        'user_id',
        'account_id',
        'email_conversation_id',
        'selected_email_mailbox_placement_id',
        'effect_type',
        'proposal_json',
        'proposal_fingerprint',
        'explanation',
        'confidence',
        'source_fingerprint',
        'source_fingerprint_schema',
        'source_message_ids_json',
        'schema_version',
        'status',
        'idempotency_key',
        'ai_execution_id',
        'ai_agent_id',
        'ai_provider_id',
        'ai_model',
        'ai_policy_revision',
        'ai_trace_json',
        'corrected_by',
        'corrected_at',
        'dismissed_by',
        'dismissed_at',
        'stale_at',
        'revoked_at',
        'applied_by',
        'applied_at',
        'applied_reference_type',
        'applied_reference_id',
        'generated_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'account_id' => 'integer',
        'email_conversation_id' => 'integer',
        'selected_email_mailbox_placement_id' => 'integer',
        'proposal_json' => 'array',
        'confidence' => 'float',
        'source_message_ids_json' => 'array',
        'ai_agent_id' => 'integer',
        'ai_policy_revision' => 'integer',
        'ai_trace_json' => 'array',
        'corrected_by' => 'integer',
        'corrected_at' => 'datetime',
        'dismissed_by' => 'integer',
        'dismissed_at' => 'datetime',
        'stale_at' => 'datetime',
        'revoked_at' => 'datetime',
        'applied_by' => 'integer',
        'applied_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $suggestion): void {
            // Existing NULL rows are intentionally interpreted as legacy v1.
            // New rows name v2, while old workers remain deployable before
            // migration 121200 adds the column.
            if (self::supportsSourceFingerprintSchema()
                && blank($suggestion->source_fingerprint_schema)) {
                $suggestion->source_fingerprint_schema = EmailConversationFingerprint::SCHEMA_VERSION;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EmailConversation::class, 'email_conversation_id');
    }

    public function selectedPlacement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'selected_email_mailbox_placement_id');
    }

    public function aiAgent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailSmartInboxSuggestionEvent::class, 'email_smart_inbox_suggestion_id')
            ->orderBy('id');
    }

    public static function supportsSourceFingerprintSchema(): bool
    {
        return Schema::hasColumn(
            (new self)->getTable(),
            'source_fingerprint_schema',
        );
    }

    /**
     * A request that starts before migration 121200 must hash and store v1.
     * Otherwise it could persist a v2 digest into an old NULL=v1 row.
     */
    public static function fingerprintSchemaForNewRows(): string
    {
        return self::supportsSourceFingerprintSchema()
            ? EmailConversationFingerprint::SCHEMA_VERSION
            : EmailConversationFingerprint::LEGACY_SCHEMA_VERSION;
    }
}
