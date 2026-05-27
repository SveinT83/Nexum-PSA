<?php

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimeRate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'code',
        'rate_type',
        'unit',
        'amount_ex_vat',
        'currency',
        'description',
        'applies_without_contract',
        'applies_with_contract',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount_ex_vat' => 'decimal:2',
            'applies_without_contract' => 'boolean',
            'applies_with_contract' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Services::class, 'service_time_rates', 'time_rate_id', 'service_id')
            ->withPivot(['id', 'amount_ex_vat', 'is_active', 'metadata'])
            ->withTimestamps();
    }

    public function serviceRates(): HasMany
    {
        return $this->hasMany(ServiceTimeRate::class);
    }
}
