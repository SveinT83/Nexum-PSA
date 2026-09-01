<?php

namespace App\Modules\Integration\Models;

use App\Models\Tech\Work\Assets\AssetAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RmmAlertOccurrence extends Model
{
    protected $fillable = [
        'asset_alert_id',
        'sequence',
        'event_type',
        'integration_type',
        'fingerprint',
        'severity',
        'title',
        'context',
        'occurred_at',
        'resolved_at',
        'processing_status',
        'processing_started_at',
        'processing_token',
        'processed_at',
        'processing_error',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'context' => 'array',
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(AssetAlert::class, 'asset_alert_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(RmmAlertRuleExecution::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(RmmAlertWorkItem::class);
    }
}
