<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoxEvent extends Model
{
    protected $table = 'storage_box_events';

    protected $fillable = [
        'box_id',
        'actor_id',
        'type',
        'from_warehouse_id',
        'to_warehouse_id',
        'from_room_id',
        'to_room_id',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
