<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;

class TicketRuleAuthorityFence extends Model
{
    public const SCOPE = 'ticket_rules';

    public const AUTHORITY_LEGACY = 'legacy';

    public const AUTHORITY_V2 = 'v2';

    protected $primaryKey = 'scope';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'catalog_generation' => 'integer',
        'runtime_activated_at' => 'datetime',
        'runtime_activated_by' => 'integer',
    ];
}
