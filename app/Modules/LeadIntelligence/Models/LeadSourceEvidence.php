<?php

namespace App\Modules\LeadIntelligence\Models;

use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadSourceEvidence extends Model
{
    protected $table = 'lead_source_evidence';

    protected $fillable = [
        'lead_research_run_id',
        'client_id',
        'contact_id',
        'source_type',
        'source_url',
        'source_title',
        'excerpt',
        'confidence',
        'metadata',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'metadata' => 'array',
    ];

    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(LeadResearchRun::class, 'lead_research_run_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}

