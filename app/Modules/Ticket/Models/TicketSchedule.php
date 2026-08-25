<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSchedule extends Model
{
    protected $fillable = [
        'ticket_id',
        'schedule_type',
        'planned_start_at',
        'planned_end_at',
        'timezone',
        'recurrence_rule',
        'recurrence_ends_at',
        'calendar_event_id',
        'sla_mode',
        'status',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'planned_start_at' => 'datetime',
        'planned_end_at' => 'datetime',
        'recurrence_ends_at' => 'datetime',
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
