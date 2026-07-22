<?php

namespace App\Modules\Commercial\Models\Services;

use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\ServiceTimeRate;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Models\TimeRate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Services extends Model
{
    use HasFactory;

    protected $table = 'services';

    protected $fillable = [
        'name',
        'vendor_id',
        'source',
        'source_integration_id',
        'managed_externally',
        'cost_price',
        'suggested_sale_price',
        'price_currency',
        'price_mode',
        'price_markup_percent',
        'manual_price_override',
        'unitId',
        'sla_id',
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
        'category_id',
        'created_by_user_id',
        'updated_by_user_id',
        'published_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'orderable' => 'bool',
            'managed_externally' => 'bool',
            'taxable' => 'decimal:2',
            'timebank_enabled' => 'bool',
            'setup_fee' => 'decimal:2',
            'price_including_tax' => 'decimal:2',
            'price_ex_vat' => 'decimal:2',
            'cost_price' => 'decimal:4',
            'suggested_sale_price' => 'decimal:4',
            'price_markup_percent' => 'decimal:4',
            'manual_price_override' => 'boolean',
            'one_time_fee' => 'decimal:2',
            'default_discount_value' => 'decimal:2',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    // Add relationships here (queue, addon, creator, updater, clients whitelist)
    public function serviceTerms()
    {
        return $this->belongsToMany(
            \App\Modules\Commercial\Models\Terms\terms::class,
            'service_term_pivot',
            'service_id',
            'term_id'
        )->withTimestamps();
    }

    public function costRelations()
    {
        return $this->hasMany(\App\Modules\Commercial\Models\CostRelations::class, 'serviceId');
    }

    public function unit()
    {
        return $this->belongsTo(Units::class, 'unitId');
    }

    public function sla()
    {
        return $this->belongsTo(Sla::class, 'sla_id');
    }

    public function category()
    {
        return $this->belongsTo(\App\Modules\Taxonomy\Models\Category::class, 'category_id');
    }

    public function timeRates()
    {
        return $this->belongsToMany(TimeRate::class, 'service_time_rates', 'service_id', 'time_rate_id')
            ->withPivot(['id', 'amount_ex_vat', 'is_active', 'metadata'])
            ->withTimestamps();
    }

    public function serviceTimeRates()
    {
        return $this->hasMany(ServiceTimeRate::class, 'service_id');
    }

    public function vendor()
    {
        return $this->belongsTo(\App\Modules\Documentation\Models\Vendor::class);
    }

    public function cloudFactoryOffer()
    {
        return $this->hasOne(\App\Modules\Integration\Models\CloudFactory\Offer::class, 'service_id');
    }

    public function sourceIntegration()
    {
        return $this->belongsTo(Integration::class, 'source_integration_id');
    }

    public function isIntegrationManaged(): bool
    {
        return $this->managed_externally
            && $this->sourceIntegration?->status === 'active';
    }
}
