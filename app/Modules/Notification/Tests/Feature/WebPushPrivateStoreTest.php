<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Modules\Notification\Contracts\WebPushInstallationProfile;
use App\Modules\Notification\Services\WebPushPrivateStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Temporary protected artifacts only; no native installation setup or actual VAPID identity generation. */
class WebPushPrivateStoreTest extends TestCase
{
    private string $directory;

    private object $profile;

    private WebPushPrivateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/nexum-webpush-store-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->profile = new class($this->directory) implements WebPushInstallationProfile
        {
            public bool $supported = true;

            public bool $enrolled = true;

            public function __construct(private readonly string $directory) {}

            public function resolve(): array
            {
                return ['supported' => $this->supported, 'enrolled' => $this->enrolled,
                    'reason_code' => 'synthetic_profile', 'private_directory' => $this->directory,
                    'installation_id' => hash('sha256', 'synthetic-installation')];
            }
        };
        $this->store = new WebPushPrivateStore($this->profile);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, sys_get_temp_dir().'/nexum-webpush-store-test-')) {
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

    private function summary(array $identity): array
    {
        return ['revision' => $identity['revision'], 'enabled' => $identity['enabled'], 'configured' => true];
    }

    private function initialize(string $key = 'setup-one'): array
    {
        return $this->store->change(hash('sha256', $key), hash('sha256', 'safe-subject'), 'uninitialized',
            fn () => ['source' => 'managed', 'enabled' => false, 'subject' => 'mailto:admin@example.test',
                'public_key' => 'PUBLIC-CANARY', 'private_key' => 'PRIVATE-KEY-CANARY'], $this->summary(...));
    }

    #[Test]
    public function encrypted_atomic_readback_and_exact_action_replay_never_expose_key_material(): void
    {
        $first = $this->initialize();
        $again = $this->store->change(hash('sha256', 'setup-one'), hash('sha256', 'safe-subject'), 'uninitialized',
            function () {
                $this->fail('A replay must not regenerate an identity.');
            }, $this->summary(...));
        $this->assertFalse($first['replayed']);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['revision'], $again['revision']);
        $this->assertSame('PRIVATE-KEY-CANARY', $this->store->read()['identity']['private_key']);
        foreach (glob($this->directory.'/*.enc') as $path) {
            $this->assertStringNotContainsString('PRIVATE-KEY-CANARY', file_get_contents($path));
            $this->assertSame(0600, fileperms($path) & 0777);
        }
        $this->assertStringNotContainsString('PRIVATE', json_encode($again));
    }

    #[Test]
    public function an_exact_prior_revision_is_retained_and_stale_changes_fail_closed(): void
    {
        $first = $this->initialize();
        $next = $this->store->change(hash('sha256', 'disable-one'), hash('sha256', 'disable'), $first['revision'],
            fn ($old) => array_replace($old, ['enabled' => false, 'subject' => 'mailto:new@example.test']), $this->summary(...));
        $this->assertNotSame($first['revision'], $next['revision']);
        $this->assertSame('mailto:admin@example.test', $this->store->historical($first['revision'])['subject']);
        try {
            $this->store->change(hash('sha256', 'stale'), hash('sha256', 'other'), $first['revision'],
                fn ($old) => array_replace($old, ['enabled' => true]), $this->summary(...));
            $this->fail('Stale revision was accepted.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(412, $e->getStatusCode());
        }
        $this->assertSame($next['revision'], $this->store->read()['identity']['revision']);
    }

    #[Test]
    public function corrupt_or_permissive_artifacts_are_not_read_or_replaced(): void
    {
        $this->initialize();
        chmod($this->directory.'/current.enc', 0644);
        $this->assertSame('unavailable', $this->store->read()['state']);
        chmod($this->directory.'/current.enc', 0600);
        file_put_contents($this->directory.'/current.enc', 'CORRUPT-ARTIFACT');
        $this->assertSame('unavailable', $this->store->read()['state']);
        try {
            $this->store->change(hash('sha256', 'corrupt'), hash('sha256', 'safe'), 'uninitialized', fn () => [], $this->summary(...));
            $this->fail('Corrupt state was replaced.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame('CORRUPT-ARTIFACT', file_get_contents($this->directory.'/current.enc'));
    }

    #[Test]
    public function receipt_key_conflict_is_not_permission_to_generate_again(): void
    {
        $this->initialize();
        try {
            $this->store->change(hash('sha256', 'setup-one'), hash('sha256', 'changed'), 'uninitialized',
                function () {
                    $this->fail('Conflicting receipt executed.');
                }, $this->summary(...));
            $this->fail('Conflicting receipt was accepted.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    #[Test]
    public function unsupported_enrolled_installation_never_falls_back_to_legacy_state(): void
    {
        $this->profile->supported = false;
        $this->assertSame('unavailable', $this->store->read()['state']);
        $this->profile->enrolled = false;
        $this->assertSame('legacy', $this->store->read()['state']);
        $this->assertSame([], glob($this->directory.'/*'));
    }

    #[Test]
    public function a_symlink_cannot_replace_the_protected_current_identity(): void
    {
        $first = $this->initialize();
        $other = $this->directory.'/other.enc';
        rename($this->directory.'/current.enc', $other);
        symlink($other, $this->directory.'/current.enc');
        $this->assertSame('unavailable', $this->store->read()['state']);
        $this->assertStringNotContainsString('PRIVATE-KEY-CANARY', file_get_contents($other));
    }
}
