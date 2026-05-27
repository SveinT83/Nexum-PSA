<?php

namespace App\Modules\Economy\Models;

use App\Models\Clients\Client;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EconomyOrderLine extends Model
{
    protected $fillable = [
        'economy_order_id',
        'client_id',
        'source_type',
        'source_id',
        'ticket_id',
        'work_date',
        'line_type',
        'description',
        'quantity',
        'unit',
        'unit_price_ex_vat',
        'line_total_ex_vat',
        'vat_rate',
        'vat_amount',
        'total_inc_vat',
        'currency',
        'status',
        'metadata',
    ];

    protected $casts = [
        'work_date' => 'date',
        'quantity' => 'decimal:2',
        'unit_price_ex_vat' => 'decimal:4',
        'line_total_ex_vat' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_inc_vat' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(EconomyOrder::class, 'economy_order_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
