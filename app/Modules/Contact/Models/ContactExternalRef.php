<?php

namespace App\Modules\Contact\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactExternalRef extends Model
{
    protected $fillable = [
        'contact_id',
        'source',
        'external_id',
        'external_key',
        'last_synced_at',
        'sync_hash',
        'payload',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'payload' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
