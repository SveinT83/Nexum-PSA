<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketWorkflowTransition extends Model
{
    protected $fillable = [
        'ticket_workflow_id',
        'transition_key',
        'from_state_key',
        'to_state_key',
        'from_status_id',
        'to_status_id',
        'label',
        'is_active',
        'manual_enabled',
        'trigger_actions',
        'requires_note',
        'requires_response',
        'requires_resolution',
        'requires_knowledge_update',
        'requirements',
        'customer_notification',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'manual_enabled' => 'boolean',
        'trigger_actions' => 'array',
        'requires_note' => 'boolean',
        'requires_response' => 'boolean',
        'requires_resolution' => 'boolean',
        'requires_knowledge_update' => 'boolean',
        'sort_order' => 'integer',
        'requirements' => 'array',
        'customer_notification' => 'array',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(TicketWorkflow::class, 'ticket_workflow_id');
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'to_status_id');
    }
}
