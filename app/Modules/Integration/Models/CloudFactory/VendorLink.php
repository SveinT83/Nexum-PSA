<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\System\Integrations\Integration;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorLink extends CloudFactoryModel
{
    protected $table = 'cloudfactory_vendor_links';

    protected function casts(): array
    {
        return [
            'provider_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
