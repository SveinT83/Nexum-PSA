<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketWorkflowHistory extends Model
{
    protected $fillable = [
        'ticket_id',
        'actor_id',
        'workflow_version_id',
        'event_type',
        'from_state_key',
        'to_state_key',
        'transition_key',
        'idempotency_key',
        'requirements_snapshot',
        'before',
        'after',
        'message',
        'metadata',
    ];

    protected $casts = [
        'requirements_snapshot' => 'array',
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(TicketWorkflowVersion::class, 'workflow_version_id');
    }
}
