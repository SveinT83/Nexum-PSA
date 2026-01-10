<?php

namespace App\Models\CS\Packages;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\CS\Services\Services;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'sales_price_user',
        'sales_price_asset',
        'sales_price_site',
        'sales_price_client',
        'sales_price_other',
        'status',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'sales_price_user' => 'decimal:2',
        'sales_price_asset' => 'decimal:2',
        'sales_price_site' => 'decimal:2',
        'sales_price_client' => 'decimal:2',
        'sales_price_other' => 'decimal:2',
    ];

    public function services()
    {
        return $this->belongsToMany(Services::class, 'package_service', 'package_id', 'service_id');
    }

    public function terms()
    {
        return $this->belongsToMany(
            \App\Models\CS\Terms\terms::class,
            'package_term_pivot',
            'package_id',
            'term_id'
        )->withTimestamps();
    }
}
