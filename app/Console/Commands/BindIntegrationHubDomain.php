<?php

namespace App\Console\Commands;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\IntegrationHubDomain;
use App\Modules\Integration\Services\IntegrationHub\DomainNormalizer;
use Illuminate\Console\Command;

class BindIntegrationHubDomain extends Command
{
    protected $signature = 'integration-hub:bind-domain
        {hostname : Domain or hostname to bind}
        {client : Nexum Client ID}
        {site : Nexum Site ID}
        {--integration= : Optional Integration UUID}
        {--environment=unknown : production, staging, development, test, or unknown}
        {--provider-reference= : Explicit provider object/subscription reference}
        {--transfer : Explicitly transfer an existing hostname binding to the requested owner}';

    protected $description = 'Create or update an explicit Nexum domain ownership binding without calling a provider.';

    public function handle(DomainNormalizer $normalizer): int
    {
        $client = Client::query()->find((int) $this->argument('client'));
        $site = ClientSite::query()->find((int) $this->argument('site'));
        if (! $client || ! $site || (int) $site->client_id !== (int) $client->id) {
            $this->error('Client/Site binding is invalid.');

            return self::FAILURE;
        }
        $environment = (string) $this->option('environment');
        if (! in_array($environment, ['production', 'staging', 'development', 'test', 'unknown'], true)) {
            $this->error('Environment is invalid.');

            return self::FAILURE;
        }

        $integrationId = $this->option('integration');
        $integration = $integrationId ? Integration::query()->where('installation_key', (string) config('integration-hub.installation_key'))->find($integrationId) : null;
        if ($integrationId && ! $integration) {
            $this->error('Integration is outside this installation or does not exist.');

            return self::FAILURE;
        }
        if ($integration && (($integration->owner_scope === 'client' && (int) $integration->client_id !== (int) $client->id)
            || ($integration->owner_scope === 'site' && (int) $integration->client_site_id !== (int) $site->id))) {
            $this->error('Integration ownership does not match the Client/Site binding.');

            return self::FAILURE;
        }
        if ($integration && $integration->environment !== 'unknown' && $environment !== 'unknown' && $integration->environment !== $environment) {
            $this->error('Integration environment does not match the domain binding.');

            return self::FAILURE;
        }

        try {
            $normalized = $normalizer->normalize((string) $this->argument('hostname'));
        } catch (IntegrationHubDeniedException $exception) {
            $this->error('Domain binding rejected: '.$exception->reasonCode.'.');

            return self::FAILURE;
        }

        $identity = [
            'installation_key' => (string) config('integration-hub.installation_key'),
            'environment' => $environment,
            'hostname_ascii' => $normalized['ascii'],
        ];
        $existing = IntegrationHubDomain::query()->where($identity)->first();
        $ownershipChanged = $existing && (
            (int) $existing->client_id !== (int) $client->id
            || (int) $existing->client_site_id !== (int) $site->id
            || (string) ($existing->integration_id ?? '') !== (string) ($integration?->id ?? '')
        );
        if ($ownershipChanged && ! $this->option('transfer')) {
            $this->error('Domain binding conflicts with an existing owner. Re-run with --transfer after review.');

            return self::FAILURE;
        }

        $metadata = (array) ($existing?->metadata ?? []);
        if ($ownershipChanged) {
            $metadata['last_transfer'] = [
                'from_client_id' => $existing->client_id,
                'from_site_id' => $existing->client_site_id,
                'from_integration_id' => $existing->integration_id,
                'transferred_at' => now()->toIso8601String(),
            ];
        }
        $domain = IntegrationHubDomain::query()->updateOrCreate($identity, [
            'hostname_unicode' => $normalized['unicode'],
            'client_id' => $client->id,
            'client_site_id' => $site->id,
            'integration_id' => $integration?->id,
            'provider_reference' => $this->option('provider-reference') ?: null,
            'lifecycle_state' => 'active',
            'verification_status' => 'unknown',
            'stale_after_seconds' => max(30, (int) config('integration-hub.default_stale_after_seconds', 900)),
            'observed_at' => null,
            'last_verified_at' => null,
            'metadata' => $metadata ?: null,
        ]);
        $this->info('Domain binding saved: '.$domain->id.'. Provider verification remains unknown.');

        return self::SUCCESS;
    }
}
