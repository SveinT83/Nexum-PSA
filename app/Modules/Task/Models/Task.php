<?php

namespace App\Modules\Task\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\WorkContext\Models\WorkContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    public const VISIBILITY_INTERNAL = 'internal';
    public const VISIBILITY_PRIVATE = 'private';

    protected $fillable = [
        'parent_id',
        'title',
        'description',
        'owner_type',
        'owner_id',
        'created_by',
        'assigned_to',
        'status_id',
        'queue_id',
        'priority_id',
        'category_id',
        'client_id',
        'work_context_id',
        'site_id',
        'visibility',
        'source_type',
        'source_id',
        'template_group_id',
        'template_item_id',
        'due_at',
        'scheduled_start_at',
        'scheduled_end_at',
        'estimated_minutes',
        'completed_at',
        'completed_by',
        'blocks_owner_completion',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_minutes' => 'integer',
        'blocks_owner_completion' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(TicketQueue::class, 'queue_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'priority_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function workContext(): BelongsTo
    {
        return $this->belongsTo(WorkContext::class, 'work_context_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'taggables')
            ->withPivot('module')
            ->withTimestamps();
    }

    public function relations(): HasMany
    {
        return $this->hasMany(TaskRelation::class);
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class);
    }

    public function blockedTasks(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'depends_on_task_id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TaskTimeEntry::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->latest();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at')
            ->whereHas('status', fn (Builder $status) => $status->where('is_done', false)->where('is_cancelled', false));
    }

    public function getActualMinutesAttribute(): int
    {
        return (int) $this->timeEntries()->sum('minutes');
    }

    public function getIsBlockedAttribute(): bool
    {
        return $this->dependencies()
            ->where('is_required', true)
            ->whereHas('dependsOnTask', fn (Builder $query) => $query->whereNull('completed_at'))
            ->exists();
    }
}
