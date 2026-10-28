<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudTalkDelivery;
use App\Modules\Nextcloud\Models\NextcloudTalkDestination;
use App\Modules\Nextcloud\Services\TalkDestinationRegistry;
use App\Modules\Notification\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Native browser/API paths share persisted selection, fresh rights and bounded provider evidence. */
class TalkNotificationSurfaceTest extends TestCase
{
    use \App\Modules\Nextcloud\Tests\AssertsNextcloudApiSchema;
    use RefreshDatabase;

    private User $actor;

    private string $bearer;

    private array $document;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Role::findOrCreate('Tech', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo(['nextcloud.connection_manage', 'nextcloud.talk_manage', 'notification.manage_channels']);
        $this->bearer = $this->actor->createToken('Talk fixtures', ['*'])->plainTextToken;
        $this->document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function cloud(): NextcloudConnection
    {
        return NextcloudConnection::factory()->create(['name' => 'Talk <escaped>', 'scope' => 'global', 'is_active' => true,
            'mode' => 'managed', 'base_url' => 'https://cloud.example.test', 'settings' => ['calendar_sync_enabled' => false]]);
    }

    private function api(string $method, string $path, array $body = [], array $headers = [])
    {
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, $path, $body, $headers + ['Authorization' => 'Bearer '.$this->bearer]);
        if ($response->status() < 300) {
            $route = Route::getRoutes()->match(Request::create($path, $method));
            $operation = $this->document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
            $this->assertIsArray($operation, 'New Talk route requires typed Swagger.');
            $this->schema(json_decode($response->getContent()), $operation['responses'][$response->status()]['content']['application/json']['schema'], 'response');
        }

        return $response;
    }

    private function room(string $token = 'room123'): array
    {
        return ['token' => $token, 'displayName' => 'Ops <safe>', 'name' => 'Ops', 'type' => 2,
            'participantType' => 3, 'readOnly' => 0, 'permissions' => 128];
    }

    private function provider(): void
    {
        Http::fake(function ($request) {
            $data = match (true) {
                str_contains($request->url(), '/api/v4/room/') => $this->room(),
                str_contains($request->url(), '/api/v4/room') && $request->method() === 'GET' => [$this->room()],
                str_contains($request->url(), '/api/v4/room') => $this->room(),
                $request->method() === 'POST' => ['id' => 73, 'token' => 'room123', 'referenceId' => $request['referenceId']],
                default => [['id' => 73, 'token' => 'room123']],
            };

            return Http::response(['ocs' => ['meta' => ['statuscode' => 100], 'data' => $data]], $request->method() === 'POST' ? 201 : 200);
        });
    }

