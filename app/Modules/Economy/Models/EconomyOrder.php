<?php

namespace App\Modules\Economy\Models;

use App\Models\Clients\Client;
use App\Models\Core\User;
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
        'portal_visible_at',
        'portal_visible_by',
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
        'portal_visible_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EconomyOrderLine::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function portalVisibleBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'portal_visible_by');
    }

    public function isPortalVisible(): bool
    {
        return $this->portal_visible_at !== null;
    }
}
