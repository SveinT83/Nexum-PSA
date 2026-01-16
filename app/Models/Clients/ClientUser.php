<?php

namespace App\Models\Clients;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_site_id',
        'user_id',
        'role',
        'name',
        'email',
        'phone',
        'address',
        'co_address',
        'zip',
        'city',
        'county',
        'country',
        'is_default_for_site',
        'is_default_for_client',
        'active',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
