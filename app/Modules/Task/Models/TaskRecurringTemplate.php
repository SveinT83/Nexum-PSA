<?php

namespace App\Modules\Task\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaskRecurringTemplate extends Model
{
    protected $fillable = [
        'template_group_id',
        'name',
        'owner_type',
        'owner_id',
        'created_by',
        'interval',
        'interval_config',
        'timezone',
        'due_offset_minutes',
        'assigned_to',
        'next_run_at',
        'last_run_at',
        'last_result',
        'last_failure_reason',
        'is_active',
    ];

    protected $casts = [
        'interval_config' => 'array',
        'due_offset_minutes' => 'integer',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function templateGroup(): BelongsTo
    {
        return $this->belongsTo(TaskTemplateGroup::class, 'template_group_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
