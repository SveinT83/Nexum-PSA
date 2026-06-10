<?php

namespace App\Modules\Marketing\Models;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    protected $fillable = [
        'marketing_list_id',
        'email_account_id',
        'name',
        'description',
        'status',
        'starts_at',
        'batch_size',
        'send_interval_minutes',
        'track_opens',
        'track_clicks',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'approved_at' => 'datetime',
        'track_opens' => 'boolean',
        'track_clicks' => 'boolean',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(MarketingList::class, 'marketing_list_id');
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(MarketingCampaignEmail::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketingCampaignEvent::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
