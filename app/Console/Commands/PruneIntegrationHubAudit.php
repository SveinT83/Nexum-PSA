<?php

namespace App\Console\Commands;

use App\Modules\Integration\Models\IntegrationHubAuditEvent;
use App\Modules\Integration\Models\IntegrationHubExecution;
use App\Modules\Integration\Models\IntegrationHubExecutionGrant;
use Illuminate\Console\Command;

class PruneIntegrationHubAudit extends Command
{
    protected $signature = 'integration-hub:prune';

    protected $description = 'Prune expired Integration Hub audit, grants, and completed execution metadata.';

    public function handle(): int
    {
        $audit = IntegrationHubAuditEvent::query()->whereNotNull('retain_until')->where('retain_until', '<', now())->delete();
        $grants = IntegrationHubExecutionGrant::query()->where('expires_at', '<', now()->subDay())->delete();
        $executions = IntegrationHubExecution::query()->whereNotNull('retain_until')->where('retain_until', '<', now())->delete();
        $this->info("Pruned audit={$audit}, grants={$grants}, executions={$executions}.");

        return self::SUCCESS;
    }
}
