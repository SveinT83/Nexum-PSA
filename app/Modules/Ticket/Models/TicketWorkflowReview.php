<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketWorkflowReview extends Model
{
    protected $fillable = [
        'ticket_id',
        'workflow_version_id',
        'state_key',
        'gate_key',
        'status',
        'evidence_fingerprint',
        'requirements_snapshot',
        'requested_by',
        'assigned_reviewer_id',
        'reviewed_by',
        'comment',
        'decided_at',
        'invalidated_at',
        'invalidation_reason',
        'metadata',
    ];

    protected $casts = [
        'requirements_snapshot' => 'array',
        'decided_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
