<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderConnectionVerifier;
use App\Modules\Integration\Services\EmailProviderLifecycleAccountLocks;
use App\Modules\Integration\Services\EmailProviderTransportFactory;
use App\Modules\Integration\Services\EmailProviderVerificationDeadline;
use App\Modules\Integration\Support\EmailProviderEndpoint;
use App\Modules\Integration\Support\EmailProviderResolvedEndpoint;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use App\Modules\Integration\Support\EmailProviderTelemetryRedactor;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;

class EmailProviderSecurityBoundaryTest extends TestCase
{
    #[Test]
    public function telemetry_redaction_covers_every_provider_request_alias_and_drops_query_and_model_entries(): void
    {
        $canaries = [
            'host-canary.example',
            'user-canary@example.test',
            'secret-canary',
            'reason-canary',
            'cidr-canary',
            'transport-canary',
        ];
        $request = IncomingEntry::make([
            'request' => [
                'imap_host' => $canaries[0],
                'imap_username' => $canaries[1],
                'imap_secret' => $canaries[2],
                'private_endpoint_reason' => $canaries[3],
                'trusted_cidr_name' => $canaries[4],
                'imap_transport' => $canaries[5],
            ],
            'session' => [
                '_old_input' => [
                    'smtp_host' => $canaries[0],
                    'smtp_username' => $canaries[1],
                    'smtp_password' => $canaries[2],
                    'trust_mode' => 'trusted_private',
                ],
            ],
            'context' => [
                'credentials' => [
                    'client_secret' => $canaries[2],
                ],
                'pinned_ip' => '10.20.30.40',
            ],
        ])->type(EntryType::REQUEST);

        $this->assertTrue(EmailProviderTelemetryRedactor::sanitize($request));
        $encoded = json_encode($request->content, JSON_THROW_ON_ERROR);
        foreach ([...$canaries, '10.20.30.40', 'trusted_private'] as $canary) {
            $this->assertStringNotContainsString($canary, $encoded);
        }

        $query = IncomingEntry::make([
            'sql' => 'insert into integration_email_provider_credential_versions values ("cipher-canary")',
        ])->type(EntryType::QUERY);
        $model = IncomingEntry::make([
            'model' => 'App\\Modules\\Integration\\Models\\EmailProviderConnection:1',
            'changes' => ['imap_host' => 'model-host-canary'],
        ])->type(EntryType::MODEL);

        $this->assertFalse(EmailProviderTelemetryRedactor::sanitize($query));
        $this->assertFalse(EmailProviderTelemetryRedactor::sanitize($model));
        $this->assertFalse(config('telescope.watchers.'.\Laravel\Telescope\Watchers\QueryWatcher::class.'.enabled'));
        $this->assertFalse(config('telescope.watchers.'.\Laravel\Telescope\Watchers\ModelWatcher::class.'.enabled'));
    }

