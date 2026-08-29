<?php

namespace App\Modules\Integration\Models;

use App\Models\Tech\Work\Assets\AssetAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RmmAlertWorkItem extends Model
{
    protected $fillable = [
        'rmm_alert_occurrence_id',
        'asset_alert_id',
        'rmm_alert_rule_execution_id',
        'rule_key',
        'action_index',
        'action_type',
        'fingerprint',
        'target_type',
        'target_id',
        'metadata',
    ];

    protected $casts = [
        'action_index' => 'integer',
        'metadata' => 'array',
    ];

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(RmmAlertOccurrence::class, 'rmm_alert_occurrence_id');
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(AssetAlert::class, 'asset_alert_id');
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(RmmAlertRuleExecution::class, 'rmm_alert_rule_execution_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
