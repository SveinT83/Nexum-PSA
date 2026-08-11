<?php

namespace App\Modules\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseShipmentLine extends Model
{
    protected $table = 'storage_purchase_shipment_lines';

    protected $fillable = [
        'purchase_shipment_id',
        'purchase_order_line_id',
        'qty_allocated',
        'qty_received',
        'qty_rejected',
        'qty_cancelled',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'qty_allocated' => 'integer',
        'qty_received' => 'integer',
        'qty_rejected' => 'integer',
        'qty_cancelled' => 'integer',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(PurchaseShipment::class, 'purchase_shipment_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class);
    }

    public function getQtyOutstandingAttribute(): int
    {
        return max(
            0,
            $this->qty_allocated
                - $this->qty_received
                - $this->qty_rejected
                - $this->qty_cancelled
        );
    }
}
