<?php

namespace App\Modules\Task\Models;

use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplateItem extends Model
{
    protected $fillable = [
        'template_group_id',
        'parent_id',
        'title',
        'description',
        'status_id',
        'queue_id',
        'priority_id',
        'category_id',
        'assigned_to',
        'estimated_minutes',
        'blocks_owner_completion',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'estimated_minutes' => 'integer',
        'blocks_owner_completion' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(TaskTemplateGroup::class, 'template_group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TaskTemplateItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(TaskTemplateItem::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskTemplateChecklistItem::class, 'template_item_id')->orderBy('sort_order')->orderBy('id');
    }
}
