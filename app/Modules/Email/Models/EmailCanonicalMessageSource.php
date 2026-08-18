<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCanonicalMessageSource extends Model
{
    public const KIND_SELF = 'self';

    public const KIND_CONFIRMED_COMPONENT = 'confirmed_component';

    public const KIND_DRIFT_DISSOLUTION = 'drift_dissolution';

    protected $table = 'email_canonical_message_sources';

    protected $guarded = [];

    protected $casts = [
        'evidence_complete' => 'boolean',
        'mapped_at' => 'datetime',
    ];

    public function canonicalMessage(): BelongsTo
    {
        return $this->belongsTo(EmailCanonicalMessage::class, 'canonical_email_message_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'source_email_message_id');
    }

    public function mapper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_by');
    }
}
