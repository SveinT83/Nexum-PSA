<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketWorkflowEvidence extends Model
{
    protected $table = 'ticket_workflow_evidence';

    protected $fillable = [
        'ticket_id',
        'evidence_type',
        'scope_key',
        'source_type',
        'source_id',
        'fingerprint',
        'subject_name',
        'evidenced_at',
        'created_by',
        'invalidated_at',
        'invalidated_by',
        'invalidation_reason',
        'comment',
        'metadata',
    ];

    protected $casts = [
        'evidenced_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
