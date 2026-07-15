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
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const TARGET_REVIEW_ONLY = 'review_only';
    public const TARGET_SALES_LEAD = 'sales_lead';

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
        return $this->status === self::STATUS_ACTIVE;
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
}
