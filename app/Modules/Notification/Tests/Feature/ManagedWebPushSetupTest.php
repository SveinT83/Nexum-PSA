<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Notification\Actions\ManageWebPushSetup;
use App\Modules\Notification\Contracts\WebPushInstallationProfile;
use App\Modules\Notification\Contracts\WebPushQueueEvidence;
use App\Modules\Notification\Jobs\VerifyManagedWebPushRuntime;
use App\Modules\Notification\Models\WebPushSubscription;
use App\Modules\Notification\Services\WebPushManagedRuntime;
use App\Modules\Notification\Services\WebPushPrivateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Full local lifecycle with temporary encrypted identities, synthetic installation authority and no external push. */
class ManagedWebPushSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private string $directory;

    private object $profile;

    private object $queue;

    private ManageWebPushSetup $actions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://nexum.example.test', 'webpush.enabled' => false,
            'webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null,
            'webpush.vapid.pem_file' => null, 'webpush.vapid.subject' => null]);
        Role::findOrCreate('Tech', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo('notification.manage_channels');
        $this->actingAs($this->actor);
        $this->directory = sys_get_temp_dir().'/nexum-webpush-setup-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->profile = new class($this->directory) implements WebPushInstallationProfile
        {
            public bool $supported = true;

            public function __construct(private readonly string $directory) {}

            public function resolve(): array
            {
                return ['supported' => $this->supported, 'enrolled' => true,
                    'reason_code' => 'synthetic_supported_profile', 'private_directory' => $this->directory,
                    'installation_id' => hash('sha256', 'synthetic-setup')];
            }
        };
        $this->queue = new class implements WebPushQueueEvidence
        {
            public string $state = 'healthy';

            public string $scope = 'synthetic-owned-scope';

            public function get(): array
            {
                return ['state' => $this->state, 'reason_code' => 'synthetic_queue',
                    'connection' => 'database', 'queue' => 'default', 'driver' => 'database',
                    'scope_hash' => hash('sha256', $this->scope), 'last_completed_at' => '2026-10-28T00:00:00Z'];
            }
        };
        $this->app->instance(WebPushInstallationProfile::class, $this->profile);
        $this->app->instance(WebPushQueueEvidence::class, $this->queue);
        $this->actions = app(ManageWebPushSetup::class);
        Cache::flush();
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake(['https://nexum.example.test/sw.js' => Http::response(file_get_contents(public_path('sw.js')), 200, ['Content-Type' => 'application/javascript'])]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, sys_get_temp_dir().'/nexum-webpush-setup-test-')) {
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

    private function request(array $data): Request
    {
        return Request::create('/tech/admin/notification-channels/web-push/setup', 'POST', $data + ['confirm' => true, 'request_key' => (string) Str::uuid()]);
    }

    private function initialize(): array
    {
        return $this->actions->initialize($this->request(['revision' => 'uninitialized', 'subject' => 'mailto:admin@example.test']));
    }

    private function enable(): array
    {
        $revision = app(WebPushManagedRuntime::class)->summary()['revision'];
        $this->actions->check($this->request(['revision' => $revision]));
        $job = null;
        Bus::assertDispatched(VerifyManagedWebPushRuntime::class, function ($candidate) use (&$job) {
            $job = $candidate;

            return true;
        });
        $this->assertNotNull($job);
        $private = app(WebPushPrivateStore::class)->read()['identity']['private_key'];
        $this->assertStringNotContainsString($private, serialize($job));
        $job->handle(app(WebPushManagedRuntime::class), $this->queue);
        $this->assertTrue($this->actions->context($this->request([]))['ready_to_enable']);

        return $this->actions->activation($this->request(['revision' => $revision, 'enabled' => true]));
    }

    #[Test]
    public function initialization_check_worker_ack_enable_disable_and_reenable_preserve_identity(): void
    {
        $initialized = $this->initialize();
        $this->assertFalse($initialized['context']['identity']['enabled']);
        $this->assertTrue($initialized['context']['identity']['identity_valid']);
        $this->assertFalse($initialized['context']['ready_to_enable']);
        $identity = $initialized['context']['identity']['identity_version'];
        $enabled = $this->enable();
        $this->assertTrue($enabled['context']['identity']['enabled']);
        $disabled = $this->actions->activation($this->request(['revision' => $enabled['context']['identity']['revision'], 'enabled' => false]));
        $this->assertFalse($disabled['context']['identity']['enabled']);
        $again = $this->actions->activation($this->request(['revision' => $disabled['context']['identity']['revision'], 'enabled' => true]));
        $this->assertTrue($again['context']['identity']['enabled']);
        $this->assertSame($identity, $again['context']['identity']['identity_version']);
        $this->assertArrayNotHasKey('private_key', $again['context']['identity']);
    }

    #[Test]
    public function rotation_and_rollback_are_disabled_audited_and_completed_replay_keeps_new_registrations(): void
    {
        $this->initialize();
        $enabled = $this->enable();
        $old = app(WebPushManagedRuntime::class)->summary();
        $this->actor->updatePushSubscription('https://push.example.test/old', str_repeat('A', 40), str_repeat('B', 24));
        $request = $this->request(['revision' => $old['revision'], 'confirmation' => 'ROTATE WEB PUSH']);
        $rotated = $this->actions->rotate($request);
        $this->assertFalse($rotated['context']['identity']['enabled']);
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->assertNotSame($old['public_fingerprint'], $rotated['context']['identity']['public_fingerprint']);
        $this->assertSame($old['revision'], $rotated['context']['identity']['previous_identity_revision']);
        $this->assertDatabaseHas('web_push_subscription_events', ['action' => 'administrator_revoked']);
        $this->actor->updatePushSubscription('https://push.example.test/new', str_repeat('C', 40), str_repeat('D', 24));
        $replay = $this->actions->rotate($request);
        $this->assertTrue($replay['operation']['replayed']);
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertSame($rotated['context']['identity']['revision'], $replay['context']['identity']['revision']);
        $restored = $this->actions->rollback($this->request(['revision' => $rotated['context']['identity']['revision'],
            'archive_revision' => $old['revision'], 'confirmation' => 'ROLL BACK WEB PUSH']));
        $this->assertFalse($restored['context']['identity']['enabled']);
        $this->assertSame($old['public_fingerprint'], $restored['context']['identity']['public_fingerprint']);
        $this->assertNotSame($old['identity_version'], $restored['context']['identity']['identity_version']);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    #[Test]
    public function unsafe_fields_are_removed_before_validation_and_do_not_create_identity(): void
    {
        $request = $this->request(['revision' => 'uninitialized', 'subject' => 'PRIVATE-SUBJECT-CANARY', 'private_key' => 'PRIVATE-KEY-CANARY']);
        try {
            $this->actions->initialize($request);
            $this->fail('Private input was accepted.');
        } catch (ValidationException) {
        }
        $this->assertStringNotContainsString('CANARY', json_encode($request->all()));
        $this->assertSame('uninitialized', app(WebPushPrivateStore::class)->read()['state']);
    }

    #[Test]
    public function missing_worker_proof_and_unapproved_queue_cannot_activate_or_dispatch(): void
    {
        $first = $this->initialize();
        $revision = $first['context']['identity']['revision'];
        try {
            $this->actions->activation($this->request(['revision' => $revision, 'enabled' => true]));
            $this->fail('Activated without proof.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->queue->state = 'blocked';
        try {
            $this->actions->check($this->request(['revision' => $revision]));
            $this->fail('A blocked queue was used.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        Bus::assertNothingDispatched();
        $this->assertFalse(app(WebPushManagedRuntime::class)->summary()['enabled']);
    }

    #[Test]
    public function html_success_and_certificate_failure_cannot_dispatch_a_worker_probe(): void
    {
        $revision = $this->initialize()['context']['identity']['revision'];
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('<html>Login</html>', 200, ['Content-Type' => 'text/html'])]);
        try {
            $this->actions->check($this->request(['revision' => $revision]));
            $this->fail('HTML was accepted.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \GuzzleHttp\Exception\ConnectException(
            'PRIVATE-TRANSPORT-CANARY', new \GuzzleHttp\Psr7\Request('GET', 'https://nexum.example.test/sw.js'),
            null, ['errno' => 60]));
        $evidence = app(\App\Modules\Notification\Services\WebPushSetupChecks::class)->trustedWorker();
        $this->assertSame(['verified' => false, 'reason_code' => 'trusted_https_untrusted'], $evidence);
        $this->assertStringNotContainsString('CANARY', json_encode($evidence));
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function authority_is_rechecked_after_http_and_inside_the_worker(): void
    {
        $revision = $this->initialize()['context']['identity']['revision'];
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function () {
            $this->actor->revokePermissionTo('notification.manage_channels');

            return Http::response(file_get_contents(public_path('sw.js')), 200, ['Content-Type' => 'application/javascript']);
        });
        try {
            $this->actions->check($this->request(['revision' => $revision]));
            $this->fail('Revoked actor dispatched.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Bus::assertNothingDispatched();
        $this->actor->givePermissionTo('notification.manage_channels');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(file_get_contents(public_path('sw.js')), 200, ['Content-Type' => 'application/javascript'])]);
        $this->actions->check($this->request(['revision' => $revision]));
        $job = null;
        Bus::assertDispatched(VerifyManagedWebPushRuntime::class, function ($candidate) use (&$job) {
            $job = $candidate;

            return true;
        });
        $this->actor->revokePermissionTo('notification.manage_channels');
        $job->handle(app(WebPushManagedRuntime::class), $this->queue);
        $this->actor->givePermissionTo('notification.manage_channels');
        $context = $this->actions->context($this->request([]));
        $this->assertFalse($context['ready_to_enable']);
        $this->assertSame('blocked', $context['worker']);
    }

    #[Test]
    public function expired_proof_and_changed_exact_queue_cannot_enable(): void
    {
        $this->initialize();
        $enabled = $this->enable();
        $disabled = $this->actions->activation($this->request(['revision' => $enabled['context']['identity']['revision'], 'enabled' => false]));
        $this->travel(601)->seconds();
        try {
            $this->actions->activation($this->request(['revision' => $disabled['context']['identity']['revision'], 'enabled' => true]));
            $this->fail('Expired proof enabled.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->travelBack();
        $this->queue->scope = 'different-owned-scope';
        try {
            $this->actions->activation($this->request(['revision' => $disabled['context']['identity']['revision'], 'enabled' => true]));
            $this->fail('Different queue proof enabled.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertFalse(app(WebPushManagedRuntime::class)->summary()['enabled']);
    }

    #[Test]
    public function failed_owner_cleanup_stays_disabled_and_exact_pending_intent_can_resume(): void
    {
        $this->initialize();
        $enabled = $this->enable();
        $this->actor->updatePushSubscription('https://push.example.test/valid', str_repeat('A', 40), str_repeat('B', 24));
        $this->actor->updatePushSubscription('https://push.example.test/orphan', str_repeat('C', 40), str_repeat('D', 24));
        WebPushSubscription::where('endpoint', 'https://push.example.test/orphan')->update(['subscribable_id' => $this->actor->id + 100000]);
        try {
            $this->actions->rotate($this->request(['revision' => $enabled['context']['identity']['revision'], 'confirmation' => 'ROTATE WEB PUSH']));
            $this->fail('Unresolved ownership was ignored.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $pending = app(WebPushManagedRuntime::class)->summary();
        $this->assertFalse($pending['enabled']);
        $this->assertTrue($pending['registration_reset_required']);
        $this->assertSame('rotation', $pending['pending_operation_kind']);
        $this->assertDatabaseCount('push_subscriptions', 2);
        WebPushSubscription::where('endpoint', 'https://push.example.test/orphan')->update(['subscribable_id' => $this->actor->id]);
        $resumed = $this->actions->rotate($this->request(['revision' => $pending['revision'], 'confirmation' => 'ROTATE WEB PUSH']));
        $this->assertFalse($resumed['context']['identity']['enabled']);
        $this->assertFalse($resumed['context']['identity']['registration_reset_required']);
        $this->assertNull($resumed['context']['identity']['pending_operation_kind']);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }
}
