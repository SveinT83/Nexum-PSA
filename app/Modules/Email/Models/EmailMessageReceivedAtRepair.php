<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessageReceivedAtRepair extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REPAIRED = 'repaired';

    public const STATUS_UNRESOLVED = 'unresolved';

    public const SOURCE_HEADER_DATE = 'header_date';

    public const SOURCE_CONVERSATION_BOUNDARY = 'conversation_boundary';

    public const CANDIDATE_LOCAL_INGEST_CREATED_AT = 'local_ingest_created_at_candidate';

    protected $table = 'email_message_received_at_repairs';

    protected $fillable = [
        'email_message_id',
        'observed_received_at',
        'repaired_received_at',
        'evidence_source',
        'evidence_fingerprint',
        'candidate_received_at',
        'candidate_source',
        'status',
        'reason_code',
        'smart_suggestions_recovered',
        'last_checked_at',
        'repaired_at',
    ];

    protected $casts = [
        'email_message_id' => 'integer',
        'observed_received_at' => 'datetime',
        'repaired_received_at' => 'datetime',
        'candidate_received_at' => 'datetime',
        'smart_suggestions_recovered' => 'integer',
        'last_checked_at' => 'datetime',
        'repaired_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id')->withTrashed();
    }
}
