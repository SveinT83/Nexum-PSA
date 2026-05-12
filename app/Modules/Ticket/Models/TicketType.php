<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketType extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
        'is_deletable',
        'is_active',
        'sort_order',
        'settings',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_deletable' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'ticket_type_id');
    }
}
