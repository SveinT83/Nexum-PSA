<?php

namespace App\Modules\LeadIntelligence\Models;

use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMarketingEligibility extends Model
{
    protected $fillable = [
        'contact_id',
        'client_id',
        'email',
        'email_type',
        'role',
        'eligible',
        'reason',
        'source_evidence_id',
        'evaluated_at',
        'metadata',
    ];

    protected $casts = [
        'eligible' => 'boolean',
        'evaluated_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sourceEvidence(): BelongsTo
    {
        return $this->belongsTo(LeadSourceEvidence::class, 'source_evidence_id');
    }
}

