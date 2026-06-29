<?php

namespace App\Modules\Telephony\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelephonyCall extends Model
{
    protected $fillable = [
        'provider_profile',
        'provider_call_id',
        'provider_call_key',
        'fallback_fingerprint',
        'direction',
        'caller_number_raw',
        'caller_number_normalized',
        'called_number',
        'answered_by_user_id',
        'contact_id',
        'client_user_id',
        'client_id',
        'site_id',
        'linked_ticket_id',
        'started_at',
        'answered_at',
        'ended_at',
        'duration_minutes',
        'status',
        'notes',
        'is_test',
        'raw_payload',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_test' => 'boolean',
        'raw_payload' => 'array',
        'metadata' => 'array',
    ];

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_user_id');
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }

    public function linkedTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'linked_ticket_id');
    }
}
