<?php

namespace App\Modules\Commercial\Models\Contracts;

use App\Models\Clients\Client;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientContractTimeConsumption extends Model
{
    protected $fillable = [
        'client_id',
        'contract_id',
        'contract_item_id',
        'contract_item_time_rate_id',
        'time_rate_id',
        'user_id',
        'work_date',
        'minutes',
        'note',
        'source',
        'rate_name',
        'rate_code',
        'rate_type',
        'rate_unit',
        'rate_amount_ex_vat',
        'rate_currency',
        'period_start',
        'period_end',
        'included_minutes_snapshot',
        'used_before_minutes_snapshot',
        'overused_minutes',
    ];

    protected $casts = [
        'work_date' => 'date',
        'minutes' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'rate_amount_ex_vat' => 'decimal:2',
        'included_minutes_snapshot' => 'integer',
        'used_before_minutes_snapshot' => 'integer',
        'overused_minutes' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contracts::class);
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function contractItemTimeRate(): BelongsTo
    {
        return $this->belongsTo(ContractItemTimeRate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
