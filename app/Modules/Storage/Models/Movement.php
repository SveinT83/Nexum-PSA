<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movement extends Model
{
    protected $table = 'storage_movements';

    protected $fillable = [
        'item_id',
        'actor_id',
        'type',
        'qty_before',
        'qty_delta',
        'qty_after',
        'from_warehouse_id',
        'to_warehouse_id',
        'from_room_id',
        'to_room_id',
        'from_box_id',
        'to_box_id',
        'stock_unit_id',
        'source_type',
        'source_id',
        'reason',
        'note',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
