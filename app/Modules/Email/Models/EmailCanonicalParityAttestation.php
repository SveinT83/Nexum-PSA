<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCanonicalParityAttestation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'email_canonical_parity_attestations';

    protected $guarded = [];

    protected $casts = [
        'strict_evidence' => 'boolean',
        'frozen_max_placement_id' => 'integer',
        'frozen_active_placement_count' => 'integer',
        'next_placement_id' => 'integer',
        'verified_placement_count' => 'integer',
        'total_evidence_bytes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            EmailCanonicalParityAttestationItem::class,
            'email_canonical_parity_attestation_id',
        );
    }
}
