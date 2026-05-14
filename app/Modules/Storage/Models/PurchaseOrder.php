<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $table = 'storage_purchase_orders';

    protected $fillable = [
        'po_number',
        'vendor_id',
        'deliver_to_warehouse_id',
        'status',
        'vendor_ref',
        'tracking_no',
        'ordered_at',
        'expected_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'ordered_at' => 'date',
        'expected_at' => 'date',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function deliverToWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'deliver_to_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
