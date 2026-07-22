<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Operation extends CloudFactoryModel
{
    protected $table = 'cloudfactory_operations';

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
