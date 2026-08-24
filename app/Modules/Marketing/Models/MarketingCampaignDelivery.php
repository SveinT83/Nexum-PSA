<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaignDelivery extends Model
{
    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_PROVIDER_WRITE_STARTED = 'provider_write_started';

    public const STATUS_SENT = 'sent';

    public const STATUS_OUTCOME_UNKNOWN = 'outcome_unknown';

    protected $fillable = [
        'marketing_campaign_id',
        'marketing_campaign_email_id',
        'marketing_campaign_recipient_id',
        'status',
        'source',
        'claim_token',
        'rfc_message_id',
        'claimed_at',
        'provider_write_started_at',
        'sent_at',
        'outcome_unknown_at',
        'last_error_code',
        'metadata',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'provider_write_started_at' => 'datetime',
        'sent_at' => 'datetime',
        'outcome_unknown_at' => 'datetime',
        'metadata' => 'array',
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

    public function recipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class, 'marketing_campaign_delivery_id');
    }

    public function identityKeys(): HasMany
    {
        return $this->hasMany(MarketingCampaignDeliveryIdentityKey::class, 'marketing_campaign_delivery_id');
    }
}
