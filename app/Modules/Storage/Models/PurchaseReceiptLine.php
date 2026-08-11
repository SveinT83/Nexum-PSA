<?php

namespace App\Modules\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReceiptLine extends Model
{
    protected $table = 'storage_purchase_receipt_lines';

    protected $fillable = [
        'purchase_receipt_id',
        'purchase_order_line_id',
        'item_id',
        'purchase_shipment_line_id',
        'reverses_receipt_line_id',
        'qty_accepted',
        'qty_rejected',
        'qty_on_hand_before',
        'qty_on_hand_after',
        'item_name_snapshot',
        'sku_snapshot',
        'supplier_sku_snapshot',
        'unit_cost_snapshot',
        'tax_rate_snapshot',
        'currency_snapshot',
        'discrepancy_note',
        'is_over_receipt',
        'over_receipt_reason',
        'metadata',
    ];

    protected $casts = [
        'qty_accepted' => 'integer',
        'qty_rejected' => 'integer',
        'qty_on_hand_before' => 'integer',
        'qty_on_hand_after' => 'integer',
        'unit_cost_snapshot' => 'decimal:2',
        'tax_rate_snapshot' => 'decimal:2',
        'is_over_receipt' => 'boolean',
        'metadata' => 'array',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function shipmentLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseShipmentLine::class, 'purchase_shipment_line_id');
    }

    public function reversedLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_receipt_line_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(PurchaseReceiptUnit::class);
    }
}
