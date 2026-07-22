<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Models\CloudFactory\SyncRun;

class CloudFactoryCustomerSync
{
    public function __construct(
        private readonly CloudFactoryApiFactory $apiFactory,
        private readonly CloudFactoryClientMapper $mapper,
        private readonly CloudFactorySyncProgress $progress,
    ) {}

    public function run(Integration $integration, SyncRun $run): void
    {
        $api = $this->apiFactory->make($integration);
        $page = 1;

        do {
            $payload = $api->get('/v2/customers/customers', [
                'pageIndex' => $page,
                'pageSize' => 250,
            ]);
            $customers = $payload['results'] ?? [];

            if ($page === 1) {
                $this->progress->setTotal(
                    $run,
                    'customers',
                    $this->progress->totalFromPayload($payload, 'results')
                );
            }

            foreach ($customers as $customer) {
                $result = $this->mapper->import($integration, $customer);
                $outcome = match ($result['status'] ?? 'skipped') {
                    'created', 'linked' => 'created',
                    'updated' => 'updated',
                    'conflict' => 'conflicted',
                    default => 'unchanged',
                };
                $this->progress->itemProcessed($run, 'customers', $outcome);
            }

            $totalPages = max(1, (int) data_get($payload, 'metadata.totalPages', 1));
            $page++;
        } while ($page <= $totalPages);
    }
}
