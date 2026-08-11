<?php

namespace App\Modules\Storage\Models;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPlannedLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends Model
{
    protected $table = 'storage_purchase_order_lines';

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'item_name_snapshot',
        'sku_snapshot',
        'supplier_sku_snapshot',
        'ticket_id',
        'ticket_planned_line_id',
        'qty_ordered',
        'qty_received',
        'qty_cancelled',
        'unit_cost',
        'tax_rate',
        'currency',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
        'expected_at',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'qty_ordered' => 'integer',
        'qty_received' => 'integer',
        'qty_cancelled' => 'integer',
        'unit_cost' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'expected_at' => 'date',
        'cancelled_at' => 'datetime',
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

    public function shipmentLines(): HasMany
    {
        return $this->hasMany(PurchaseShipmentLine::class);
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class);
    }

    public function getQtyOutstandingAttribute(): int
    {
        return max(0, $this->qty_ordered - $this->qty_received - $this->qty_cancelled);
    }
}
