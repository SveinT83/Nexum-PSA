<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Notification\Contracts\WebPushInstallationProfile;
use App\Modules\Notification\Contracts\WebPushQueueEvidence;
use App\Modules\Notification\Jobs\VerifyManagedWebPushRuntime;
use App\Modules\Notification\Services\WebPushManagedRuntime;
use App\Modules\Notification\Services\WebPushPrivateStore;
use App\Modules\Notification\Services\WebPushSetupChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Exact installed HTTP surfaces/schemas with temporary protected profiles and no real worker or browser send. */
class ManagedWebPushApiSurfaceTest extends TestCase
{
    use \App\Modules\Nextcloud\Tests\AssertsNextcloudApiSchema;
    use RefreshDatabase;

    private User $actor;

    private string $token;

    private string $directory;

    private object $profile;

    private object $queue;

    private array $document;

    private array $covered = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://nexum.example.test', 'webpush.enabled' => false,
            'webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null, 'webpush.vapid.subject' => null,
            'webpush.vapid.pem_file' => null, 'notification-api.managed_webpush_execution_enabled' => true]);
        Role::findOrCreate('Tech', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo('notification.manage_channels');
        $this->token = $this->actor->createToken('Managed push fixture', ['*'])->plainTextToken;
        $this->directory = sys_get_temp_dir().'/nexum-webpush-api-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->profile = new class($this->directory) implements WebPushInstallationProfile
        {
            public bool $supported = true;

            public function __construct(private readonly string $directory) {}

            public function resolve(): array
            {
                return ['supported' => $this->supported, 'enrolled' => true,
                    'reason_code' => 'synthetic_profile', 'private_directory' => $this->directory,
                    'installation_id' => hash('sha256', 'synthetic-api')];
            }
        };
        $this->queue = new class implements WebPushQueueEvidence
        {
            public string $state = 'healthy';

            public function get(): array
            {
                return ['state' => $this->state, 'reason_code' => 'synthetic_queue',
                    'connection' => 'database', 'queue' => 'default', 'driver' => 'database',
                    'scope_hash' => hash('sha256', 'synthetic-api-queue'), 'last_completed_at' => '2026-10-28T00:00:00Z'];
            }
        };
        $this->app->instance(WebPushInstallationProfile::class, $this->profile);
        $this->app->instance(WebPushQueueEvidence::class, $this->queue);
        $this->document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        Cache::flush();
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake(['https://nexum.example.test/sw.js' => Http::response(file_get_contents(public_path('sw.js')), 200, ['Content-Type' => 'application/javascript'])]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, sys_get_temp_dir().'/nexum-webpush-api-test-')) {
            foreach (glob($this->directory.'/*') as $path) {
                if (is_file($path) || is_link($path)) {
                    unlink($path);
                }
            }
            foreach (glob($this->directory.'/.write-*') as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function api(string $method, string $suffix = '', array $data = [], array $headers = [])
    {
        $path = '/api/v1/notifications/web-push/setup'.$suffix;
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, $path, $data, $headers + ['Authorization' => 'Bearer '.$this->token]);
        if ($response->status() < 300) {
            $route = Route::getRoutes()->match(Request::create($path, $method));
            $operation = $this->document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
            $this->assertIsArray($operation, 'Managed setup requires typed Swagger.');
            $this->schema(json_decode($response->getContent()), $operation['responses'][$response->status()]['content']['application/json']['schema'], 'response');
            $this->covered[] = $route->getActionMethod();
        }

        return $response;
    }

    private function write(string $method, string $suffix, array $data, ?array $headers = null)
    {
        $headers ??= ['If-Match' => '"'.$data['revision'].'"', 'Idempotency-Key' => (string) Str::uuid()];

        return $this->api($method, $suffix, $data + ['confirm' => true], $headers);
    }

    private function initialized(): array
    {
        return $this->write('POST', '/initialize', ['revision' => 'uninitialized', 'subject' => 'mailto:admin@example.test'])
            ->assertOk()->json('data.context');
    }

    private function verified(array $context): array
    {
        $this->write('POST', '/check', ['revision' => $context['identity']['revision']])->assertOk();
        $job = null;
        $expectedKey = app(WebPushSetupChecks::class)->key($context['identity']['identity_version']);
        Bus::assertDispatched(VerifyManagedWebPushRuntime::class, function ($candidate) use (&$job, $expectedKey) {
            if ($candidate->cacheKey !== $expectedKey) {
                return false;
            } $job = $candidate;

            return true;
        });
        $job->handle(app(WebPushManagedRuntime::class), $this->queue);

        return $this->api('GET')->assertOk()->assertJsonPath('data.ready_to_enable', true)->json('data');
    }

    #[Test]
    public function all_six_scoped_operations_have_exact_schema_persistence_replay_and_key_redaction(): void
    {
        $read = $this->api('GET')->assertOk()->assertHeader('ETag', '"uninitialized"');
        $context = $this->initialized();
        $private = app(WebPushPrivateStore::class)->read()['identity']['private_key'];
        $this->actingAs($this->actor, 'web');
        $this->get(route('tech.admin.notification-channels.web-push.setup.index'))->assertOk()
            ->assertSee('ROTATE WEB PUSH')->assertDontSee($private, false)->assertDontSee($this->directory, false);
        $this->app['auth']->forgetGuards();

        $context = $this->verified($context);
        $enabled = $this->write('PATCH', '/activation', ['revision' => $context['identity']['revision'], 'enabled' => true])
            ->assertOk()->assertJsonPath('data.context.identity.enabled', true)->json('data.context');
        $headers = ['If-Match' => '"'.$enabled['identity']['revision'].'"', 'Idempotency-Key' => (string) Str::uuid()];
        $body = ['revision' => $enabled['identity']['revision'], 'confirmation' => 'ROTATE WEB PUSH'];
        $rotated = $this->write('POST', '/rotate', $body, $headers)->assertOk()->assertJsonPath('data.context.identity.enabled', false);
        $this->write('POST', '/rotate', $body, $headers)->assertOk()->assertJsonPath('replayed', true);
        $rotatedContext = $rotated->json('data.context');
        $restored = $this->write('POST', '/rollback', ['revision' => $rotatedContext['identity']['revision'],
            'archive_revision' => $enabled['identity']['revision'], 'confirmation' => 'ROLL BACK WEB PUSH'])->assertOk();
        $restored->assertJsonPath('data.context.identity.enabled', false);
        $this->assertSame($enabled['identity']['public_fingerprint'], $restored->json('data.context.identity.public_fingerprint'));
        $this->assertStringNotContainsString($private, $restored->getContent());
        $this->assertStringNotContainsString($this->directory, $restored->getContent());
        $this->assertEqualsCanonicalizing(['context', 'initialize', 'check', 'activation', 'rotate', 'rollback'], array_values(array_unique($this->covered)));
    }

    #[Test]
    public function the_wizard_is_protected_and_unknown_private_form_values_are_not_flashed(): void
    {
        $this->actingAs($this->actor, 'web');
        $url = route('tech.admin.notification-channels.web-push.setup.index');
        $this->get($url)->assertOk()->assertSee('Web Push Setup')->assertSee('Configure with delivery disabled')
            ->assertDontSee('name="private_key"', false)->assertDontSee($this->directory, false);
        $this->post(route('tech.admin.notification-channels.web-push.setup.initialize'), [
            'revision' => 'uninitialized', 'confirm' => true, 'request_key' => (string) Str::uuid(),
            'subject' => 'PRIVATE-SUBJECT-CANARY', 'private_key' => 'PRIVATE-KEY-CANARY'])
            ->assertRedirect()->assertSessionHasErrors('setup');
        $this->assertStringNotContainsString('CANARY', json_encode(session()->getOldInput()));
        $this->profile->supported = false;
        $this->get($url)->assertOk()->assertSee('installation setup could not be verified');
        $this->actor->revokePermissionTo('notification.manage_channels');
        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function default_off_execution_and_missing_revision_or_native_rights_do_not_generate_keys(): void
    {
        config(['notification-api.managed_webpush_execution_enabled' => false]);
        $this->write('POST', '/initialize', ['revision' => 'uninitialized', 'subject' => 'mailto:admin@example.test'])->assertStatus(409);
        $this->assertSame('uninitialized', app(WebPushPrivateStore::class)->read()['state']);
        config(['notification-api.managed_webpush_execution_enabled' => true]);
        $this->api('POST', '/initialize', ['revision' => 'uninitialized', 'subject' => 'mailto:admin@example.test', 'confirm' => true],
            ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(428);
        $this->actor->revokePermissionTo('notification.manage_channels');
        $this->api('GET')->assertForbidden();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function browser_registration_requires_its_actual_current_application_server_public_key(): void
    {
        $context = $this->verified($this->initialized());
        $this->write('PATCH', '/activation', ['revision' => $context['identity']['revision'], 'enabled' => true])->assertOk();
        $this->actingAs($this->actor, 'web');
        $payload = ['endpoint' => 'https://push.example.test/registration', 'keys' => ['p256dh' => str_repeat('A', 40), 'auth' => str_repeat('B', 24)]];
        $url = route('tech.profile.notifications.web-push.devices.store');
        $this->postJson($url, $payload)->assertStatus(409);
        $public = app(WebPushManagedRuntime::class)->effective()['public_key'];
        $this->postJson($url, $payload + ['application_server_key' => $public])->assertCreated();
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    #[Test]
    public function setup_context_explains_an_unavailable_queue_integration_without_booting_it(): void
    {
        $this->app->forgetInstance(WebPushQueueEvidence::class);
        $this->app->bind(WebPushQueueEvidence::class, \App\Modules\Notification\Support\SystemWebPushQueueEvidence::class);
        config(['runtime_health.enabled' => false]);
        $response = $this->api('GET')->assertOk()->assertJsonPath('data.ready_to_enable', false);
        $this->assertSame('blocked', $response->json('data.queue.state'));
        $this->assertContains($response->json('data.queue.reason_code'), ['runtime_health_not_enabled', 'runtime_health_integration_unavailable']);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }
}
