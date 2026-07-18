<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketWorkflow extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'definition_status',
        'escalation_paths',
        'published_version_id',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'escalation_paths' => 'array',
    ];

    public function states(): HasMany
    {
        return $this->hasMany(TicketWorkflowState::class)->orderBy('sort_order')->orderBy('id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(TicketWorkflowTransition::class)->orderBy('sort_order')->orderBy('id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TicketWorkflowVersion::class)->orderByDesc('version');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(TicketWorkflowVersion::class, 'published_version_id');
    }
}
