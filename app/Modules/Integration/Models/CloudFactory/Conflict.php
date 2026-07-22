<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conflict extends CloudFactoryModel
{
    protected $table = 'cloudfactory_conflicts';

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'candidate_ids' => 'array',
            'provider_payload' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
