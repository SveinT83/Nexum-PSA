<?php

namespace App\Modules\Email\Models;

use App\Modules\Email\Services\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCanonicalMessage extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_DRIFTED = 'drifted';

    protected $table = 'email_canonical_messages';

    protected $guarded = [];

    protected $casts = [
        'to_json' => 'array',
        'cc_json' => 'array',
        'headers_json' => 'array',
        'received_at' => 'datetime',
        'is_oversize' => 'boolean',
        'size_bytes' => 'integer',
        'attachments_count' => 'integer',
        'evidence_complete' => 'boolean',
        'source_count' => 'integer',
        'last_verified_at' => 'datetime',
        'drifted_at' => 'datetime',
    ];

    public function getBodyHtmlSanitizedAttribute(?string $value): ?string
    {
        return HtmlSanitizer::sanitize($value);
    }

    public function rootSource(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'root_source_email_message_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(EmailCanonicalMessageSource::class, 'canonical_email_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailCanonicalMessageAttachment::class, 'canonical_email_message_id')
            ->orderBy('position');
    }
}
