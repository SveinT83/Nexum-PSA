<?php

namespace App\Modules\Signal\Models;

use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Signal extends Model
{
    protected $fillable = [
        'source_domain',
        'source_type',
        'source_id',
        'subject_type',
        'subject_id',
        'contact_id',
        'client_id',
        'signal_type',
        'severity',
        'confidence',
        'status',
        'summary',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(SignalRuleExecution::class);
    }
}
