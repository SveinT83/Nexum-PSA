<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingContentSource extends Model
{
    protected $fillable = [
        'marketing_campaign_id',
        'source_type',
        'source_url',
        'external_id',
        'title',
        'excerpt',
        'content_html',
        'published_at',
        'fetched_at',
        'status',
        'metadata',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'fetched_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }
}
