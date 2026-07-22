<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\System\Integrations\Integration;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends CloudFactoryModel
{
    protected $table = 'cloudfactory_sync_runs';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
