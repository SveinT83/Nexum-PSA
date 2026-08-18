<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCanonicalCutoverItem extends Model
{
    public const KIND_SELF_MAP = 'self_map';

    public const KIND_COMPONENT_MEMBER = 'component_member';

    public const KIND_DISSOLVE_MEMBER = 'dissolve_member';

    public const KIND_POINTER_REPAIR = 'pointer_repair';

    public const KIND_MODE_CHANGE = 'mode_change';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_FAILED = 'failed';

    protected $table = 'email_canonical_cutover_items';

    protected $guarded = [];

    protected $casts = [
        'correlation_candidate_ids_json' => 'array',
        'previous_placement_pointers_json' => 'array',
        'evidence_complete' => 'boolean',
        'previous_evidence_complete' => 'boolean',
        'previous_mapped_at' => 'datetime',
        'previous_read_mode_row_exists' => 'boolean',
        'previous_read_mode_lock_version' => 'integer',
        'parity_attestation_id' => 'integer',
        'applied_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailCanonicalCutoverRun::class, 'email_canonical_cutover_run_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'source_email_message_id');
    }

    public function previousCanonicalMessage(): BelongsTo
    {
        return $this->belongsTo(
            EmailCanonicalMessage::class,
            'previous_canonical_email_message_id',
        );
    }

    public function appliedCanonicalMessage(): BelongsTo
    {
        return $this->belongsTo(
            EmailCanonicalMessage::class,
            'applied_canonical_email_message_id',
        );
    }
}
