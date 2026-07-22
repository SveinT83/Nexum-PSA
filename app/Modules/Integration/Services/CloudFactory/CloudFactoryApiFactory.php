<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;

class CloudFactoryApiFactory
{
    public function __construct(private readonly CloudFactoryAudit $audit) {}

    public function make(Integration $integration): CloudFactoryApiClient
    {
        return new CloudFactoryApiClient($integration, $this->audit);
    }
}
