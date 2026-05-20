<?php

namespace App\Modules\Ticket\Models;

use App\Models\Clients\Client;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Economy\Models\EconomyOrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketTimeEntryAllocation extends Model
{
    protected $fillable = [
        'ticket_time_entry_id',
        'ticket_id',
        'client_id',
        'contract_id',
        'contract_item_id',
        'period_start',
        'period_end',
        'included_minutes',
        'covered_minutes',
        'billable_minutes',
        'economy_order_line_id',
        'status',
        'metadata',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'included_minutes' => 'integer',
        'covered_minutes' => 'integer',
        'billable_minutes' => 'integer',
        'metadata' => 'array',
    ];

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TicketTimeEntry::class, 'ticket_time_entry_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contracts::class);
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(EconomyOrderLine::class, 'economy_order_line_id');
    }
}
