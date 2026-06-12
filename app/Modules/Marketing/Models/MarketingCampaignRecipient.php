<?php

namespace App\Modules\Marketing\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignRecipient extends Model
{
    protected $fillable = [
        'marketing_campaign_id',
        'marketing_campaign_email_id',
        'marketing_list_member_id',
        'contact_id',
        'client_user_id',
        'client_id',
        'email',
        'name',
        'status',
        'due_at',
        'sent_at',
        'attempts',
        'rfc_message_id',
        'last_error',
        'tracking_token',
        'metadata',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'sent_at' => 'datetime',
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

    public function listMember(): BelongsTo
    {
        return $this->belongsTo(MarketingListMember::class, 'marketing_list_member_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
