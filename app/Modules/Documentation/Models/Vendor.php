<?php

namespace App\Modules\Documentation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Canonical partner register for vendors, manufacturers, and suppliers.
 *
 * Documentation owns this master data, while other modules reference it for
 * assets, costs, inventory items, supplier lines, and purchase workflows.
 */
class Vendor extends Model
{
    protected $table = 'vendors';

    protected $fillable = [
        'name',
        'vendor_code',
        'org_no',
        'url',
        'phone',
        'email',
        'default_lead_time_days',
        'note',
        'terms',
        'is_vendor',
        'is_supplier',
        'is_manufacturer',
        'is_active',
    ];

    protected $casts = [
        'default_lead_time_days' => 'integer',
        'is_vendor' => 'boolean',
        'is_supplier' => 'boolean',
        'is_manufacturer' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function services()
    {
        return $this->hasMany(\App\Modules\Commercial\Models\Services\Services::class);
    }
}
