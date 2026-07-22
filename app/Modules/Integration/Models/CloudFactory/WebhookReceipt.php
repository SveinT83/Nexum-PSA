<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\System\Integrations\Integration;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookReceipt extends CloudFactoryModel
{
    protected $table = 'cloudfactory_webhook_receipts';

    protected function casts(): array
    {
        return [
            'header_valid' => 'boolean',
            'sanitized_payload' => 'array',
            'provider_created_at' => 'datetime',
            'provider_sent_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
