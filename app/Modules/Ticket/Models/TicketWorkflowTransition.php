<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketWorkflowTransition extends Model
{
    protected $fillable = [
        'ticket_workflow_id',
        'from_status_id',
        'to_status_id',
        'label',
        'is_active',
        'requires_note',
        'requires_resolution',
        'requires_knowledge_update',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_note' => 'boolean',
        'requires_resolution' => 'boolean',
        'requires_knowledge_update' => 'boolean',
        'sort_order' => 'integer',
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
