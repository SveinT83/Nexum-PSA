<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketAssignmentRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'weight',
        'is_active',
        'stop_processing',
        'conditions_json',
        'action_type',
        'action_value',
        'created_by',
        'updated_by',
        'last_hit_at',
        'hit_count',
    ];

    protected $casts = [
        'weight' => 'integer',
        'is_active' => 'boolean',
        'stop_processing' => 'boolean',
        'conditions_json' => 'array',
        'last_hit_at' => 'datetime',
        'hit_count' => 'integer',
    ];
}
