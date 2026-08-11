<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptReversal extends Model
{
    protected $table = 'storage_purchase_receipt_reversals';

    protected $fillable = [
        'original_receipt_id',
        'reversal_receipt_id',
        'reason',
        'actor_id',
    ];

    public function originalReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'original_receipt_id');
    }

    public function reversalReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'reversal_receipt_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
