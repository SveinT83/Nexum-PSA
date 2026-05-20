<?php

namespace App\Modules\Economy\Models;

use App\Models\Clients\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EconomyOrder extends Model
{
    protected $fillable = [
        'order_number',
        'client_id',
        'period_start',
        'period_end',
        'status',
        'subtotal_ex_vat',
        'vat_amount',
        'total_inc_vat',
        'created_by',
        'updated_by',
        'generated_at',
        'ready_at',
        'approved_at',
        'exported_at',
        'cancelled_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'subtotal_ex_vat' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_inc_vat' => 'decimal:2',
        'generated_at' => 'datetime',
        'ready_at' => 'datetime',
        'approved_at' => 'datetime',
        'exported_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EconomyOrderLine::class);
    }
}
