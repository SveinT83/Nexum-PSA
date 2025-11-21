<?php

namespace App\Models\CS\Services;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Services extends Model
{
    use HasFactory;

    protected $table = 'services';

    protected $fillable = [
        'name', //Name of service
        'sku', //Stock Keeping Unit
        'status', //Missing
        'icon', //Missing
        'sort_order', //Missing
        'queue_default_id', //ID of default queue

        // Service availability is so an service can be made available only when another service is present on the contract
        'availability_addon_of_service_id', //The ID of the service this is an addon of
        'availability_audience', // Defines target audience/group that can access this service. Private, business etc.
        'orderable_in_client_portal', //True if an client can order this service directly
        'taxable', //Check
        'price_ex_vat', //Check
        'billing_interval', //Check
        'unit_pricing', //Check
        'one_time_fee', //Check
        'one_time_fee_recurrence', //Check
        'recurrence_value_x', //Check
        'default_discount_value', //Check
        'default_discount_type', //Check
        'timebank_enabled', // Determines if timebank is enabled for this service
        'timebank_minutes', // The amount of timebank minutes included
        'timebank_interval', // The renewal interval for the timebank
        'short_description', //Check
        'long_description', //Check
        'terms', //Used in Contracts
        'created_by_user_id', //Missing
        'updated_by_user_id', //Missing
        'published_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'orderable_in_client_portal' => 'bool',
            'taxable' => 'bool',
            'timebank_enabled' => 'bool',
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