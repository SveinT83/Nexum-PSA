<?php

namespace App\Modules\Marketing\Models;

use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignEvent extends Model
{
    protected $fillable = [
        'marketing_campaign_id',
        'marketing_campaign_email_id',
        'marketing_campaign_recipient_id',
        'contact_id',
        'client_id',
        'type',
        'url',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function campaignEmail(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignEmail::class, 'marketing_campaign_email_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignRecipient::class, 'marketing_campaign_recipient_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
