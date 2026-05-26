<?php

namespace App\Modules\Task\Models;

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
        'interval',
        'interval_config',
        'next_run_at',
        'last_run_at',
        'is_active',
    ];

    protected $casts = [
        'interval_config' => 'array',
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
}
