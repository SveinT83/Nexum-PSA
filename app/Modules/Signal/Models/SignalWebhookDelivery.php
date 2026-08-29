<?php

namespace App\Modules\Signal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalWebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNRESOLVED = 'unresolved';

    protected $fillable = [
        'signal_id',
        'signal_rule_id',
        'action_key',
        'url',
        'status',
        'attempts',
        'claim_token',
        'response_status',
        'response_body',
        'last_error',
        'last_attempted_at',
        'completed_at',
        'delivered_at',
        'payload',
    ];

    protected $casts = [
        'last_attempted_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_FAILED,
            self::STATUS_UNRESOLVED,
        ], true);
    }
}
