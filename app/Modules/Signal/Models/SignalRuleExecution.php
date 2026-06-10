<?php

namespace App\Modules\Signal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalRuleExecution extends Model
{
    protected $fillable = [
        'signal_id',
        'signal_rule_id',
        'status',
        'actions',
        'results',
        'error',
        'executed_at',
    ];

    protected $casts = [
        'actions' => 'array',
        'results' => 'array',
        'executed_at' => 'datetime',
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
