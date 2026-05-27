<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\ContractItemTimeRate;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\TimeRate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TicketTimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'type',
        'work_date',
        'started_at',
        'ended_at',
        'minutes',
        'cost_account',
        'note',
        'billable',
        'billing_status',
        'timebank_status',
        'billing_basis',
        'invoice_text',
        'contract_id',
        'contract_item_id',
        'contract_item_time_rate_id',
        'time_rate_id',
        'rate_name',
        'rate_code',
        'rate_type',
        'rate_unit',
        'rate_amount_ex_vat',
        'rate_currency',
    ];

    protected $casts = [
        'work_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'minutes' => 'integer',
        'billable' => 'boolean',
        'rate_amount_ex_vat' => 'decimal:2',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contracts::class, 'contract_id');
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function contractItemTimeRate(): BelongsTo
    {
        return $this->belongsTo(ContractItemTimeRate::class);
    }

    public function timeRate(): BelongsTo
    {
        return $this->belongsTo(TimeRate::class);
    }

    public function allocation(): HasOne
    {
        return $this->hasOne(TicketTimeEntryAllocation::class);
    }
}
