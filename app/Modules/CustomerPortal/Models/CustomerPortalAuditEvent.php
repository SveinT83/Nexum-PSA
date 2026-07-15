<?php

namespace App\Modules\CustomerPortal\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPortalAuditEvent extends Model
{
    protected $fillable = [
        'customer_portal_account_id',
        'user_id',
        'contact_id',
        'client_id',
        'site_id',
        'event',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalAccount::class, 'customer_portal_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }
}
