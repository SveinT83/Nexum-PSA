<?php

namespace App\Modules\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptUnit extends Model
{
    protected $table = 'storage_purchase_receipt_units';

    protected $fillable = [
        'purchase_receipt_line_id',
        'stock_unit_id',
        'reverses_receipt_unit_id',
        'quantity',
        'serial_no_snapshot',
        'batch_no_snapshot',
        'expiry_date_snapshot',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expiry_date_snapshot' => 'date',
    ];

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id');
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    public function reversedUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_receipt_unit_id');
    }
}
