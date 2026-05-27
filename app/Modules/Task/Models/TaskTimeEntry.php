<?php

namespace App\Modules\Task\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTimeEntry extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'source_type',
        'work_date',
        'started_at',
        'ended_at',
        'minutes',
        'billable',
        'note',
    ];

    protected $casts = [
        'work_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'minutes' => 'integer',
        'billable' => 'boolean',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
