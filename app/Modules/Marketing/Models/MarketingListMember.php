<?php

namespace App\Modules\Marketing\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingListMember extends Model
{
    protected $fillable = [
        'marketing_list_id',
        'source_type',
        'source_id',
        'contact_id',
        'client_user_id',
        'client_id',
        'email',
        'name',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(MarketingList::class, 'marketing_list_id');
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
