<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketWorkflowState extends Model
{
    protected $fillable = [
        'ticket_workflow_id',
        'ticket_status_id',
        'name',
        'is_initial',
        'is_terminal',
        'requires_note',
        'requires_response',
        'requires_resolution',
        'requires_knowledge_update',
        'sort_order',
    ];

    protected $casts = [
        'is_initial' => 'boolean',
        'is_terminal' => 'boolean',
        'requires_note' => 'boolean',
        'requires_response' => 'boolean',
        'requires_resolution' => 'boolean',
        'requires_knowledge_update' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(TicketWorkflow::class, 'ticket_workflow_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'ticket_status_id');
    }
}
