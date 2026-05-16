<?php

namespace App\Modules\Commercial\Models\Sla;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sla extends Model
{
    use SoftDeletes;

    protected $table = 'sla';

    protected $fillable = [
        'name',
        'description',
        'is_default',
        'low_firstResponse',
        'low_firstResponse_type',
        'low_onsite',
        'low_onsite_type',
        'medium_firstResponse',
        'medium_firstResponse_type',
        'medium_onsite',
        'medium_onsite_type',
        'high_firstResponse',
        'high_firstResponse_type',
        'high_onsite',
        'high_onsite_type',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contracts::class, 'sla_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'sla_id');
    }
}
