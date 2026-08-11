<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailRule extends Model
{
    use SoftDeletes;

    public const TRIGGER_INBOUND = 'on_inbound';

    public const ROUTING_PHASE_NORMAL = 'normal';

    public const ROUTING_PHASE_PRECLASSIFICATION = 'preclassification';

    protected $fillable = [
        'name',
        'description',
        'trigger',
        'routing_phase',
        'weight',
        'is_active',
        'stop_processing',
        'conditions_json',
        'actions_json',
        'created_by',
        'updated_by',
        'last_hit_at',
        'hit_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'stop_processing' => 'boolean',
        'conditions_json' => 'array',
        'actions_json' => 'array',
        'routing_phase' => 'string',
        'last_hit_at' => 'datetime',
        'hit_count' => 'integer',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(EmailRuleLog::class);
    }
}