    #[Test]
    public function provider_verifier_disconnects_and_stops_transports_when_connect_or_start_fails(): void
    {
        $runtime = $this->runtime();

        $imap = Mockery::mock(Client::class);
        $imap->shouldReceive('connect')->once()->andThrow(new RuntimeException('raw imap provider response'));
        $imap->shouldReceive('disconnect')->once();
        $factory = Mockery::mock(EmailProviderTransportFactory::class);
        $factory->shouldReceive('makeImap')->once()->andReturn($imap);
        $factory->shouldNotReceive('makeSmtp');

        try {
            (new EmailProviderConnectionVerifier($factory))->verify($runtime);
            $this->fail('The failed IMAP probe must bubble to the sanitizing action boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame('raw imap provider response', $exception->getMessage());
        }

        $imap = Mockery::mock(Client::class);
        $imap->shouldReceive('connect')->once();
        $imap->shouldReceive('disconnect')->once();
        $smtp = Mockery::mock(EsmtpTransport::class);
        $smtp->shouldReceive('start')->once()->andThrow(new RuntimeException('raw smtp provider response'));
        $smtp->shouldReceive('stop')->once();
        $factory = Mockery::mock(EmailProviderTransportFactory::class);
        $factory->shouldReceive('makeImap')->once()->andReturn($imap);
        $factory->shouldReceive('makeSmtp')->once()->andReturn($smtp);

        try {
            (new EmailProviderConnectionVerifier($factory))->verify($runtime);
            $this->fail('The failed SMTP probe must bubble to the sanitizing action boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame('raw smtp provider response', $exception->getMessage());
        }
    }

    #[Test]
    public function verification_deadline_bounds_probe_and_hanging_cleanup_without_stealing_an_existing_alarm(): void
    {
        if (! function_exists('pcntl_alarm') || ! defined('SIGALRM')) {
            $this->markTestSkipped('PCNTL is required by the production verification contract.');
        }

        config()->set('email_provider_security.verification_deadline_seconds', 2);
        config()->set('email_provider_security.verification_cleanup_grace_seconds', 1);
        config()->set('email_provider_security.verification_outer_alarm_margin_seconds', 2);
        $deadline = new EmailProviderVerificationDeadline;
        $started = microtime(true);

        try {
            $deadline->run(static function (): void {
                try {
                    sleep(10);
                } finally {
                    // Simulate a provider library whose graceful disconnect
                    // also hangs after the main probe deadline is consumed.
                    sleep(10);
                }
            });
            $this->fail('The absolute provider deadline must interrupt probe and cleanup.');
        } catch (EmailProviderSecurityException $exception) {
            $this->assertContains($exception->reasonCode, [
                'provider_verification_deadline_exceeded',
                'provider_verification_cleanup_deadline_exceeded',
            ]);
            $this->assertLessThan(5.0, microtime(true) - $started);
        }

        $previous = pcntl_signal_get_handler(SIGALRM);
        $outerHandler = static function (): void {};
        pcntl_signal(SIGALRM, $outerHandler, false);
        pcntl_alarm(4);
        try {
            $this->assertSecurityReason(
                fn () => $deadline->run(static fn (): bool => true),
                'provider_verification_deadline_conflict',
            );
            $this->assertGreaterThan(0, pcntl_alarm(0));

            pcntl_alarm(10);
            $this->assertTrue($deadline->run(static fn (): bool => true));
            $this->assertSame($outerHandler, pcntl_signal_get_handler(SIGALRM));
            $this->assertGreaterThanOrEqual(8, pcntl_alarm(0));
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);
        }
    }

    #[Test]
    public function lifecycle_lock_budget_outlives_maximum_probe_cleanup_and_finalization(): void
    {
        config()->set('email_provider_security.verification_deadline_seconds', 120);
        config()->set('email_provider_security.verification_cleanup_grace_seconds', 5);

        $this->assertGreaterThanOrEqual(185, (new EmailProviderLifecycleAccountLocks)->leaseSeconds());
    }

    #[Test]
    public function pinned_ipv6_addresses_are_bracketed_while_tls_peer_name_remains_the_original_host(): void
    {
        $runtime = $this->runtime('2001:4860:4860::8888', '2606:4700:4700::1111');
        $factory = new EmailProviderTransportFactory;
        $imap = $factory->makeImap($runtime)->getAccountConfig();
        $smtp = $factory->makeSmtp($runtime);

        $this->assertSame('[2001:4860:4860::8888]', $imap['host']);
        $this->assertSame('imap.example.test', $imap['ssl_options']['peer_name']);
        $this->assertSame('[2606:4700:4700::1111]', $smtp->getStream()->getHost());
        $this->assertSame(
            'smtp.example.test',
            $smtp->getStream()->getStreamOptions()['ssl']['peer_name'],
        );
    }

    private function runtime(
        string $imapAddress = '8.8.8.8',
        string $smtpAddress = '1.1.1.1',
    ): EmailProviderRuntimeCredentials {
        return new EmailProviderRuntimeCredentials(
            providerIntegrationId: 'provider-safe-id',
            configurationVersion: 1,
            credentialVersion: 1,
            imapEndpoint: new EmailProviderResolvedEndpoint(
                new EmailProviderEndpoint(
                    'imap',
                    'imap.example.test',
                    993,
                    'implicit_tls',
                    'standard.imap.993.implicit_tls',
                ),
                $imapAddress,
            ),
            smtpEndpoint: new EmailProviderResolvedEndpoint(
                new EmailProviderEndpoint(
                    'smtp',
                    'smtp.example.test',
                    465,
                    'implicit_tls',
                    'standard.smtp.465.implicit_tls',
                ),
                $smtpAddress,
            ),
            imapUsername: 'imap-user-canary',
            imapSecret: 'imap-secret-canary',
            smtpUsername: 'smtp-user-canary',
            smtpSecret: 'smtp-secret-canary',
            imapAuthType: 'password',
            smtpAuthType: 'password',
        );
    }

    private function assertSecurityReason(callable $callback, string $reasonCode): void
    {
        try {
            $callback();
            $this->fail('The security boundary should have denied the operation.');
        } catch (EmailProviderSecurityException $exception) {
            $this->assertSame($reasonCode, $exception->reasonCode);
            $this->assertNull($exception->getPrevious());
        }
    }
}
