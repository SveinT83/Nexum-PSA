<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    protected $table = 'storage_reservations';

    protected $fillable = [
        'item_id',
        'warehouse_id',
        'box_id',
        'qty',
        'source_type',
        'source_id',
        'strength',
        'status',
        'created_by',
        'expires_at',
    ];

    protected $casts = [
        'qty' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
