<?php

namespace App\Modules\Intake\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IntakeForm extends Model
{
    use SoftDeletes;

    public const DEFAULT_SUBMIT_BUTTON_LABEL = 'Send inquiry';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ACTIVE = self::STATUS_PUBLISHED;
    public const STATUS_LEGACY_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    public const TARGET_REVIEW_ONLY = 'review_only';
    public const TARGET_SALES_LEAD = 'sales_lead';
    public const TARGET_TICKET = 'ticket';
    public const TARGET_TASK = 'task';

    public const ROUTING_MODE_MANUAL_REVIEW = 'manual_review';
    public const ROUTING_MODE_KNOWN_CLIENT_ONLY = 'known_client_only';
    public const ROUTING_MODE_ANY_SUBMISSION = 'any_submission';

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_CLIENT = 'client';
    public const SCOPE_SERVICE = 'service';
    public const SCOPE_SALES = 'sales';
    public const SCOPE_TICKET = 'ticket';
    public const SCOPE_CAMPAIGN = 'campaign';

    public const DEFAULT_ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'success_message',
        'target_type',
        'auto_create_client',
        'auto_create_contact',
        'owner_id',
        'spam_honeypot_field',
        'max_files',
        'max_file_size_kb',
        'allowed_mime_types',
        'metadata',
    ];

    protected $casts = [
        'auto_create_client' => 'boolean',
        'auto_create_contact' => 'boolean',
        'max_files' => 'integer',
        'max_file_size_kb' => 'integer',
        'allowed_mime_types' => 'array',
        'metadata' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(IntakeFormField::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeFields(): HasMany
    {
        return $this->fields()->where('is_active', true);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(IntakeSubmission::class)->latest('submitted_at')->latest();
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_LEGACY_ACTIVE], true);
    }

    public function allowedMimeTypes(): array
    {
        return $this->allowed_mime_types ?: self::DEFAULT_ALLOWED_MIME_TYPES;
    }

    public function submitButtonLabel(): string
    {
        $label = trim((string) data_get($this->metadata, 'submit_button_label', ''));

        return $label !== '' ? $label : self::DEFAULT_SUBMIT_BUTTON_LABEL;
    }

    public function purpose(): ?string
    {
        $purpose = trim((string) data_get($this->metadata, 'purpose', ''));

        return $purpose !== '' ? $purpose : null;
    }

    public function language(): string
    {
        $language = trim((string) data_get($this->metadata, 'language', ''));

        return $language !== '' ? $language : 'en';
    }

    public function scopeType(): string
    {
        $scope = (string) data_get($this->metadata, 'scope.type', self::SCOPE_GLOBAL);

        return in_array($scope, array_keys(self::scopeLabels()), true) ? $scope : self::SCOPE_GLOBAL;
    }

    public function scopeClientId(): ?int
    {
        $clientId = data_get($this->metadata, 'scope.client_id');

        return is_numeric($clientId) ? (int) $clientId : null;
    }

    public function scopeServiceId(): ?int
    {
        $serviceId = data_get($this->metadata, 'scope.service_id');

        return is_numeric($serviceId) ? (int) $serviceId : null;
    }

    public function campaignKey(): ?string
    {
        $key = trim((string) data_get($this->metadata, 'scope.campaign_key', ''));

        return $key !== '' ? $key : null;
    }

    public function routingMode(): string
    {
        $mode = (string) data_get($this->metadata, 'routing.mode', self::ROUTING_MODE_MANUAL_REVIEW);

        return in_array($mode, array_keys(self::routingModeLabels()), true)
            ? $mode
            : self::ROUTING_MODE_MANUAL_REVIEW;
    }

    public function shouldAutoRoute(IntakeSubmission $submission): bool
    {
        if ($this->target_type === self::TARGET_REVIEW_ONLY) {
            return false;
        }

        return match ($this->routingMode()) {
            self::ROUTING_MODE_KNOWN_CLIENT_ONLY => (bool) $submission->matched_client_id,
            self::ROUTING_MODE_ANY_SUBMISSION => true,
            default => false,
        };
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PUBLISHED => 'Published',
            self::STATUS_PAUSED => 'Paused',
            self::STATUS_ARCHIVED => 'Archived',
        ];
    }

    public static function targetLabels(): array
    {
        return [
            self::TARGET_REVIEW_ONLY => 'Review only',
            self::TARGET_SALES_LEAD => 'Sales lead',
            self::TARGET_TICKET => 'Ticket',
            self::TARGET_TASK => 'Task',
        ];
    }

    public static function routingModeLabels(): array
    {
        return [
            self::ROUTING_MODE_MANUAL_REVIEW => 'Manual review',
            self::ROUTING_MODE_KNOWN_CLIENT_ONLY => 'Auto-route known clients',
            self::ROUTING_MODE_ANY_SUBMISSION => 'Auto-route every valid submission',
        ];
    }

    public static function scopeLabels(): array
    {
        return [
            self::SCOPE_GLOBAL => 'Global',
            self::SCOPE_CLIENT => 'Client',
            self::SCOPE_SERVICE => 'Service',
            self::SCOPE_SALES => 'Sales',
            self::SCOPE_TICKET => 'Ticket',
            self::SCOPE_CAMPAIGN => 'Campaign',
        ];
    }
}
