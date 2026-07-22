<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketPlannedLine extends Model
{
    protected $fillable = [
        'ticket_id',
        'line_type',
        'source_type',
        'source_id',
        'storage_item_id',
        'section',
        'downstream_type',
        'sku',
        'name',
        'description',
        'quantity',
        'unit',
        'unit_cost_ex_vat',
        'unit_price_ex_vat',
        'vat_rate',
        'status',
        'approved_quote_version_id',
        'converted_cost_entry_id',
        'created_by',
        'updated_by',
        'snapshot',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost_ex_vat' => 'decimal:2',
        'unit_price_ex_vat' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'snapshot' => 'array',
        'metadata' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function storageItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'storage_item_id')->withTrashed();
    }

    public function approvedQuoteVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'approved_quote_version_id');
    }

    public function convertedCostEntry(): BelongsTo
    {
        return $this->belongsTo(TicketCostEntry::class, 'converted_cost_entry_id');
    }

    public function purchaseOrderLine(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PurchaseOrderLine::class, 'ticket_planned_line_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
