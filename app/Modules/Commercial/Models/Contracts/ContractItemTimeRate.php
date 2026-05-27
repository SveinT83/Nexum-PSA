<?php

namespace App\Modules\Commercial\Models\Contracts;

use App\Modules\Commercial\Models\ServiceTimeRate;
use App\Modules\Commercial\Models\TimeRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractItemTimeRate extends Model
{
    protected $fillable = [
        'contract_item_id',
        'time_rate_id',
        'service_time_rate_id',
        'name',
        'code',
        'rate_type',
        'unit',
        'amount_ex_vat',
        'currency',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_ex_vat' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function timeRate(): BelongsTo
    {
        return $this->belongsTo(TimeRate::class);
    }

    public function serviceTimeRate(): BelongsTo
    {
        return $this->belongsTo(ServiceTimeRate::class);
    }
}
