<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Nextcloud\Models\NextcloudApiCommand;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real bearer requests, persisted effects and static-schema validation for every success. */
class NextcloudApiParityTest extends TestCase
{
    use \App\Modules\Nextcloud\Tests\AssertsNextcloudApiSchema;
    use RefreshDatabase;

    private User $actor;

    private string $token;

    private static array $covered = [];

    private array $document;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Role::findOrCreate('Tech', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo(['nextcloud.connection_manage', 'nextcloud.sync', 'nextcloud.user_mapping_manage', 'nextcloud.folder_manage', 'nextcloud.talk_manage']);
        $this->token = $this->actor->createToken('Nextcloud tests', ['*'])->plainTextToken;
        $this->document = json_decode(file_get_contents(getenv('NEXTCLOUD_API_SWAGGER_PATH') ?: storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function tearDownAfterClass(): void
    {
        if ($path = getenv('NEXTCLOUD_API_COVERAGE_PATH')) {
            file_put_contents($path, json_encode(self::$covered, JSON_PRETTY_PRINT));
        }
        parent::tearDownAfterClass();
    }

    private function cloud(array $overrides = []): NextcloudConnection
    {
        return NextcloudConnection::factory()->create(array_replace(['scope' => 'global', 'mode' => 'read_only', 'base_url' => 'https://cloud.example.test',
            'service_username' => 'nexum', 'service_password' => 'DO-NOT-LEAK-service', 'is_active' => true, 'settings' => ['file_browser_enabled' => true, 'users_groups_read_enabled' => false, 'calendar_sync_enabled' => false]], $overrides));
    }

    private function base(NextcloudConnection $c): string
    {
        return '/api/v1/nextcloud/connections/'.$c->id;
    }

    private function api(string $method, string $uri, array $data = [], array $headers = [])
    {
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, $uri, $data, array_replace(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'], $headers));
        if ($response->status() < 300) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
            $op = $this->document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
            $this->assertIsArray($op, $method.' '.$uri.' lacks Swagger');
            $schema = $op['responses'][$response->status()]['content']['application/json']['schema'];
            $this->schema(json_decode($response->getContent()), $schema, 'response');
            $statuses = self::$covered[$op['operationId']]['statuses'] ?? [];
            $statuses[] = $response->status();
            self::$covered[$op['operationId']] = ['method' => $method, 'path' => '/'.$route->uri(), 'status' => $response->status(), 'statuses' => array_values(array_unique($statuses))];
        }

        return $response;
    }

    private function write(string $method, string $path, array $data = [], ?NextcloudConnection $c = null, array $extra = [])
    {
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        if ($c) {
            $headers['If-Match'] = $this->api('GET', $this->base($c))->assertOk()->headers->get('ETag');
        }

        return $this->api($method, $path, $data, array_replace($headers, $extra));
    }

    #[Test]
    public function connection_crud_filters_context_patch_and_encrypted_own_receipts(): void
    {
        $payload = ['name' => 'API Cloud', 'scope' => 'global', 'mode' => 'read_only', 'base_url' => 'https://cloud.example.test', 'sync_interval_minutes' => 15,
            'service_username' => 'nexum', 'service_password' => 'DO-NOT-LEAK-input', 'is_default' => true, 'is_active' => true];
        $key = (string) Str::uuid();
        $first = $this->write('POST', '/api/v1/nextcloud/connections', $payload, null, ['Idempotency-Key' => $key])->assertCreated();
        $c = NextcloudConnection::findOrFail($first->json('data.id'));
        $this->assertSame('DO-NOT-LEAK-input', $c->service_password);
        $this->assertStringNotContainsString('DO-NOT-LEAK', $first->getContent());
        $this->write('POST', '/api/v1/nextcloud/connections', $payload, null, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('replayed', true);
        $this->assertSame(1, NextcloudConnection::count());
        $this->write('POST', '/api/v1/nextcloud/connections', array_replace($payload, ['name' => 'Different']), null, ['Idempotency-Key' => $key])->assertConflict();
        $this->api('GET', '/api/v1/nextcloud/connections?scope=global&search=API&per_page=1')->assertOk()->assertJsonPath('total', 1);
        foreach (['clients', 'sites', 'contacts', 'users', 'roles', 'calendars'] as $kind) {
            $this->api('GET', '/api/v1/nextcloud/context?kind='.$kind)->assertOk();
            $this->api('GET', $this->base($c).'/context?kind='.$kind)->assertOk();
        }
        $this->write('PATCH', $this->base($c), ['name' => 'Changed'], $c)->assertOk()->assertJsonPath('data.name', 'Changed');
        $this->assertSame('DO-NOT-LEAK-input', $c->fresh()->service_password);
        $op = $first->json('operation_id');
        $this->api('GET', '/api/v1/nextcloud/operations/'.$op)->assertOk()->assertJsonPath('data.state', 'completed');
        $raw = DB::table('nextcloud_api_commands')->where('id', $op)->value('result');
        $this->assertStringNotContainsString('API Cloud', $raw);
        $this->assertStringNotContainsString('DO-NOT-LEAK', DB::table('activity_log')->get()->toJson());
        $this->write('DELETE', $this->base($c), [], $c)->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('nextcloud_connections', ['id' => $c->id]);
    }

    #[Test]
    public function every_mapping_type_has_exact_crud_readback_and_wrong_connection_denial(): void
    {
        $c = $this->cloud();
        $other = $this->cloud();
        $client = Client::factory()->create();
        $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $calendar = Calendar::create(['uuid' => (string) Str::uuid(), 'name' => 'Owner', 'slug' => 'owner-work', 'timezone' => 'Europe/Oslo', 'type' => 'personal', 'owner_type' => User::class, 'owner_id' => $owner->id, 'is_active' => true]);
        $payloads = ['users' => ['remote_user_id' => 'user', 'identity_type' => 'technician', 'user_id' => $owner->id],
            'groups' => ['remote_group_id' => 'staff', 'role_id' => $this->actor->roles->first()->id, 'sync_mode' => 'preview_only'],
            'calendars' => ['remote_calendar_id' => '/remote.php/dav/calendars/owner/work/', 'calendar_id' => $calendar->id, 'user_id' => $owner->id, 'sync_direction' => 'pull_only'],
            'folders' => ['client_id' => $client->id, 'purpose' => 'client_files', 'remote_path' => '/Clients/Acme']];
        foreach ($payloads as $kind => $payload) {
            $res = $this->write('POST', $this->base($c).'/'.$kind, $payload, $c)->assertOk();
            $id = $res->json('data.id');
            $this->api('GET', $this->base($c).'/'.$kind)->assertOk()->assertJsonPath('total', 1);
            $this->api('GET', $this->base($c).'/'.$kind.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
            $this->api('GET', $this->base($other).'/'.$kind.'/'.$id)->assertNotFound();
            $this->write('DELETE', $this->base($other).'/'.$kind.'/'.$id, [], $other)->assertNotFound();
            $this->write('DELETE', $this->base($c).'/'.$kind.'/'.$id, [], $c)->assertOk()->assertJsonPath('data.deleted', true);
        }
    }

    #[Test]
    public function scoped_contact_skip_map_import_and_group_roles_never_cross_clients(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $foreign = ClientSite::factory()->create();
        $person = ClientUser::factory()->create(['client_site_id' => $site->id]);
        $other = ClientUser::factory()->create(['client_site_id' => $foreign->id]);
        $c = $this->cloud(['scope' => 'client', 'client_id' => $client->id, 'client_site_id' => $site->id]);
        $this->write('POST', $this->base($c).'/users', ['remote_user_id' => 'person', 'mapping_action' => 'skip'], $c)->assertOk()->assertJsonPath('data.is_active', false);
        $this->write('POST', $this->base($c).'/users', ['remote_user_id' => 'person', 'mapping_action' => 'map_existing', 'client_user_id' => $other->id], $c)->assertNotFound();
        $this->write('POST', $this->base($c).'/users', ['remote_user_id' => 'person', 'mapping_action' => 'map_existing', 'client_user_id' => $person->id], $c)->assertOk()->assertJsonPath('data.client_user_id', $person->id);
        $payload = ['remote_user_id' => 'new@example.test', 'remote_email' => 'new@example.test', 'remote_username' => 'Imported Person', 'mapping_action' => 'import', 'client_role' => 'viewer'];
        $this->write('POST', $this->base($c).'/users', $payload, $c)->assertOk();
        $this->write('POST', $this->base($c).'/users', $payload, $c)->assertOk();
        $this->assertSame(1, ClientUser::where('email', 'new@example.test')->count());
        $imported = ClientUser::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame($site->id, $imported->client_site_id);
        $this->assertNotNull($imported->fresh()->contact_id);
        $this->write('POST', $this->base($c).'/groups', ['remote_group_id' => 'viewers', 'client_role' => 'viewer', 'role_id' => $this->actor->roles->first()->id, 'client_id' => $foreign->client_id, 'sync_mode' => 'nextcloud_to_nexum'], $c)
            ->assertOk()->assertJsonPath('data.client_id', $client->id)->assertJsonPath('data.role_id', null);
        $this->api('GET', $this->base($c).'/context?kind=contacts')->assertOk()->assertJsonPath('total', 2);
        $this->api('GET', $this->base($c).'/context?kind=users')->assertOk()->assertJsonPath('total', 0);
    }

    #[Test]
    public function permissions_scopes_active_actor_secrets_and_unsupported_inputs_fail_closed(): void
    {
        $c = $this->cloud();
        $this->token = $this->actor->createToken('Read', ['nextcloud.connections.read'])->plainTextToken;
        $this->api('GET', $this->base($c))->assertOk();
        $this->write('PATCH', $this->base($c), ['name' => 'No'], $c)->assertForbidden();
        $this->token = $this->actor->createToken('Config', ['nextcloud.connections.read', 'nextcloud.connections.write'])->plainTextToken;
        $this->write('PATCH', $this->base($c), ['service_password' => 'blocked'], $c)->assertForbidden();
        $this->write('PATCH', $this->base($c), ['settings' => ['automatic_calendar_sync_enabled' => true]], $c)->assertUnprocessable();
        $this->api('PATCH', $this->base($c), ['name' => 'No key'])->assertStatus(428);
        $this->api('PATCH', $this->base($c), ['name' => 'No revision'], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(428);
        $this->api('GET', '/api/v1/nextcloud/connections?per_page=101')->assertUnprocessable();
        $this->actor->revokePermissionTo('nextcloud.connection_manage');
        $this->api('GET', $this->base($c))->assertForbidden();
        $this->actor->givePermissionTo('nextcloud.connection_manage');
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->api('GET', $this->base($c))->assertForbidden();
    }

    #[Test]
    public function stale_writes_key_conflicts_audit_failures_and_unknown_provider_claims_do_not_repeat(): void
    {
        $c = $this->cloud();
        $etag = $this->api('GET', $this->base($c))->headers->get('ETag');
        $c->update(['name' => 'Concurrent']);
        $this->api('PATCH', $this->base($c), ['name' => 'Old'], ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $etag])->assertStatus(412);
        config(['activitylog.enabled' => false]);
        $this->write('PATCH', $this->base($c), ['name' => 'Unjournaled'], $c)->assertStatus(503);
        $this->assertSame('Concurrent', $c->fresh()->name);
        $this->assertSame(0, NextcloudApiCommand::count());
        config(['activitylog.enabled' => true]);
        $c->update(['talk_bot_id' => 1, 'talk_bot_secret' => 'bot-secret', 'talk_default_conversation_token' => 'target']);
        $payload = ['confirm' => true];
        $key = (string) Str::uuid();
        $headers = ['Idempotency-Key' => $key, 'If-Match' => $this->api('GET', $this->base($c))->headers->get('ETag')];
        $this->api('POST', $this->base($c).'/talk-bot/test', $payload, $headers)->assertConflict();
        config(['nextcloud_api.provider_execution_enabled' => true]);
        Http::fake(fn () => throw new \RuntimeException('provider leaked secret MUST-NOT-LEAK'));
        $first = $this->api('POST', $this->base($c).'/talk-bot/test', $payload, $headers)->assertStatus(202)->assertJsonPath('state', 'unknown');
        $this->assertStringNotContainsString('MUST-NOT-LEAK', $first->getContent());
        $this->api('POST', $this->base($c).'/talk-bot/test', $payload, $headers)->assertStatus(202)->assertJsonPath('replayed', true);
        $this->api('GET', '/api/v1/nextcloud/operations/'.$first->json('operation_id'))->assertOk()->assertJsonPath('data.reconciliation_required', true);
        $this->assertSame(1, NextcloudApiCommand::count());
    }

    #[Test]
    public function talk_patch_preserves_secret_and_real_ocs_ack_is_not_delivery(): void
    {
        $c = $this->cloud(['talk_bot_id' => 3, 'talk_bot_secret' => 'DO-NOT-LEAK-bot', 'talk_default_conversation_token' => 'room']);
        $this->api('GET', $this->base($c).'/talk-bot')->assertOk()->assertJsonPath('data.talk_bot_secret_configured', true);
        $this->write('PATCH', $this->base($c).'/talk-bot', ['talk_bot_features' => ['reaction']], $c)->assertOk()->assertJsonPath('data.talk_bot_id', 3);
        $this->assertSame('DO-NOT-LEAK-bot', $c->fresh()->talk_bot_secret);
        config(['nextcloud_api.provider_execution_enabled' => true]);
        Http::fake(['*' => Http::response(['ocs' => ['meta' => ['statuscode' => 201], 'data' => ['id' => 45]]], 201)]);
        $res = $this->write('POST', $this->base($c).'/talk-bot/test', ['confirm' => true], $c)->assertOk()->assertJsonPath('data.message_id', 45)->assertJsonPath('data.delivery_verified', false);
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('DO-NOT-LEAK', $res->getContent());
    }

    #[Test]
    public function folder_metadata_selection_reviewed_matching_and_stale_proposals(): void
    {
        $c = $this->cloud(['root_folder' => '/Clients']);
        $client = Client::factory()->create(['name' => 'Acme AS']);
        $this->mock(NextcloudReadClient::class, function ($mock) {
            $mock->shouldReceive('files')->andReturn([['path' => '/Clients/Acme', 'name' => 'Acme', 'type' => 'folder', 'size' => null, 'content_type' => null, 'last_modified' => null]]);
        });
        $this->api('GET', $this->base($c).'/files?path=/Clients')->assertOk()->assertJsonPath('data.0.name', 'Acme');
        $this->api('GET', $this->base($c).'/files?path=/Clients/../private')->assertUnprocessable();
        $this->write('PATCH', $this->base($c).'/folders', ['folder_type' => 'documents', 'remote_path' => '/Reports'], $c)->assertOk()->assertJsonPath('data.documents_folder', '/Reports');
        $preview = $this->api('POST', $this->base($c).'/folders/auto-match/preview')->assertOk();
        $this->assertSame(0, $c->folderMappings()->count());
        $this->write('POST', $this->base($c).'/folders/auto-match/apply', ['preview_token' => $preview->json('preview_token')], $c)->assertOk()->assertJsonPath('data.matched', 1);
        $this->assertSame($client->id, (int) $c->folderMappings()->firstOrFail()->mappable_id);
        $this->write('POST', $this->base($c).'/folders/auto-match/apply', ['preview_token' => $preview->json('preview_token')], $c)->assertConflict();
    }

    #[Test]
    public function full_sync_preview_execution_attempts_and_health_are_distinct_from_calendar_only(): void
    {
        $c = $this->cloud(['settings' => ['file_browser_enabled' => false, 'users_groups_read_enabled' => false, 'calendar_sync_enabled' => false]]);
        Http::fake(['*' => Http::response(['ocs' => ['meta' => ['statuscode' => 100], 'data' => ['capabilities' => ['core' => []]]]], 200)]);
        $this->write('POST', $this->base($c).'/health', [], $c)->assertOk()->assertJsonPath('data.success', true);
        $preview = $this->api('POST', $this->base($c).'/sync/preview')->assertOk()->assertJsonPath('data.synchronizes_calendars', false);
        $this->assertSame(0, NextcloudSyncLog::count());
        config(['nextcloud_api.provider_execution_enabled' => true]);
        $run = $this->write('POST', $this->base($c).'/sync', ['confirm' => true, 'preview_token' => $preview->json('preview_token')], $c)->assertOk()->assertJsonPath('data.operation', 'api_manual_sync');
        $this->api('GET', $this->base($c).'/attempts')->assertOk()->assertJsonPath('total', 1);
        $this->api('GET', $this->base($c).'/attempts/'.$run->json('data.id'))->assertOk();
        $other = $this->cloud();
        $this->api('GET', $this->base($other).'/attempts/'.$run->json('data.id'))->assertNotFound();
    }

    #[Test]
    public function exact_deletion_replay_survives_missing_binding_and_rechecks_original_rights(): void
    {
        $c = $this->cloud();
        $key = (string) Str::uuid();
        $uri = $this->base($c);
        $headers = ['Idempotency-Key' => $key, 'If-Match' => $this->api('GET', $uri)->headers->get('ETag')];
        $first = $this->api('DELETE', $uri, [], $headers)->assertOk();
        $this->api('DELETE', $uri, [], $headers)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('operation_id', $first->json('operation_id'));
        $this->actor->revokePermissionTo('nextcloud.connection_manage');
        $this->api('DELETE', $uri, [], $headers)->assertForbidden();
        $this->assertSame(1, NextcloudApiCommand::count());
    }

    #[Test]
    public function identity_import_requires_site_and_connection_site_must_match_client(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create();
        $c = $this->cloud(['scope' => 'client', 'client_id' => $client->id, 'client_site_id' => null]);
        $this->write('POST', $this->base($c).'/users', ['remote_user_id' => 'new@example.test', 'mapping_action' => 'import'], $c)->assertUnprocessable();
        $this->write('PATCH', $this->base($c), ['client_site_id' => $site->id], $c)->assertUnprocessable();
        $this->assertSame(0, NextcloudApiCommand::count());
        $this->assertSame(0, ClientUser::count());
    }

    #[Test]
    public function secret_rotation_requires_cumulative_grant_and_invalidates_automatic_consent(): void
    {
        $c = $this->cloud(['settings' => ['automatic_calendar_sync_enabled' => true]]);
        $res = $this->write('PATCH', $this->base($c), ['service_password' => 'ROTATED-SECRET'], $c)->assertOk()->assertJsonPath('data.settings.automatic_calendar_sync_enabled', false);
        $this->assertSame('ROTATED-SECRET', $c->fresh()->service_password);
        $this->assertStringNotContainsString('ROTATED-SECRET', $res->getContent());
        $this->assertStringNotContainsString('ROTATED-SECRET', DB::table('nextcloud_api_commands')->get()->toJson());
        $op = $res->json('operation_id');
        $token = $this->actor->tokens()->latest('id')->first();
        $token->update(['abilities' => ['nextcloud.operations.read', 'nextcloud.connections.write']]);
        $this->api('GET', '/api/v1/nextcloud/operations/'.$op)->assertForbidden();
    }

    #[Test]
    public function full_sync_of_client_group_imports_one_canonical_contact_and_replays_without_provider_calls(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $c = $this->cloud(['scope' => 'client', 'client_id' => $client->id, 'client_site_id' => $site->id, 'settings' => ['users_groups_read_enabled' => true, 'file_browser_enabled' => false, 'calendar_sync_enabled' => false]]);
        $c->groupMappings()->create(['remote_group_id' => 'viewers', 'client_id' => $client->id, 'client_role' => 'viewer', 'sync_mode' => 'nextcloud_to_nexum', 'is_active' => true]);
        Http::fake([
            '*/cloud/capabilities*' => Http::response(['ocs' => ['data' => ['capabilities' => ['core' => []]]]], 200),
            '*/cloud/users*' => Http::response(['ocs' => ['data' => ['users' => ['person@example.test']]]], 200),
            '*/cloud/groups/viewers/users*' => Http::response(['ocs' => ['data' => ['users' => ['person@example.test']]]], 200),
            '*/cloud/groups*' => Http::response(['ocs' => ['data' => ['groups' => ['viewers']]]], 200),
        ]);
        $preview = $this->api('POST', $this->base($c).'/sync/preview')->assertOk()->assertJsonPath('data.imports_client_contacts', true);
        config(['nextcloud_api.provider_execution_enabled' => true]);
        $headers = ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $this->api('GET', $this->base($c))->headers->get('ETag')];
        $payload = ['confirm' => true, 'preview_token' => $preview->json('preview_token')];
        $this->api('POST', $this->base($c).'/sync', $payload, $headers)->assertOk()->assertJsonPath('data.summary.client_contacts_imported', 1);
        $this->api('POST', $this->base($c).'/sync', $payload, $headers)->assertOk()->assertJsonPath('replayed', true);
        Http::assertSentCount(4);
        $bridge = ClientUser::where('email', 'person@example.test')->firstOrFail();
        $this->assertNotNull($bridge->contact_id);
        $this->assertSame(1, $c->userMappings()->count());
    }

    #[Test]
    public function ocs_failure_with_http_success_is_never_reported_as_a_sent_talk_message(): void
    {
        $c = $this->cloud(['talk_bot_id' => 1, 'talk_bot_secret' => 'secret', 'talk_default_conversation_token' => 'room']);
        config(['nextcloud_api.provider_execution_enabled' => true]);
        Http::fake(['*' => Http::response(['ocs' => ['meta' => ['statuscode' => 403, 'message' => 'PRIVATE-REMOTE-ERROR'], 'data' => []]], 200)]);
        $response = $this->write('POST', $this->base($c).'/talk-bot/test', ['confirm' => true], $c)->assertStatus(202)->assertJsonPath('reconciliation_required', true);
        $this->assertStringNotContainsString('PRIVATE-REMOTE-ERROR', $response->getContent());
    }

    #[Test]
    public function unexpected_failure_rolls_back_receipt_and_audit_without_exposing_secret_or_debug_trace(): void
    {
        config(['app.debug' => true]);
        $this->mock(\App\Modules\Nextcloud\Actions\StoreNextcloudConnection::class, function ($mock) {
            $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('PRIVATE-PASSWORD-PROBE'));
        });
        $res = $this->write('POST', '/api/v1/nextcloud/connections', ['name' => 'Fault probe', 'scope' => 'global', 'mode' => 'read_only', 'base_url' => 'https://cloud.example.test', 'sync_interval_minutes' => 15]);
        $res->assertStatus(500)->assertExactJson(['message' => 'Nextcloud API operation failed.']);
        $this->assertStringNotContainsString('PRIVATE-PASSWORD-PROBE', $res->getContent());
        $this->assertSame(0, NextcloudApiCommand::count());
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'nextcloud_api')->count());
    }

    #[Test]
    public function actor_revoked_while_waiting_for_the_mutex_cannot_commit_a_local_change(): void
    {
        $c = $this->cloud();
        $revoked = false;
        DB::listen(function ($query) use (&$revoked) {
            if (! $revoked && str_contains($query->sql, 'nextcloud_api_mutex')) {
                $revoked = true;
                DB::table($this->actor->getTable())->where('id', $this->actor->id)->update(['status' => User::STATUS_DISABLED]);
            }
        });
        $this->write('PATCH', $this->base($c), ['name' => 'Must never commit'], $c)->assertForbidden();
        $this->assertSame(0, NextcloudApiCommand::count());
        $this->assertNotSame('Must never commit', $c->fresh()->name);
    }
}
