<?php

namespace App\Modules\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use SoftDeletes;

    protected $table = 'storage_vendors';

    protected $fillable = [
        'name',
        'vendor_code',
        'email',
        'phone',
        'website',
        'default_lead_time_days',
        'terms',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'default_lead_time_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function itemVendors(): HasMany
    {
        return $this->hasMany(ItemVendor::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
