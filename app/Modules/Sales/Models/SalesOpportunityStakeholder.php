<?php

namespace App\Modules\Sales\Models;

use App\Models\Clients\ClientUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOpportunityStakeholder extends Model
{
    protected $fillable = [
        'opportunity_id',
        'client_user_id',
        'role',
        'is_primary',
        'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(SalesOpportunity::class, 'opportunity_id');
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }
}