    #[Test]
    public function discovery_selection_global_default_and_exact_test_use_real_scoped_api_and_schemas(): void
    {
        $c = $this->cloud();
        $this->provider();
        $base = '/api/v1/nextcloud/connections/'.$c->id.'/talk-notifications';
        $context = $this->api('GET', $base)->assertOk();
        $headers = ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $context->headers->get('ETag')];
        $body = ['revision' => $context->json('data.revision')];
        $refreshed = $this->api('POST', $base.'/refresh', $body, $headers)->assertOk();
        $this->api('POST', $base.'/refresh', $body, $headers)->assertOk()->assertJsonPath('replayed', true);
        Http::assertSentCount(1);
        $id = $refreshed->json('data.destinations.0.id');
        $configured = $this->api('PATCH', $base, ['revision' => $refreshed->json('data.revision'),
            'enabled' => true, 'destination_ids' => [$id], 'default_destination_id' => $id],
            ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $refreshed->headers->get('ETag')])->assertOk();
        $channel = NotificationChannel::firstOrCreate(['driver' => 'nextcloud_talk'], ['name' => 'nextcloud_talk', 'label' => 'Talk']);
        $global = '/api/v1/notifications/channels/'.$channel->id;
        $read = $this->api('GET', $global)->assertOk();
        $this->api('PUT', $global, ['is_enabled' => true, 'config' => ['talk_destination_id' => $id]],
            ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $read->json('data.etag')])->assertOk();
        config(['nextcloud_api.provider_execution_enabled' => true]);
        $body = ['revision' => $configured->json('data.revision'), 'confirm' => true];
        $headers = ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $configured->headers->get('ETag')];
        $test = $this->api('POST', $base.'/destinations/'.$id.'/test', $body, $headers)->assertOk();
        $test->assertJsonPath('data.delivery_confirmed', true)->assertJsonPath('data.message_id', 73);
        $this->api('POST', $base.'/destinations/'.$id.'/test', $body, $headers)->assertOk()->assertJsonPath('replayed', true);
        Http::assertSentCount(4);
        $this->assertSame('confirmed', NextcloudTalkDestination::findOrFail($id)->last_test_state);
        $this->assertSame($c->id, $channel->fresh()->config['nextcloud_connection_id']);
    }

    #[Test]
    public function optional_private_creation_is_disabled_by_default_and_cannot_duplicate_on_retry(): void
    {
        $c = $this->cloud();
        $this->provider();
        $base = '/api/v1/nextcloud/connections/'.$c->id.'/talk-notifications';
        $read = $this->api('GET', $base)->assertOk();
        $body = ['revision' => $read->json('data.revision'), 'name' => 'Ops', 'confirm' => true];
        $headers = ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $read->headers->get('ETag')];
        $this->api('POST', $base.'/conversations', $body, $headers)->assertStatus(409);
        Http::assertNothingSent();
        config(['nextcloud_api.provider_execution_enabled' => true]);
        $created = $this->api('POST', $base.'/conversations', $body, $headers)->assertOk();
        $created->assertJsonPath('data.state', 'confirmed')->assertJsonPath('data.destination.enabled', false);
        $this->api('POST', $base.'/conversations', $body, $headers)->assertOk()->assertJsonPath('replayed', true);
        Http::assertSentCount(2);
        $this->assertCount(1, NextcloudTalkDelivery::where('kind', 'create_room')->get());
        $this->assertFalse(app(TalkDestinationRegistry::class)->enabled($c->fresh()));
    }

    #[Test]
    public function missing_native_permission_and_cross_connection_destination_cannot_send(): void
    {
        $a = $this->cloud();
        $b = $this->cloud();
        $dest = NextcloudTalkDestination::create(['connection_id' => $b->id, 'conversation_token' => 'other123', 'name' => 'Other']);
        $base = '/api/v1/nextcloud/connections/'.$a->id.'/talk-notifications';
        $read = $this->api('GET', $base)->assertOk();
        config(['nextcloud_api.provider_execution_enabled' => true]);
        $this->api('POST', $base.'/destinations/'.$dest->id.'/test', ['revision' => $read->json('data.revision'), 'confirm' => true],
            ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $read->headers->get('ETag')])->assertNotFound();
        $this->actor->revokePermissionTo('nextcloud.talk_manage');
        $this->api('GET', $base)->assertForbidden();
        Http::assertNothingSent();
    }

    #[Test]
    public function ordinary_panels_render_escaped_names_without_secret_urls_or_nested_forms(): void
    {
        $c = $this->cloud();
        $this->actingAs($this->actor);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = view('nextcloud::Admin.connections.partials.talk-notifications', ['connection' => $c])->render();
        $this->assertStringContainsString('Talk Notifications', $html);
        $this->assertStringNotContainsString('service_password', $html);
        $this->assertSame(substr_count($html, '<form '), substr_count($html, '</form>'));
        $channel = NotificationChannel::firstOrCreate(['driver' => 'nextcloud_talk'], ['name' => 'nextcloud_talk', 'label' => 'Talk']);
        $html = view('notification::Admin.channels.partials.talk-destinations', ['channel' => $channel,
            'talkConnections' => app(TalkDestinationRegistry::class)->globalChoices()])->render();
        $this->assertStringContainsString('Talk &lt;escaped&gt;', $html);
        $this->assertStringNotContainsString('name="config[default_webhook_url]"', $html);
        $this->get(route('tech.admin.notification-channels.edit', $channel))->assertOk()->assertSee('Talk notification destinations');
    }

    #[Test]
    public function revoking_test_scope_during_provider_read_stops_the_message_post(): void
    {
        $c = $this->cloud();
        $registry = app(TalkDestinationRegistry::class);
        $registry->saveSettings($c, ['enabled' => true]);
        $destination = NextcloudTalkDestination::create(['connection_id' => $c->id,
            'provider_binding' => $registry->binding($c->fresh()), 'conversation_token' => 'room123', 'name' => 'Approved',
            'is_enabled' => true, 'is_available' => true, 'health_status' => 'available']);
        NotificationChannel::where('driver', 'nextcloud_talk')->update(['is_enabled' => true]);
        $base = '/api/v1/nextcloud/connections/'.$c->id.'/talk-notifications';
        $read = $this->api('GET', $base)->assertOk();
        config(['nextcloud_api.provider_execution_enabled' => true]);
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method(), 'Revoked scope must stop before POST.');
            $this->actor->tokens()->update(['abilities' => json_encode(['nextcloud.talk.read'])]);

            return Http::response(['ocs' => ['meta' => ['statuscode' => 100], 'data' => $this->room()]]);
        });
        $this->api('POST', $base.'/destinations/'.$destination->id.'/test',
            ['revision' => $read->json('data.revision'), 'confirm' => true],
            ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $read->headers->get('ETag')])->assertForbidden();
        Http::assertSentCount(1);
        $this->assertSame('blocked', NextcloudTalkDelivery::firstOrFail()->state);
    }
}
