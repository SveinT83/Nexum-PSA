<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Notification\Livewire\NotificationBell;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSmsTemplate;
use App\Modules\Notification\Notifications\CustomerPortalNotification;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real bearer requests verify persisted effects, every success contract, and fresh denial. */
class NotificationApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private string $token;

    private array $manifest;

    private array $schemas;

    private array $observed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::findOrCreate('Tech', 'web');
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo('notification.manage_channels');
        $this->token = $this->actor->createToken('Notification parity', ['*'])->plainTextToken;
        $this->bearer($this->token);
        config(['webpush.enabled' => true, 'webpush.vapid.public_key' => 'public-test-vapid',
            'webpush.vapid.private_key' => 'private-vapid-CANARY', 'webpush.vapid.subject' => 'mailto:test@example.test',
            'notification-api.external_tests_enabled' => true]);
        $this->manifest = collect(json_decode(file_get_contents(app_path('Modules/Notification/Support/api-manifest.json')), true))
            ->keyBy('name')->all();
        $this->schemas = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true)['components']['schemas'];
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function bearer(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token);
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function note(?User $user = null, string $type = 'test.notification', array $data = []): string
    {
        $user ??= $this->actor;
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'type' => $type, 'data' => json_encode($data + ['title' => 'Notification', 'url' => '/tech']),
            'read_at' => null, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function payload(string $suffix = 'one'): array
    {
        return ['endpoint' => 'https://push.example.test/SECRET-endpoint-'.$suffix,
            'keys' => ['p256dh' => str_repeat('A', 40), 'auth' => str_repeat('B', 24)]];
    }

    private function callApi(string $name, array $parameters = [], array $body = [], array $headers = [], int $status = 200)
    {
        $route = $this->manifest['notifications.'.$name];
        $url = '/api/v1/'.$route['path'];
        foreach ($parameters as $key => $value) {
            $url = str_replace('{'.$key.'}', $value, $url);
        }
        $response = $this->json(strtoupper($route['method']), $url, $body, $headers);
        $response->assertStatus($status);
        $this->validateSchema($response->json(), $this->schemas[$route['response_schema']]);
        $this->observed[] = $name;
        $response->assertHeader('Cache-Control', 'no-store, private');

        return $response;
    }

    private function validateSchema(mixed $data, array $schema): void
    {
        if ($data === null && ($schema['nullable'] ?? false)) {
            $this->assertNull($data);

            return;
        }
        if (isset($schema['$ref'])) {
            $this->validateSchema($data, $this->schemas[basename($schema['$ref'])]);

            return;
        }
        switch ($schema['type'] ?? null) {
            case 'object':
                $this->assertIsArray($data);
                foreach ($schema['required'] ?? [] as $key) {
                    $this->assertArrayHasKey($key, $data);
                }
                foreach ($schema['properties'] ?? [] as $key => $child) {
                    if (array_key_exists($key, $data)) {
                        $this->validateSchema($data[$key], $child);
                    }
                }
                break;
            case 'array':
                $this->assertIsArray($data);
                foreach ($data as $value) {
                    $this->validateSchema($value, $schema['items']);
                }
                break;
            case 'string': $this->assertIsString($data);
                break;
            case 'integer': $this->assertIsInt($data);
                break;
            case 'boolean': $this->assertIsBool($data);
                break;
        }
        if (isset($schema['enum'])) {
            $this->assertContains($data, $schema['enum']);
        }
    }

    private function portal(): User
    {
        $client = Client::factory()->create(['active' => true]);
        $contact = Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'Portal contact']);
        $user = User::factory()->create(['contact_id' => $contact->id, 'status' => User::STATUS_ACTIVE]);
        $account = CustomerPortalAccount::create(['user_id' => $user->id, 'contact_id' => $contact->id, 'status' => 'active']);
        CustomerPortalMembership::create(['customer_portal_account_id' => $account->id, 'client_id' => $client->id,
            'role' => CustomerPortalMembership::ROLE_VIEWER, 'status' => CustomerPortalMembership::STATUS_ACTIVE]);

        return $user;
    }

    #[Test]
    public function every_notification_http_operation_has_typed_success_and_exact_persisted_effects(): void
    {
        $id = $this->note();
        $this->callApi('index')->assertJsonPath('meta.total', 1);
        $this->callApi('show', ['notification' => $id])->assertJsonPath('data.id', $id);
        $this->callApi('summary')->assertJsonPath('data.unread_count', 1);
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->callApi('read', ['notification' => $id]);
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->callApi('open', ['notification' => $id])->assertJsonPath('data.acknowledged', true);
        $this->note();
        $this->callApi('read-all')->assertJsonPath('data.updated', 1);
        $this->callApi('context');
        $this->callApi('preferences.show');
        $this->callApi('preferences.update', [], ['settings' => [['notification_type' => 'ticket_assigned', 'database_enabled' => true]]]);
        $this->assertDatabaseHas('notification_settings', ['user_id' => $this->actor->id, 'notification_type' => 'ticket_assigned', 'database_enabled' => true]);
        $registered = $this->callApi('devices.store', [], $this->payload(), [], 201);
        $device = $registered->json('device.id');
        $this->callApi('devices.store', [], $this->payload());
        $this->callApi('devices.index')->assertJsonCount(1, 'devices');
        $this->callApi('devices.current', [], ['endpoint' => $this->payload()['endpoint']]);
        $test = $this->callApi('devices.test', [], ['endpoint' => $this->payload()['endpoint']], $this->key(), 202);
        $this->callApi('operations.show', ['operation' => $test->json('operation_id')]);
        $this->callApi('devices.destroy', ['subscription' => $device])->assertJsonPath('removed', true);
        $this->assertDatabaseCount('push_subscriptions', 0);
        $device = $this->callApi('devices.store', [], $this->payload('admin'), [], 201)->json('device.id');
        $this->callApi('admin.devices.index')->assertJsonPath('meta.total', 1);
        $this->callApi('admin.devices.destroy', ['subscription' => $device], [], $this->key());
        $channel = NotificationChannel::updateOrCreate(['name' => 'sms'], ['label' => 'SMS', 'driver' => 'sms', 'is_enabled' => true,
            'config' => ['provider' => 'dry_run', 'sender_name' => 'Nexum', 'default_country_code' => '+47']]);
        $this->callApi('channels.index');
        $read = $this->callApi('channels.show', ['channel' => $channel->id]);
        $this->callApi('channels.context', ['channel' => $channel->id]);
        $this->callApi('channels.update', ['channel' => $channel->id],
            ['is_enabled' => true, 'config' => $channel->config], $this->key() + ['If-Match' => $read->headers->get('ETag')]);
        $contact = Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'SMS contact']);
        $contact->phones()->create(['label' => 'mobile', 'phone' => '99 88 77 66', 'is_primary' => true, 'sms_allowed' => true]);
        NotificationSmsTemplate::create(['key' => 'api_test', 'name' => 'API test', 'body' => 'Test', 'is_active' => true, 'variables' => []]);
        $this->callApi('channels.test', ['channel' => $channel->id], ['test_contact_id' => $contact->id, 'test_template_key' => 'api_test'], $this->key())
            ->assertJsonPath('data.state', 'dry_run');
        $this->assertDatabaseCount('notification_sms_messages', 1);
        $portal = $this->portal();
        $this->bearer($portal->createToken('Portal parity', ['*'])->plainTextToken);
        $pid = $this->note($portal, CustomerPortalNotification::class, ['type' => 'portal_ticket_reply', 'url' => '/portal']);
        $this->note($portal);
        $this->callApi('portal.index')->assertJsonPath('meta.total', 1);
        $this->callApi('portal.show', ['notification' => $pid]);
        $this->callApi('portal.summary')->assertJsonPath('data.unread_count', 1);
        $this->callApi('portal.preferences.show')->assertJsonPath('data.web_push.ready', false)
            ->assertJsonPath('data.web_push.public_key', null)->assertJsonPath('data.talk_enabled', false);
        $this->callApi('portal.preferences.update', [], ['settings' => [['notification_type' => 'portal_ticket_reply', 'database_enabled' => true]]]);
        $this->callApi('portal.read', ['notification' => $pid]);
        $this->callApi('portal.open', ['notification' => $pid]);
        $this->callApi('portal.read-all')->assertJsonPath('data.updated', 0);
        $this->assertSame(1, $portal->unreadNotifications()->count());
        $this->assertEqualsCanonicalizing(array_map(fn ($x) => substr($x, strlen('notifications.')), array_keys($this->manifest)), array_unique($this->observed));
    }

    #[Test]
    public function foreign_records_read_only_tokens_inactive_users_and_admin_revocation_fail_closed(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $foreign = $this->note($other);
        $this->getJson('/api/v1/notifications/'.$foreign)->assertNotFound();
        $id = $this->note();
        $this->bearer($this->actor->createToken('Read only', ['notifications.read'])->plainTextToken);
        $this->postJson('/api/v1/notifications/'.$id.'/read')->assertForbidden();
        $this->getJson('/api/v1/notifications/channels')->assertForbidden();
        $this->bearer($this->token);
        $this->actor->revokePermissionTo('notification.manage_channels');
        $this->getJson('/api/v1/notifications/channels')->assertForbidden();
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->getJson('/api/v1/notifications')->assertForbidden();
    }

    #[Test]
    public function preferences_are_atomic_and_generic_tokens_cannot_operate_workday(): void
    {
        $input = ['settings' => [
            ['notification_type' => 'ticket_assigned', 'mail_enabled' => false],
            ['notification_type' => 'unknown_type', 'database_enabled' => true],
        ]];
        $this->putJson('/api/v1/notifications/preferences', $input)->assertUnprocessable();
        $this->assertDatabaseCount('notification_settings', 0);
        $this->putJson('/api/v1/notifications/preferences', ['settings' => [['notification_type' => 'workday_reminder', 'database_enabled' => true]]])->assertForbidden();
        $this->getJson('/api/v1/notifications?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/v1/notifications?owner_id=1')->assertUnprocessable();
    }

    #[Test]
    public function device_transport_and_receipts_are_private_and_tests_cannot_send_twice(): void
    {
        $device = $this->callApi('devices.store', [], $this->payload(), [], 201);
        $this->assertStringNotContainsString('SECRET-endpoint', $device->getContent());
        $key = $this->key();
        $body = ['endpoint' => $this->payload()['endpoint']];
        $first = $this->postJson('/api/v1/notifications/web-push/test', $body, $key)->assertAccepted();
        $this->bearer($this->token);
        $this->postJson('/api/v1/notifications/web-push/test', $body, $key)->assertAccepted()->assertJsonPath('replayed', true);
        Queue::assertPushed(\App\Modules\Notification\Jobs\SendWebPushDeviceTest::class, 1);
        $raw = DB::table('notification_api_commands')->first();
        $this->assertStringNotContainsString('SECRET-endpoint', json_encode($raw));
        $this->assertStringNotContainsString('queued', $raw->result);
        $this->bearer($this->token);
        $this->postJson('/api/v1/notifications/web-push/test', ['endpoint' => 'https://push.example.test/other'], $key)->assertConflict();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('Tech');
        $this->bearer($other->createToken('Other', ['*'])->plainTextToken);
        $this->getJson('/api/v1/notifications/operations/'.$first->json('operation_id'))->assertNotFound();
        $this->postJson('/api/v1/notifications/web-push/devices', $this->payload())->assertConflict();
        $this->postJson('/api/v1/notifications/web-push/devices/current', $body)->assertOk()->assertJsonPath('device', null);
    }

    #[Test]
    public function channel_overwrite_requires_current_state_and_replay_cannot_leak_revoked_authority(): void
    {
        $c = NotificationChannel::create(['name' => 'test', 'label' => 'Test', 'driver' => 'other', 'is_enabled' => false]);
        $read = $this->getJson('/api/v1/notifications/channels/'.$c->id)->assertOk();
        $key = $this->key();
        $body = ['is_enabled' => true, 'secrets' => ['api_token' => 'SECRET-driver-CANARY']];
        $this->putJson('/api/v1/notifications/channels/'.$c->id, $body, $key)->assertStatus(428);
        $key = $this->key() + ['If-Match' => $read->headers->get('ETag')];
        $first = $this->putJson('/api/v1/notifications/channels/'.$c->id, $body, $key)->assertOk();
        $this->assertStringNotContainsString('SECRET-driver', $first->getContent());
        $this->assertSame('SECRET-driver-CANARY', $c->fresh()->getSecret('api_token'));
        $this->putJson('/api/v1/notifications/channels/'.$c->id, $body, $key)->assertOk()->assertJsonPath('replayed', true);
        $this->putJson('/api/v1/notifications/channels/'.$c->id, $body, $this->key() + ['If-Match' => $read->headers->get('ETag')])->assertStatus(412);
        $this->actor->revokePermissionTo('notification.manage_channels');
        $this->bearer($this->token);
        $this->getJson('/api/v1/notifications/operations/'.$first->json('operation_id'))->assertForbidden();
    }

    #[Test]
    public function portal_context_and_audience_never_become_internal_notification_access(): void
    {
        $portal = $this->portal();
        $portalToken = $portal->createToken('Portal only', ['notifications.portal.read', 'notifications.portal.update', 'notifications.portal.preferences.write'])->plainTextToken;
        $this->bearer($portalToken);
        $internal = $this->note($portal);
        $this->getJson('/api/v1/notifications/portal/'.$internal)->assertNotFound();
        $this->postJson('/api/v1/notifications/portal/'.$internal.'/read')->assertNotFound();
        $this->putJson('/api/v1/notifications/portal/preferences', ['settings' => [['notification_type' => 'ticket_assigned', 'database_enabled' => true]]])->assertUnprocessable();
        $this->getJson('/api/v1/notifications/portal?membership_id=999999')->assertForbidden();
        $this->getJson('/api/v1/notifications/preferences')->assertForbidden();
        CustomerPortalMembership::query()->update(['status' => 'disabled']);
        $this->bearer($portalToken);
        $this->getJson('/api/v1/notifications/portal')->assertForbidden();
    }

    #[Test]
    public function bell_total_is_not_limited_by_dropdown_and_inbound_open_preserves_source_read(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $this->note();
        }
        $this->actingAs($this->actor);
        Livewire::test(NotificationBell::class)->assertSet('unreadCount', 14);
        $id = $this->note($this->actor, InboundEmailRoutedNotification::class);
        $this->bearer($this->token);
        $this->postJson('/api/v1/notifications/'.$id.'/open')->assertOk()->assertJsonPath('data.source_read_required', true);
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }
}
