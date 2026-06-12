<?php

namespace App\Modules\Marketing\Models;

use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingInterestAssignment extends Model
{
    protected $fillable = [
        'marketing_interest_tag_id',
        'contact_id',
        'client_id',
        'first_event_id',
        'last_event_id',
        'event_count',
        'engagement_score',
        'first_seen_at',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function interestTag(): BelongsTo
    {
        return $this->belongsTo(MarketingInterestTag::class, 'marketing_interest_tag_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function firstEvent(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignEvent::class, 'first_event_id');
    }

    public function lastEvent(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignEvent::class, 'last_event_id');
    }
}
