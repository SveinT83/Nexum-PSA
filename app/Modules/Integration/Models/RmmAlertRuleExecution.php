<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RmmAlertRuleExecution extends Model
{
    protected $fillable = [
        'rmm_alert_occurrence_id',
        'rmm_alert_rule_id',
        'rule_key',
        'rule_revision',
        'rule_name',
        'matched',
        'status',
        'rule_snapshot',
        'condition_results',
        'action_results',
        'error',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'rule_revision' => 'integer',
        'matched' => 'boolean',
        'rule_snapshot' => 'array',
        'condition_results' => 'array',
        'action_results' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(RmmAlertOccurrence::class, 'rmm_alert_occurrence_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RmmAlertRule::class, 'rmm_alert_rule_id')->withTrashed();
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(RmmAlertWorkItem::class);
    }
}
