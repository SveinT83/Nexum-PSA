<?php

namespace App\Modules\Signal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalWebhookDelivery extends Model
{
    protected $fillable = [
        'signal_id',
        'signal_rule_id',
        'url',
        'status',
        'attempts',
        'response_status',
        'response_body',
        'last_error',
        'last_attempted_at',
        'delivered_at',
        'payload',
    ];

    protected $casts = [
        'last_attempted_at' => 'datetime',
        'delivered_at' => 'datetime',
        'payload' => 'array',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SignalRule::class, 'signal_rule_id');
    }
}
