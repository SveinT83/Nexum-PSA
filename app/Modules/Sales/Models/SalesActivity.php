<?php

namespace App\Modules\Sales\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesActivity extends Model
{
    protected $fillable = [
        'opportunity_id',
        'actor_id',
        'type',
        'direction',
        'subject',
        'body',
        'is_unread',
        'read_at',
        'metadata',
    ];

    protected $casts = [
        'is_unread' => 'boolean',
        'read_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(SalesOpportunity::class, 'opportunity_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
