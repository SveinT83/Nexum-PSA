<?php

namespace App\Modules\LeadIntelligence\Models;

use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSuppressionEntry extends Model
{
    protected $fillable = [
        'email',
        'domain',
        'client_id',
        'contact_id',
        'reason',
        'source',
        'suppressed_at',
    ];

    protected $casts = [
        'suppressed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}

