<?php

namespace App\Models\Clients;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientSite extends Model
{
    use HasFactory;

    protected $table = 'client_sites'; // Sikrer at vi bruker riktig tabell

    protected $fillable = [
        'client_id',
        'name',
        'address',
        'co_address',
        'zip',
        'city',
        'county',
        'country',
        'is_default',
    ];

    /**
     * @return BelongsTo<Client, ClientSite>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientUser::class, 'client_site_id');
    }
}
