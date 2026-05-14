<?php

namespace App\Modules\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockUnit extends Model
{
    use SoftDeletes;

    protected $table = 'storage_stock_units';

    protected $fillable = [
        'item_id',
        'warehouse_id',
        'room_id',
        'box_id',
        'serial_no',
        'batch_no',
        'expiry_date',
        'status',
        'current_qty',
        'metadata',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'current_qty' => 'integer',
        'metadata' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
