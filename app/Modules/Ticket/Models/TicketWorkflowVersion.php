<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketWorkflowVersion extends Model
{
    protected $fillable = [
        'ticket_workflow_id',
        'version',
        'status',
        'definition',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'definition' => 'array',
        'published_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(TicketWorkflow::class, 'ticket_workflow_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'workflow_version_id');
    }
}
