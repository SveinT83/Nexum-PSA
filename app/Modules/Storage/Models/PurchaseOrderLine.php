<?php

namespace App\Modules\Storage\Models;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPlannedLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $table = 'storage_purchase_order_lines';

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'ticket_id',
        'ticket_planned_line_id',
        'qty_ordered',
        'qty_received',
        'unit_cost',
        'tax_rate',
        'expected_at',
        'metadata',
    ];

    protected $casts = [
        'qty_ordered' => 'integer',
        'qty_received' => 'integer',
        'unit_cost' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'expected_at' => 'date',
        'metadata' => 'array',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function ticketPlannedLine(): BelongsTo
    {
        return $this->belongsTo(TicketPlannedLine::class);
    }
}
