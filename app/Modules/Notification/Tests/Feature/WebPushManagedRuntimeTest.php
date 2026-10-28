<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Modules\Notification\Channels\ManagedWebPushChannel;
use App\Modules\Notification\Contracts\WebPushInstallationProfile;
use App\Modules\Notification\Services\WebPushManagedRuntime;
use App\Modules\Notification\Services\WebPushPrivateStore;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\WebPushChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Each cached runtime/channel re-reads protected state; all identities here are temporary fixtures. */
class WebPushManagedRuntimeTest extends TestCase
{
    private string $directory;

    private object $profile;

    private WebPushPrivateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/nexum-webpush-runtime-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->profile = new class($this->directory) implements WebPushInstallationProfile
        {
            public bool $supported = true;

            public function __construct(private readonly string $directory) {}

            public function resolve(): array
            {
                return ['supported' => $this->supported, 'enrolled' => true,
                    'reason_code' => 'synthetic_profile', 'private_directory' => $this->directory,
                    'installation_id' => hash('sha256', 'synthetic-runtime')];
            }
        };
        $this->app->instance(WebPushInstallationProfile::class, $this->profile);
        $this->store = app(WebPushPrivateStore::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, sys_get_temp_dir().'/nexum-webpush-runtime-test-')) {
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

    private function save(array $values, string $key, string $revision = 'uninitialized'): array
    {
        return $this->store->change(hash('sha256', $key), hash('sha256', $key), $revision,
            fn ($old) => array_replace($old ?? ['identity_version' => (string) \Illuminate\Support\Str::uuid()], $values), fn ($next) => ['revision' => $next['revision'], 'enabled' => $next['enabled']]);
    }

    #[Test]
    public function the_same_channel_instance_observes_disable_without_sending_or_reusing_old_transport(): void
    {
        $keys = VAPID::createVapidKeys();
        $result = $this->save(['source' => 'managed', 'enabled' => true, 'subject' => 'mailto:admin@example.test',
            'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']], 'initialize');
        $channel = app(WebPushChannel::class);
        $this->assertInstanceOf(ManagedWebPushChannel::class, $channel);
        $notifiable = new class
        {
            public int $visited = 0;

            public function routeNotificationFor($driver, $notification): Collection
            {
                $this->visited++;

                return new Collection;
            }
        };
        $channel->send($notifiable, new Notification);
        $this->assertSame(1, $notifiable->visited); // No subscriptions; no provider HTTP occurs.
        $this->save(['enabled' => false], 'disable', $result['revision']);
        $channel->send($notifiable, new Notification);
        $this->assertSame(1, $notifiable->visited);
        $this->assertFalse(app(WebPushReadiness::class)->isReady());
        $this->assertNotSame($result['revision'], $channel->runtimeRevision());
    }

    #[Test]
    public function stable_environment_keys_are_referenced_and_not_copied_or_silently_replaced(): void
    {
        $keys = VAPID::createVapidKeys();
        config(['webpush.enabled' => true, 'webpush.vapid.public_key' => $keys['publicKey'],
            'webpush.vapid.private_key' => $keys['privateKey'], 'webpush.vapid.subject' => 'mailto:old@example.test']);
        $runtime = app(WebPushManagedRuntime::class);
        $this->assertTrue(app(WebPushReadiness::class)->isReady());
        $this->save(['source' => 'environment', 'enabled' => false, 'subject' => 'mailto:new@example.test',
            'public_fingerprint' => hash('sha256', $keys['publicKey'])], 'adopt');
        $this->assertArrayNotHasKey('private_key', $this->store->read()['identity']);
        $this->assertSame($keys['privateKey'], config('webpush.vapid.private_key'));
        $this->assertSame('mailto:new@example.test', $runtime->summary()['subject']);
        $other = VAPID::createVapidKeys();
        config(['webpush.vapid.public_key' => $other['publicKey'], 'webpush.vapid.private_key' => $other['privateKey']]);
        $this->assertContains('environment_identity_changed', $runtime->summary()['issues']);
        $this->assertFalse(app(WebPushReadiness::class)->isReady());
        $this->assertStringNotContainsString($keys['privateKey'], json_encode($runtime->summary()));
    }

    #[Test]
    public function operator_force_off_and_lost_attestation_fail_closed_with_no_private_summary(): void
    {
        $keys = VAPID::createVapidKeys();
        $this->save(['source' => 'managed', 'enabled' => true, 'subject' => 'mailto:admin@example.test',
            'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']], 'initialize');
        $runtime = app(WebPushManagedRuntime::class);
        $this->assertTrue(app(WebPushReadiness::class)->isReady());
        config(['webpush_setup.force_disabled' => true]);
        $this->assertFalse($runtime->summary()['enabled']);
        config(['webpush_setup.force_disabled' => false, 'webpush.enabled' => true]);
        $this->profile->supported = false;
        $summary = $runtime->summary();
        $this->assertFalse($summary['enabled']);
        $this->assertSame('unavailable', $summary['state']);
        $this->assertStringNotContainsString($keys['privateKey'], json_encode($summary));
        $this->assertArrayNotHasKey('private_key', $summary);
        $this->assertArrayNotHasKey('private_directory', $summary);
    }
}
