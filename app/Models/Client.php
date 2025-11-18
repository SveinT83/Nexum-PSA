<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'client_number',
        'org_no',
        'billing_email',
        'notes',
        'active',
    ];

    public function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function sites(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ClientSite::class);
    }

    public function contacts(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            \App\Models\ClientUser::class,
            \App\Models\ClientSite::class,
            'client_id',      // Foreign key on client_sites table...
            'client_site_id'  // Foreign key on client_users table...
        );
    }
}
