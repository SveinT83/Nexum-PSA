<?php

namespace App\Modules\Task\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\WorkContext\Models\WorkContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaskTemplateRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'template_group_id', 'actor_id', 'trigger_type', 'source_type', 'source_id',
        'owner_type', 'owner_id', 'client_id', 'site_id', 'work_context_id',
        'template_name', 'template_updated_at', 'idempotency_key', 'status',
        'task_count', 'scheduled_for', 'started_at', 'completed_at',
        'failure_reason', 'metadata',
    ];

    protected $casts = [
        'template_updated_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'task_count' => 'integer',
        'metadata' => 'array',
    ];

    public function templateGroup(): BelongsTo
    {
        return $this->belongsTo(TaskTemplateGroup::class, 'template_group_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function workContext(): BelongsTo
    {
        return $this->belongsTo(WorkContext::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'task_template_run_id');
    }
}
