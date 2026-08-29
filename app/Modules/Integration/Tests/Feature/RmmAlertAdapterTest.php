<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Jobs\Integrations\Alerts\SyncNAbleAlertsJob;
use App\Jobs\Integrations\Alerts\SyncTacticalAlertsJob;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Models\Tech\Work\Assets\Asset;
use App\Models\Tech\Work\Assets\AssetAlert;
use App\Modules\Integration\Models\RmmAlertOccurrence;
use App\Modules\Integration\Models\RmmAlertRule;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RmmAlertAdapterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tactical_adapter_normalizes_numeric_strings_and_routes_only_failure_activations(): void
    {
        [$client, $site, $asset] = $this->assetContext();
        $integration = $this->integration('tactical_rmm', 'https://tactical.test');
        $asset->rmmLinks()->create([
            'integration_id' => $integration->id,
            'external_id' => 'agent-42',
        ]);
        $this->ignoreRule('tactical');
        $passing = [[
            'id' => 'check-1',
            'readable_desc' => 'Backup check',
            'check_type' => 'backup',
            'severity' => 'critical',
            'fails_b4_alert' => '2',
            'check_result' => [
                'status' => 'passing',
                'retcode' => '0',
                'fail_count' => '2',
            ],
        ]];
        $failing = $passing;
        $failing[0]['check_result']['retcode'] = '1';

        Http::fake([
            'https://tactical.test/agents/agent-42/checks/' => Http::sequence()
                ->push($passing)
                ->push($failing)
                ->push([], 503)
                ->push($passing)
                ->push($failing),
        ]);

        SyncTacticalAlertsJob::dispatchSync($integration->id, $asset->id);
        $this->assertDatabaseCount('asset_alerts', 0);

        SyncTacticalAlertsJob::dispatchSync($integration->id, $asset->id);
        $alert = AssetAlert::query()->firstOrFail();
        $this->assertSame('active', $alert->status);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('tactical:'.$asset->id.':check-1', $alert->fingerprint);
        $first = RmmAlertOccurrence::query()->firstOrFail();
        $this->assertSame('triggered', $first->event_type);
        $this->assertSame($client->id, data_get($first->context, 'client_id'));
        $this->assertSame($site->id, data_get($first->context, 'site_id'));
        $this->assertSame('backup', data_get($first->context, 'provider.check_type'));
        $this->assertSame(1, data_get($first->context, 'provider.return_code'));

        SyncTacticalAlertsJob::dispatchSync($integration->id, $asset->id);
        $this->assertSame('active', $alert->fresh()->status);
        $this->assertDatabaseCount('rmm_alert_occurrences', 1);

        SyncTacticalAlertsJob::dispatchSync($integration->id, $asset->id);
        $this->assertSame('resolved', $alert->fresh()->status);
        $this->assertNotNull($first->fresh()->resolved_at);

        SyncTacticalAlertsJob::dispatchSync($integration->id, $asset->id);
        $this->assertSame('active', $alert->fresh()->status);
        $this->assertDatabaseCount('rmm_alert_occurrences', 2);
        $this->assertDatabaseCount('rmm_alert_rule_executions', 2);
        $this->assertDatabaseCount('rmm_alert_work_items', 2);
        $second = RmmAlertOccurrence::query()->latest('sequence')->firstOrFail();
        $this->assertSame('reopened', $second->event_type);
        $this->assertSame(2, $second->sequence);

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tactical.test/agents/agent-42/checks/'
            && $request->hasHeader('X-API-KEY', 'test-api-key'));
    }

    #[Test]
    public function nable_adapter_preserves_optional_priority_and_check_type_without_resolving_on_http_error(): void
    {
        [$client, $site, $asset] = $this->assetContext();
        $integration = $this->integration('rmm', 'https://nable.test');
        $asset->rmmLinks()->create([
            'integration_id' => $integration->id,
            'external_id' => 'device-9',
        ]);
        $this->ignoreRule('nable');
        $failingXml = <<<'XML'
<?xml version="1.0"?>
<result status="OK"><items><client><site><workstations><workstation><id>device-9</id><failed_checks><check>
<checkid>check-9</checkid><description>Disk space failed</description>
<status>failing</status><priority>high</priority><checktype>disk_space</checktype>
</check></failed_checks></workstation></workstations></site></client></items></result>
XML;
        $emptyXml = '<?xml version="1.0"?><result status="OK"><items /></result>';

        Http::fake([
            'https://nable.test/api/*' => Http::sequence()
                ->push($failingXml, 200, ['Content-Type' => 'application/xml'])
                ->push('', 503)
                ->push($emptyXml, 200, ['Content-Type' => 'application/xml'])
                ->push($failingXml, 200, ['Content-Type' => 'application/xml']),
        ]);

        SyncNAbleAlertsJob::dispatchSync($integration->id, $asset->id);
        $alert = AssetAlert::query()->firstOrFail();
        $this->assertSame('active', $alert->status);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('nable:'.$asset->id.':check-9', $alert->fingerprint);
        $first = RmmAlertOccurrence::query()->firstOrFail();
        $this->assertSame('triggered', $first->event_type);
        $this->assertSame($client->id, data_get($first->context, 'client_id'));
        $this->assertSame($site->id, data_get($first->context, 'site_id'));
        $this->assertSame('disk_space', data_get($first->context, 'provider.check_type'));
        $this->assertSame('high', data_get($first->context, 'provider.raw_severity'));

        SyncNAbleAlertsJob::dispatchSync($integration->id, $asset->id);
        $this->assertSame('active', $alert->fresh()->status);
        $this->assertDatabaseCount('rmm_alert_occurrences', 1);

        SyncNAbleAlertsJob::dispatchSync($integration->id, $asset->id);
        $this->assertSame('resolved', $alert->fresh()->status);
        $this->assertNotNull($first->fresh()->resolved_at);

        SyncNAbleAlertsJob::dispatchSync($integration->id, $asset->id);
        $this->assertSame('active', $alert->fresh()->status);
        $this->assertDatabaseCount('rmm_alert_occurrences', 2);
        $this->assertDatabaseCount('rmm_alert_rule_executions', 2);
        $this->assertDatabaseCount('rmm_alert_work_items', 2);
        $this->assertSame('reopened', RmmAlertOccurrence::query()->latest('sequence')->value('event_type'));

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://nable.test/api/?apikey=test-api-key&service=list_failing_checks'
            && $request['service'] === 'list_failing_checks');
    }

    #[Test]
    public function tactical_adapter_fails_safely_when_credentials_are_unavailable(): void
    {
        [, , $asset] = $this->assetContext();
        $integration = Integration::query()->create([
            'name' => 'Tactical missing secret',
            'type' => 'tactical_rmm',
            'server' => 'https://tactical.test',
            'status' => 'active',
            'secrets' => [],
        ]);
        Http::fake();

        SyncTacticalAlertsJob::dispatchSync($integration->id, $asset->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('asset_alerts', 0);
    }

    #[Test]
    public function nable_successful_poll_recovers_a_stale_unfinished_occurrence_after_resolution(): void
    {
        [, , $asset] = $this->assetContext();
        $integration = $this->integration('rmm', 'https://nable.test');
        $asset->rmmLinks()->create([
            'integration_id' => $integration->id,
            'external_id' => 'device-stale',
        ]);
        $rule = $this->ignoreRule('nable');
        $alert = AssetAlert::query()->create([
            'asset_id' => $asset->id,
            'integration_type' => 'nable',
            'fingerprint' => 'nable:'.$asset->id.':stale-check',
            'title' => 'Stale resolved processing',
            'status' => 'resolved',
            'severity' => 'warning',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'resolved_at' => now(),
        ]);
        $occurrence = RmmAlertOccurrence::query()->create([
            'asset_alert_id' => $alert->id,
            'sequence' => 1,
            'event_type' => 'triggered',
            'integration_type' => 'nable',
            'fingerprint' => $alert->fingerprint,
            'severity' => 'warning',
            'title' => $alert->title,
            'context' => [
                'asset_id' => $asset->id,
                'client_id' => $asset->client_id,
                'site_id' => $asset->site_id,
            ],
            'occurred_at' => now(),
            'resolved_at' => now(),
            'processing_status' => 'processing',
            'processing_started_at' => now(),
            'processing_token' => '33333333-3333-4333-8333-333333333333',
        ]);
        $execution = $occurrence->executions()->create([
            'rmm_alert_rule_id' => $rule->id,
            'rule_key' => $rule->rule_key,
            'rule_revision' => $rule->revision,
            'rule_name' => $rule->name,
            'matched' => true,
            'status' => 'evaluating',
            'rule_snapshot' => ['conditions' => $rule->conditions, 'actions' => $rule->actions],
            'condition_results' => [],
            'started_at' => now(),
        ]);
        $emptyXml = '<?xml version="1.0"?><result status="OK"><items /></result>';
        Http::fake([
            'https://nable.test/api/*' => Http::sequence()->push($emptyXml)->push($emptyXml),
        ]);

        SyncNAbleAlertsJob::dispatchSync($integration->id, $asset->id);
        $this->assertSame('processing', $occurrence->fresh()->processing_status);

        $this->travel(16)->minutes();
        SyncNAbleAlertsJob::dispatchSync($integration->id, $asset->id);

        $this->assertSame('failed', $execution->fresh()->status);
        $this->assertSame('completed_with_failures', $occurrence->fresh()->processing_status);
        $this->assertNotNull($occurrence->fresh()->processed_at);
        $this->assertNull($occurrence->fresh()->processing_token);
    }

    #[Test]
    public function nable_client_sanitizes_connection_exceptions_that_can_contain_the_api_key(): void
    {
        $integration = $this->integration('rmm', 'https://nable.test');
        Log::spy();
        Http::fake(fn (Request $request) => throw new \RuntimeException(
            'GET '.$request->url().' failed with apikey=test-api-key'
        ));

        $result = (new NAbleRmmClient($integration))->listFailingChecks();

        $this->assertSame(['error' => 'N-able RMM request failed.'], $result);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'N-able RMM listFailingChecks request failed.'
                && $context === ['exception' => \RuntimeException::class]);
    }

    /** @return array{Client, ClientSite, Asset} */
    private function assetContext(): array
    {
        $client = Client::factory()->create(['name' => 'Adapter Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Adapter Site']);
        $asset = Asset::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'name' => 'Adapter Agent',
            'hostname' => 'adapter-agent',
            'type' => Asset::TYPE_SERVER,
            'source' => 'rmm',
            'status' => 'active',
        ]);

        return [$client, $site, $asset];
    }

    private function integration(string $type, string $server): Integration
    {
        $integration = Integration::query()->create([
            'name' => strtoupper($type).' test',
            'type' => $type,
            'server' => $server,
            'status' => 'active',
            'secrets' => [],
        ]);
        $integration->setSecret('api_key', 'test-api-key');
        $integration->save();

        return $integration->fresh();
    }

    private function ignoreRule(string $provider): RmmAlertRule
    {
        return RmmAlertRule::query()->create([
            'name' => 'Ignore '.$provider.' adapter test',
            'is_active' => true,
            'priority' => 10,
            'conditions' => ['integration_types' => [$provider]],
            'actions' => [['type' => 'ignore']],
        ]);
    }
}
