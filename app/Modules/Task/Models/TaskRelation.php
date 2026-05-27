<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaskRelation extends Model
{
    protected $fillable = [
        'task_id',
        'related_type',
        'related_id',
        'relation_type',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
