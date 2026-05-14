<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketRule extends Model
{
    use SoftDeletes;

    public const TRIGGER_CREATE = 'on_create';

    protected $fillable = [
        'name',
        'description',
        'trigger',
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
        'last_hit_at' => 'datetime',
        'hit_count' => 'integer',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(TicketRuleLog::class);
    }
}
