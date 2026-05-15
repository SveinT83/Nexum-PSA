<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketWorkflow extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function states(): HasMany
    {
        return $this->hasMany(TicketWorkflowState::class)->orderBy('sort_order')->orderBy('id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(TicketWorkflowTransition::class)->orderBy('sort_order')->orderBy('id');
    }
}
