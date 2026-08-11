<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class AiRetainedPayload extends Model
{
    protected $fillable = ['ai_access_event_id', 'encrypted_request', 'encrypted_response', 'expires_at'];

    protected $casts = [
        'encrypted_request' => 'encrypted:array',
        'encrypted_response' => 'encrypted:array',
        'expires_at' => 'datetime',
    ];
}
