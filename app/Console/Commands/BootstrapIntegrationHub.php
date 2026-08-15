<?php

namespace App\Console\Commands;

use App\Modules\Integration\Models\IntegrationHubSetting;
use App\Modules\Integration\Services\IntegrationHub\CapabilityRegistry;
use App\Modules\UserManagement\Actions\EnsureSystemActor;
use Illuminate\Console\Command;

class BootstrapIntegrationHub extends Command
{
    protected $signature = 'integration-hub:bootstrap {--enable : Enable the Hub after creating descriptors and bindings}';

    protected $description = 'Provision Integration Hub descriptors, installation bindings, and the protected MCP service actor.';

    public function handle(CapabilityRegistry $registry, EnsureSystemActor $ensureSystemActor): int
    {
        $settings = IntegrationHubSetting::current();
        $enableRequested = (bool) $this->option('enable');
        if ($enableRequested && strlen((string) config('integration-hub.active_grant_key')) < 32) {
            $this->error('Integration Hub cannot be enabled until a grant signing key of at least 32 characters is configured.');

            return self::FAILURE;
        }

        $actor = $ensureSystemActor->handle(
            (string) config('integration-hub.service_actor_key'),
            'Nexum Integration Hub MCP',
            'integration-hub-mcp@system.invalid',
            [],
        );
        $capabilities = $registry->sync($enableRequested || $settings->enabled, true, $actor->id);
        if ($enableRequested) {
            $settings->forceFill(['enabled' => true, 'updated_by' => $actor->id])->save();
        }

        $this->info(sprintf('Integration Hub provisioned: %d capabilities; enabled=%s.', $capabilities->count(), $settings->fresh()->enabled ? 'yes' : 'no'));
        $this->line('No service token or provider credential was created.');

        return self::SUCCESS;
    }
}
