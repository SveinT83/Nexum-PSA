<?php

namespace App\Modules\Asset\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Real HTTP behavior, not controller implementation details. */
class AssetApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Tech'));
        $this->tech->givePermissionTo(['asset.view', 'asset.create', 'asset.update', 'asset.manage_settings']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        Sanctum::actingAs($this->tech, ['assets.read', 'assets.create', 'assets.update', 'assets.settings.read', 'assets.settings.update', 'assets.sync']);
    }

    public function test_context_exposes_manual_defaults_and_classifications(): void
    {
        $this->getJson('/api/v1/assets/context')->assertOk()
            ->assertJsonPath('data.defaults.default_type', 'other')
            ->assertJsonPath('data.sensitivity_levels.3', 'ultra')
            ->assertJsonPath('data.provider_execution_enabled', false);
    }

    public function test_classification_round_trips_through_partial_update(): void
    {
        $id = $this->postJson('/api/v1/assets', ['name' => 'Classified asset'])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/assets/'.$id, ['sensitivity_level' => 'ultra', 'criticality_level' => 'critical'])
            ->assertOk()->assertJsonPath('data.sensitivity_level', 'ultra')->assertJsonPath('data.criticality_level', 'critical');
        $this->getJson('/api/v1/assets/'.$id)->assertOk()->assertJsonPath('data.sensitivity_level', 'ultra');
    }

    public function test_all_browser_filters_and_stable_sort_are_available_without_session_state(): void
    {
        $c = Client::factory()->create();
        $one = Asset::create(['client_id' => $c->id, 'name' => 'Needle B', 'hostname' => 'target', 'type' => 'server', 'status' => 'warning', 'sensitivity_level' => 'high', 'criticality_level' => 'critical']);
        $two = Asset::create(['client_id' => $c->id, 'name' => 'Needle A', 'type' => 'pc', 'status' => 'online']);
        \App\Models\Tech\Work\Assets\AssetAlert::create(['asset_id' => $one->id, 'fingerprint' => 'filter-alert', 'integration_type' => 'tactical', 'title' => 'Fail', 'status' => 'active']);
        $this->getJson('/api/v1/assets?'.http_build_query(['search' => 'target', 'client_id' => $c->id, 'type' => 'server', 'status' => 'warning', 'sensitivity_level' => 'high', 'criticality_level' => 'critical', 'has_alerts' => true, 'sort' => 'client_site', 'direction' => 'asc', 'per_page' => 1]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $one->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/assets?sort=name&direction=asc')->assertOk()->assertJsonPath('data.0.id', $two->id)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/assets?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/assets?sort=raw_sql')->assertUnprocessable();
    }

    public function test_settings_use_the_browser_rules_and_do_not_widen_existing_tokens(): void
    {
        $body = ['enabled_types' => ['laptop', 'other'], 'default_type' => 'laptop', 'default_ip_type' => 'fixed', 'default_status' => 'in_service'];
        $revision = $this->getJson('/api/v1/assets/settings')->assertOk()->json('revision');
        $this->withHeader('If-Match', $revision)->putJson('/api/v1/assets/settings', $body)->assertOk()->assertJsonPath('data.default_type', 'laptop');
        $this->withHeader('If-Match', '"stale"')->putJson('/api/v1/assets/settings', $body)->assertConflict();
        $this->flushHeaders();
        $this->postJson('/api/v1/assets', ['name' => 'Defaulted'])->assertCreated()->assertJsonPath('data.type', 'laptop')->assertJsonPath('data.ip_type', 'fixed')->assertJsonPath('data.status', 'in_service');
        $this->postJson('/api/v1/assets', ['name' => 'Disabled', 'type' => 'server'])->assertUnprocessable();
        $this->putJson('/api/v1/assets/settings', $body + ['hidden_flag' => true])->assertUnprocessable();
        $invalid = $body;
        $invalid['default_type'] = 'server';
        $this->putJson('/api/v1/assets/settings', $invalid)->assertUnprocessable();
        Sanctum::actingAs($this->tech, ['assets.read', 'assets.update']);
        $this->getJson('/api/v1/assets/settings')->assertForbidden();
        $this->putJson('/api/v1/assets/settings', $body)->assertForbidden();
    }

    public function test_disabled_legacy_type_survives_edit_and_manual_form_uses_shared_action(): void
    {
        $a = Asset::create(['name' => 'Old server', 'type' => 'server', 'ip_type' => 'dhcp', 'status' => 'provider_custom']);
        app(\App\Modules\Asset\Support\AssetSettings::class)->update(['enabled_types' => ['other'], 'default_type' => 'other']);
        $this->patchJson('/api/v1/assets/'.$a->id, ['name' => 'Retained'])->assertOk()->assertJsonPath('data.type', 'server')->assertJsonPath('data.status', 'provider_custom');
        \Livewire\Livewire::actingAs($this->tech)->test(\App\Modules\Asset\Livewire\Tech\AssetForm::class, ['asset' => $a])
            ->set('name', 'Browser shared')->set('criticality_level', 'high')->call('save')->assertRedirect();
        $this->assertSame('high', $a->fresh()->criticality_level);
    }

    public function test_strict_fields_and_cross_client_relations_are_rejected(): void
    {
        $c1 = Client::factory()->create();
        $c2 = Client::factory()->create();
        $s1 = ClientSite::factory()->create(['client_id' => $c1->id]);
        $s2 = ClientSite::factory()->create(['client_id' => $c2->id]);
        $id = $this->postJson('/api/v1/assets', ['name' => 'Owned', 'client_id' => $c1->id, 'site_id' => $s1->id])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/assets/'.$id, ['site_id' => $s2->id])->assertUnprocessable();
        $this->patchJson('/api/v1/assets/'.$id, ['unsupported' => true])->assertUnprocessable();
        $this->patchJson('/api/v1/assets/'.$id, ['sensitivity_level' => 'secret'])->assertUnprocessable();
        $this->patchJson('/api/v1/assets/'.$id, ['client_id' => $c1->id])->assertOk()->assertJsonPath('data.site_id', $s1->id);
        $this->patchJson('/api/v1/assets/'.$id, ['client_id' => $c2->id])->assertOk()->assertJsonPath('data.site_id', null);
        $this->patchJson('/api/v1/assets/'.$id, ['client_id' => null])->assertOk()->assertJsonPath('data.work_context.type', 'internal');
        $this->postJson('/api/v1/assets', ['name' => 'Bad internal', 'site_id' => $s1->id])->assertUnprocessable();
        $this->postJson('/api/v1/assets', ['name' => 'Bad', 'source' => 'provider'])->assertUnprocessable();
    }

    public function test_lookups_are_client_scoped_and_project_only_reference_fields(): void
    {
        $one = Client::factory()->create();
        $two = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $one->id]);
        ClientSite::factory()->create(['client_id' => $two->id]);
        $this->getJson('/api/v1/assets/lookups/sites?client_id='.$one->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $site->id);
        $this->getJson('/api/v1/assets/lookups/sites')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/assets/lookups/owners')->assertOk()->assertJsonCount(0, 'data');
        $refs = $this->getJson('/api/v1/assets/lookups/clients')->assertOk()->json('data.0');
        $this->assertSame(['id', 'name'], array_keys($refs));
        $this->getJson('/api/v1/assets/lookups/vendors')->assertOk();
        $this->getJson('/api/v1/assets/lookups/secrets')->assertNotFound();
        $this->getJson('/api/v1/assets/docs')->assertOk()->assertJsonPath('data.format', 'markdown');
    }

    public function test_current_actor_permissions_are_required_independently_from_the_token(): void
    {
        $a = Asset::create(['name' => 'Permission target', 'type' => 'other']);
        $this->tech->revokePermissionTo('asset.update');
        $this->patchJson('/api/v1/assets/'.$a->id, ['name' => 'Denied'])->assertForbidden();
        $this->tech->revokePermissionTo('asset.manage_settings');
        $this->getJson('/api/v1/assets/settings')->assertForbidden();
        $this->tech->update(['status' => User::STATUS_DISABLED]);
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->assertSame('Permission target', $a->fresh()->name);
    }

    public function test_exact_keyed_replay_is_durable_and_revision_fences_stale_changes(): void
    {
        $this->withHeader('Idempotency-Key', 'asset-create-once');
        $body = ['name' => 'Exactly once'];
        $first = $this->postJson('/api/v1/assets', $body)->assertCreated()->json();
        $this->postJson('/api/v1/assets', $body)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $first['data']['id']);
        $this->postJson('/api/v1/assets', ['name' => 'Different'])->assertConflict();
        $this->assertDatabaseCount('assets', 1);
        $this->flushHeaders();
        $read = $this->getJson('/api/v1/assets/'.$first['data']['id'])->assertOk();
        $revision = $read->headers->get('ETag');
        $this->withHeaders(['Idempotency-Key' => 'asset-update-once', 'If-Match' => $revision]);
        $this->patchJson('/api/v1/assets/'.$first['data']['id'], ['name' => 'New'])->assertOk();
        $this->patchJson('/api/v1/assets/'.$first['data']['id'], ['name' => 'New'])->assertOk()->assertJsonPath('replayed', true);
        $this->withHeader('Idempotency-Key', 'asset-update-stale')->patchJson('/api/v1/assets/'.$first['data']['id'], ['name' => 'Stale'])->assertConflict();
        $this->flushHeaders();
        $this->getJson('/api/v1/assets/operations/'.$first['operation_id'])->assertOk()->assertJsonPath('data.state', 'completed');
        $raw = \Illuminate\Support\Facades\DB::table('asset_api_commands')->where('id', $first['operation_id'])->value('result');
        $this->assertStringNotContainsString('Exactly once', $raw);
        Sanctum::actingAs($this->tech, ['assets.read']);
        $this->getJson('/api/v1/assets/operations/'.$first['operation_id'])->assertForbidden();
    }

    public function test_alert_tabs_and_summary_share_exact_client_and_asset_scope(): void
    {
        $c1 = Client::factory()->create();
        $c2 = Client::factory()->create();
        $a1 = Asset::create(['name' => 'First', 'type' => 'other', 'client_id' => $c1->id]);
        $a2 = Asset::create(['name' => 'Second', 'type' => 'other', 'client_id' => $c2->id]);
        foreach ([[$a1, 'active', null], [$a1, 'resolved', now()], [$a2, 'active', null]] as $i => [$a, $status, $resolved]) {
            \App\Models\Tech\Work\Assets\AssetAlert::create(['asset_id' => $a->id, 'fingerprint' => 'alert-'.$i, 'integration_type' => 'tactical', 'title' => 'Alert '.$i, 'message' => 'Check details', 'status' => $status, 'resolved_at' => $resolved]);
        }
        $this->getJson('/api/v1/assets/alerts?client_id='.$c1->id)->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/assets/alerts?asset_id='.$a1->id.'&status=active&unresolved_only=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.message', 'Check details');
        $this->getJson('/api/v1/assets/alerts/summary?asset_id='.$a1->id)->assertOk()->assertJsonPath('data.active_count', 1)->assertJsonPath('data.resolved_last_day_count', 1);
        $this->getJson('/api/v1/assets/alerts?status=anything')->assertUnprocessable();
    }

    public function test_provider_execution_stays_disabled_and_preview_does_not_write(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $before = \App\Modules\Asset\Models\AssetApiCommand::count();
        $this->postJson('/api/v1/assets/alerts/sync/preview', [])->assertOk()->assertJsonPath('data.execution_enabled', false);
        $this->postJson('/api/v1/assets/alerts/sync', ['confirm' => true])->assertConflict();
        $this->assertSame($before, \App\Modules\Asset\Models\AssetApiCommand::count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_provider_confirmation_stale_preview_and_interrupted_receipt_never_repeat_work(): void
    {
        config(['assets-api.provider_execution_enabled' => true]);
        $this->postJson('/api/v1/assets/alerts/sync', [])->assertStatus(428);
        $plan = $this->postJson('/api/v1/assets/alerts/sync/preview')->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'sync-alerts-empty');
        $body = ['confirm' => true, 'preview_revision' => $plan['preview_revision']];
        $first = $this->postJson('/api/v1/assets/alerts/sync', $body)->assertOk()->assertJsonPath('state', 'skipped')->json();
        $this->postJson('/api/v1/assets/alerts/sync', $body)->assertOk()->assertJsonPath('replayed', true);
        \App\Modules\Asset\Models\AssetApiCommand::whereKey($first['operation_id'])->update(['state' => 'running', 'result' => null]);
        $this->postJson('/api/v1/assets/alerts/sync', $body)->assertOk()->assertJsonPath('state', 'running')->assertJsonPath('reconciliation_required', true);
        $this->withHeader('Idempotency-Key', 'sync-alerts-stale');
        Asset::create(['name' => 'Scope changed', 'type' => 'other']);
        $this->postJson('/api/v1/assets/alerts/sync', $body)->assertConflict();
        $this->postJson('/api/v1/assets/alerts/sync', $body)->assertConflict();
    }

    public function test_tactical_alert_sync_reports_failed_transport_then_observed_success_and_replay(): void
    {
        config(['assets-api.provider_execution_enabled' => true]);
        $c = Client::factory()->create();
        $s = ClientSite::factory()->create(['client_id' => $c->id]);
        $a = Asset::create(['name' => 'Provider asset', 'type' => 'server', 'client_id' => $c->id, 'site_id' => $s->id]);
        $i = $this->integration('tactical_rmm');
        $a->rmmLinks()->create(['integration_id' => $i->id, 'external_id' => 'agent-1']);
        \Illuminate\Support\Facades\Http::fake(['https://assets-provider.test/*' => \Illuminate\Support\Facades\Http::sequence()->push([], 503)->push([['id' => 1, 'status' => 'passing']])]);
        $plan = $this->postJson('/api/v1/assets/alerts/sync/preview', ['asset_id' => $a->id])->assertOk()->json('data');
        $body = ['asset_id' => $a->id, 'confirm' => true, 'preview_revision' => $plan['preview_revision']];
        $this->withHeader('Idempotency-Key', 'tactical-alert-failure')->postJson('/api/v1/assets/alerts/sync', $body)->assertOk()->assertJsonPath('state', 'partial')->assertJsonPath('result.providers.0.state', 'failed');
        $this->withHeader('Idempotency-Key', 'tactical-alert-success')->postJson('/api/v1/assets/alerts/sync', $body)->assertOk()->assertJsonPath('state', 'completed')->assertJsonPath('result.providers.0.observed', 1);
        $this->postJson('/api/v1/assets/alerts/sync', $body)->assertOk()->assertJsonPath('replayed', true);
        \Illuminate\Support\Facades\Http::assertSentCount(2);
    }

    public function test_tactical_import_preview_and_execute_preserve_client_scope(): void
    {
        config(['assets-api.provider_execution_enabled' => true]);
        $this->tech->givePermissionTo('integration.rmm_manage');
        $c1 = Client::factory()->create();
        $c2 = Client::factory()->create();
        $s1 = ClientSite::factory()->create(['client_id' => $c1->id]);
        $s2 = ClientSite::factory()->create(['client_id' => $c2->id]);
        $i = $this->integration('tactical_rmm');
        foreach ([[$c1, 'c1'], [$c2, 'c2'], [$s1, 's1'], [$s2, 's2']] as [$model, $external]) {
            $model->rmmLinks()->create(['integration_id' => $i->id, 'external_id' => $external]);
        }
        $agents = [['agent_id' => 'a1', 'client' => 'c1', 'site' => 's1', 'hostname' => 'Allowed', 'status' => 'online'], ['agent_id' => 'a2', 'client' => 'c2', 'site' => 's2', 'hostname' => 'Other customer', 'status' => 'online']];
        \Illuminate\Support\Facades\Http::fake(['https://assets-provider.test/agents/' => \Illuminate\Support\Facades\Http::response($agents)]);
        $body = ['client_id' => $c1->id, 'site_id' => $s1->id, 'provider' => 'tactical_rmm'];
        $preview = $this->postJson('/api/v1/assets/rmm/preview', $body + ['inspect_provider' => true])->assertOk()->assertJsonPath('data.inspection.candidates', 1)->json('data');
        $this->assertDatabaseCount('assets', 0);
        $this->withHeader('Idempotency-Key', 'tactical-import-once');
        $execute = $body + ['preview_revision' => $preview['preview_revision'], 'confirm' => true];
        $this->postJson('/api/v1/assets/rmm/sync', $execute)->assertOk()->assertJsonPath('state', 'completed')->assertJsonPath('result.created', 1);
        $this->postJson('/api/v1/assets/rmm/sync', $execute)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('assets', 1);
        $a = Asset::firstOrFail();
        $this->assertSame($c1->id, $a->client_id);
        $this->assertNotNull($a->work_context_id);
        \Illuminate\Support\Facades\Http::assertSentCount(2);
    }

    public function test_nable_import_uses_shared_mapping_and_preserves_another_customer(): void
    {
        config(['assets-api.provider_execution_enabled' => true]);
        $this->tech->givePermissionTo('integration.rmm_manage');
        $c = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $c->id]);
        $i = $this->integration('rmm');
        $c->rmmLinks()->create(['integration_id' => $i->id, 'external_id' => 'customer-1']);
        $site->rmmLinks()->create(['integration_id' => $i->id, 'external_id' => 'site-1']);
        $device = ['deviceid' => 'device-1', 'siteid' => 'site-1', 'name' => 'Nable server', 'type' => 'server', 'vendor' => 'Vendor', 'model' => 'Model', 'serial_number' => 'serial-1', 'mac_address' => '', 'ip_address' => '10.0.0.1', 'os' => 'Linux'];
        $mock = \Mockery::mock(\App\Services\Integrations\NAbleRmm\NAbleRmmClient::class);
        $mock->shouldReceive('listDevices')->with('customer-1', 'server')->twice()->andReturn([$device]);
        $mock->shouldReceive('listDevices')->with('customer-1', 'workstation')->twice()->andReturn([]);
        $this->app->bind(\App\Services\Integrations\NAbleRmm\NAbleRmmClient::class, fn () => $mock);
        $body = ['provider' => 'rmm', 'client_id' => $c->id, 'site_id' => $site->id];
        $plan = $this->postJson('/api/v1/assets/rmm/preview', $body + ['inspect_provider' => true])->assertOk()->assertJsonPath('data.inspection.candidates', 1)->json('data');
        $this->assertDatabaseCount('assets', 0);
        $this->withHeader('Idempotency-Key', 'nable-import-once')->postJson('/api/v1/assets/rmm/sync', $body + ['confirm' => true, 'preview_revision' => $plan['preview_revision']])->assertOk()->assertJsonPath('result.created', 1);
        $asset = Asset::firstOrFail();
        $this->assertSame($site->id, $asset->site_id);
        $this->assertNotNull($asset->work_context_id);
    }

    public function test_receipt_is_bound_to_the_original_actor_and_real_token(): void
    {
        // Exercise actual Sanctum authentication rather than its transient test token.
        $this->app['auth']->forgetGuards();
        $one = $this->tech->createToken('one', ['assets.create', 'assets.read']);
        $two = $this->tech->createToken('two', ['assets.create', 'assets.read']);
        $body = ['name' => 'Real-token asset'];
        $this->withToken($one->plainTextToken)->withHeader('Idempotency-Key', 'real-token-replay');
        $first = $this->postJson('/api/v1/assets', $body)->assertCreated()->json();
        $this->postJson('/api/v1/assets', $body)->assertCreated()->assertJsonPath('replayed', true);
        $this->app['auth']->forgetGuards();
        $this->withToken($two->plainTextToken)->getJson('/api/v1/assets/operations/'.$first['operation_id'])->assertNotFound();
        $one->accessToken->delete();
        $this->app['auth']->forgetGuards();
        $this->withToken($one->plainTextToken)->getJson('/api/v1/assets')->assertUnauthorized();
    }

    public function test_unknown_provider_completion_is_sanitized_and_never_reexecuted(): void
    {
        config(['assets-api.provider_execution_enabled' => true]);
        $mock = \Mockery::mock(\App\Modules\Asset\Actions\SyncAssetAlerts::class);
        $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('sensitive-provider-response'));
        $this->app->instance(\App\Modules\Asset\Actions\SyncAssetAlerts::class, $mock);
        $plan = $this->postJson('/api/v1/assets/alerts/sync/preview')->assertOk()->json('data');
        $body = ['confirm' => true, 'preview_revision' => $plan['preview_revision']];
        $this->withHeader('Idempotency-Key', 'provider-unknown-once');
        $first = $this->postJson('/api/v1/assets/alerts/sync', $body)->assertOk()->assertJsonPath('state', 'unknown')->assertJsonPath('result.scope.asset_id', null)->assertJsonPath('reconciliation_required', true);
        $this->assertStringNotContainsString('sensitive-provider-response', $first->getContent());
        $this->postJson('/api/v1/assets/alerts/sync', $body)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('state', 'unknown');
    }

    private function integration(string $type): \App\Models\System\Integrations\Integration
    {
        $i = \App\Models\System\Integrations\Integration::create(['name' => 'Asset test provider', 'type' => $type, 'status' => 'active', 'server' => 'https://assets-provider.test']);
        $i->setSecret('api_key', 'test-key-only');
        $i->save();

        return $i;
    }
}
