<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketStatus extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'ticket_statuses';

    protected $fillable = [
        'name',
        'slug',
        'state',
        'is_default',
        'is_closed',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_closed' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'status_id');
    }
}
