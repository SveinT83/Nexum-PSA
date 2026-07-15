<?php

namespace App\Modules\Intake\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IntakeSubmission extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_SPAM = 'spam';
    public const STATUS_ROUTED = 'routed';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_ROUTING_SKIPPED = 'routing_skipped';

    protected $fillable = [
        'intake_form_id',
        'status',
        'source_url',
        'referrer',
        'ip_address',
        'user_agent',
        'honeypot_value',
        'raw_payload',
        'normalized_payload',
        'matched_client_id',
        'matched_site_id',
        'matched_contact_id',
        'matched_client_user_id',
        'target_type',
        'target_id',
        'routing_result',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
        'routing_result' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(IntakeForm::class, 'intake_form_id');
    }

    public function matchedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'matched_client_id');
    }

    public function matchedSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'matched_site_id');
    }

    public function matchedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'matched_contact_id');
    }

    public function matchedClientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'matched_client_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'target_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IntakeSubmissionAttachment::class, 'intake_submission_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IntakeSubmissionEvent::class, 'intake_submission_id')->latest();
    }
}
