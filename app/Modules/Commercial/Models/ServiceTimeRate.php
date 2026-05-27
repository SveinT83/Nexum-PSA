<?php

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTimeRate extends Model
{
    protected $fillable = [
        'service_id',
        'time_rate_id',
        'amount_ex_vat',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_ex_vat' => 'decimal:2',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Services::class, 'service_id');
    }

    public function timeRate(): BelongsTo
    {
        return $this->belongsTo(TimeRate::class);
    }
}
