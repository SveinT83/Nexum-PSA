<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignDeliveryIdentityKey extends Model
{
    protected $fillable = [
        'marketing_campaign_delivery_id',
        'marketing_campaign_email_id',
        'identity_type',
        'identity_hash',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignDelivery::class, 'marketing_campaign_delivery_id');
    }
}
