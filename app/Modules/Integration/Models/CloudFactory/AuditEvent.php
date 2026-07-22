<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends CloudFactoryModel
{
    protected $table = 'cloudfactory_audit_events';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
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
