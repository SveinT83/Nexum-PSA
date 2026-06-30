<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Reservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketCostEntry extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'storage_item_id',
        'storage_reservation_id',
        'quantity',
        'item_name',
        'item_sku',
        'unit_price_ex_vat',
        'currency',
        'status',
        'billing_status',
        'invoice_text',
        'note',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_ex_vat' => 'decimal:2',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storageItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'storage_item_id')->withTrashed();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'storage_reservation_id');
    }
}
