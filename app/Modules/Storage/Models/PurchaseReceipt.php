<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseReceipt extends Model
{
    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_REVERSAL = 'reversal';

    public const STATUS_POSTING = 'posting';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'storage_purchase_receipts';

    protected $fillable = [
        'receipt_number',
        'purchase_order_id',
        'purchase_shipment_id',
        'receipt_type',
        'status',
        'idempotency_token',
        'request_hash',
        'delivery_note_ref',
        'received_at',
        'warehouse_id',
        'room_id',
        'box_id',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(PurchaseShipment::class, 'purchase_shipment_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(PurchaseReceiptReversal::class, 'original_receipt_id');
    }

    public function reversalOf(): HasOne
    {
        return $this->hasOne(PurchaseReceiptReversal::class, 'reversal_receipt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
