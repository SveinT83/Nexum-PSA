<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketQueue extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'ticket_queues';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'email_address',
        'is_default',
        'is_active',
        'sort_order',
        'settings',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'queue_id');
    }
}
