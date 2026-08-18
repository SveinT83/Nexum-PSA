<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCanonicalParityAttestationItem extends Model
{
    public $timestamps = false;

    protected $table = 'email_canonical_parity_attestation_items';

    protected $guarded = [];

    protected $casts = [
        'email_mailbox_placement_id' => 'integer',
        'source_email_message_id' => 'integer',
        'canonical_email_message_id' => 'integer',
        'evidence_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function attestation(): BelongsTo
    {
        return $this->belongsTo(
            EmailCanonicalParityAttestation::class,
            'email_canonical_parity_attestation_id',
        );
    }
}
