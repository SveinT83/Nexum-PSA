<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Email\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaignEmail extends Model
{
    protected $fillable = [
        'marketing_campaign_id',
        'email_template_id',
        'sequence_order',
        'status',
        'scheduled_at',
        'delay_minutes',
        'subject_override',
        'metadata',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class);
    }
}
