<?php

namespace App\Models\CS\Services;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Services extends Model
{
    use HasFactory;

    protected $table = 'services';

    protected $fillable = [
        'name',
        'sku',
        'status',
        'icon',
        'sort_order',
        'queue_default_id',
        'availability_addon_of_service_id',
        'availability_audience',
        'orderable',
        'taxable',
        'setup_fee',
        'billing_cycle',
        'price_including_tax',
        'price_ex_vat',
        'one_time_fee',
        'one_time_fee_recurrence',
        'recurrence_value_x',
        'default_discount_value',
        'default_discount_type',
        'timebank_enabled',
        'timebank_minutes',
        'timebank_interval',
        'short_description',
        'long_description',
        'terms',
        'created_by_user_id',
        'updated_by_user_id',
        'published_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'orderable' => 'bool',
            'taxable' => 'decimal:2',
            'timebank_enabled' => 'bool',
            'setup_fee' => 'decimal:2',
            'price_including_tax' => 'decimal:2',
            'price_ex_vat' => 'decimal:2',
            'one_time_fee' => 'decimal:2',
            'default_discount_value' => 'decimal:2',
            'terms' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    // Add relationships here (queue, addon, creator, updater, clients whitelist)
}
