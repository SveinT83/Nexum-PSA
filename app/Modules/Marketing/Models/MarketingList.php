<?php

namespace App\Modules\Marketing\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingList extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status',
        'audience_type',
        'consent_category_id',
        'segment_criteria',
        'created_by',
        'updated_by',
        'last_resolved_at',
    ];

    protected $casts = [
        'segment_criteria' => 'array',
        'last_resolved_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(MarketingListMember::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class);
    }

    public function marketingCampaigns(): BelongsToMany
    {
        return $this->belongsToMany(
            MarketingCampaign::class,
            'marketing_campaign_marketing_list',
            'marketing_list_id',
            'marketing_campaign_id'
        )->withTimestamps();
    }

    public function isUsedByCampaigns(): bool
    {
        return $this->campaigns()->exists() || $this->marketingCampaigns()->exists();
    }

    public function campaignUsageCount(): int
    {
        $legacyCampaignIds = $this->campaigns()->pluck('id');
        $pivotCampaignIds = $this->marketingCampaigns()->pluck('marketing_campaigns.id');

        return $legacyCampaignIds
            ->merge($pivotCampaignIds)
            ->unique()
            ->count();
    }

    public function consentCategory(): BelongsTo
    {
        return $this->belongsTo(MarketingConsentCategory::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
