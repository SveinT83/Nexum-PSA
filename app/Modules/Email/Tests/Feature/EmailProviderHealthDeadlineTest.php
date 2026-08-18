<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\System\Integrations\Integration;
use App\Modules\Email\Jobs\EmailAccountHealthCheckJob;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailHealthCheck;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailTestService;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Services\EmailProviderCredentialCipher;
use App\Modules\Integration\Services\EmailProviderTransportFactory;
use App\Modules\Integration\Services\EmailProviderVerificationDeadline;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;

class EmailProviderHealthDeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function wrapped_provider_deadline_and_hanging_cleanup_are_bounded_sanitized_and_release_the_account_lock(): void
    {
        if (! function_exists('pcntl_alarm') || ! defined('SIGALRM')) {
            $this->markTestSkipped('PCNTL is required by the production provider health deadline.');
        }

        config()->set('email_provider_security.verification_deadline_seconds', 2);
        config()->set('email_provider_security.verification_cleanup_grace_seconds', 1);
        $account = $this->activeIntegrationAccount();
        $rawCanaries = [
            'health-wrapper-host-canary.example',
            'health-wrapper-user-canary@example.test',
            'health-wrapper-provider-response-canary',
        ];

        $imap = Mockery::mock(Client::class);
        $imap->shouldReceive('connect')->once()->andReturnUsing(function () use ($rawCanaries): void {
            try {
                sleep(10);
            } catch (EmailProviderSecurityException $deadline) {
                // Webklex wraps protocol/runtime failures. The public health
                // result must recognize the bounded cause without preserving
                // the provider wrapper or its endpoint/response diagnostics.
                throw new RuntimeException(implode('|', $rawCanaries), 0, $deadline);
            }
        });
        $imap->shouldReceive('disconnect')->once()->andReturnUsing(function () use ($account): void {
            $this->assertNull(
                EmailAccountProviderLock::acquire((int) $account->id, 1),
                'The shared provider lock must remain owned throughout bounded cleanup.',
            );
            sleep(10);
        });

        $transports = Mockery::mock(EmailProviderTransportFactory::class);
        $transports->shouldReceive('makeImap')
            ->once()
            ->with(
                Mockery::type(EmailProviderRuntimeCredentials::class),
                Mockery::type('int'),
            )
            ->andReturn($imap);
        $transports->shouldNotReceive('makeSmtp');
        Log::spy();

        $service = new EmailTestService(
            app(EmailAccountProviderRuntimeResolver::class),
            $transports,
            new EmailProviderVerificationDeadline,
        );
        $startedAt = microtime(true);
        $result = $service->run($account, 1);
        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(6.0, $elapsed);
        $this->assertFalse($result->imap_ok);
        $this->assertFalse($result->smtp_ok);
        $this->assertSame($result->imap_error_code, $result->smtp_error_code);
        $this->assertContains($result->imap_error_code, [
            'PROVIDER_VERIFICATION_DEADLINE_EXCEEDED',
            'PROVIDER_VERIFICATION_CLEANUP_DEADLINE_EXCEEDED',
        ]);
        $this->assertNull($result->smtp_ms, 'SMTP must not start after an IMAP deadline.');

        $account = $account->fresh();
        $this->assertSame('Error', $account->last_test_result);
        $this->assertSame($result->imap_error_code, $account->last_error_code);
        $this->assertSame(
            'The provider check was blocked by its absolute safety deadline.',
            $account->last_error_message,
        );
        $diagnostic = var_export($result, true).'|'.json_encode([
            $account->last_error_code,
            $account->last_error_message,
        ], JSON_THROW_ON_ERROR);
        foreach ($rawCanaries as $canary) {
            $this->assertStringNotContainsString($canary, $diagnostic);
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Email provider test was blocked by its safety deadline.',
                Mockery::on(function (array $context) use ($rawCanaries): bool {
                    $encoded = json_encode($context, JSON_THROW_ON_ERROR);
                    foreach ($rawCanaries as $canary) {
                        $this->assertStringNotContainsString($canary, $encoded);
                    }

                    return str_starts_with((string) ($context['code'] ?? ''), 'PROVIDER_VERIFICATION_')
                        && ($context['exception'] ?? null) === EmailProviderSecurityException::class;
                }),
            );

        $releasedLock = EmailAccountProviderLock::acquire((int) $account->id, 5);
        $this->assertNotNull($releasedLock, 'The health action must release its provider lock after bounded cleanup.');
        $releasedLock?->release();
    }

    #[Test]
    public function database_queue_worker_composes_its_outer_alarm_with_the_provider_health_deadline(): void
    {
        if (! function_exists('pcntl_alarm') || ! defined('SIGALRM')) {
            $this->markTestSkipped('PCNTL is required by Laravel queue worker timeouts.');
        }

        config()->set('email_provider_security.verification_deadline_seconds', 2);
        config()->set('email_provider_security.verification_cleanup_grace_seconds', 1);
        config()->set('email_provider_security.verification_outer_alarm_margin_seconds', 2);
        $account = $this->activeIntegrationAccount();
        $observedInnerRemaining = null;
        $imap = Mockery::mock(Client::class);
        $imap->shouldReceive('connect')->once()->andReturnUsing(function () use (&$observedInnerRemaining, &$imap): Client {
            $observedInnerRemaining = pcntl_alarm(0);
            if ($observedInnerRemaining > 0) {
                pcntl_alarm($observedInnerRemaining);
            }

            return $imap;
        });
        $imap->shouldReceive('disconnect')->once()->andReturn($imap);
        $smtp = Mockery::mock(\Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport::class);
        $smtp->shouldReceive('start')->once();
        $smtp->shouldReceive('stop')->once();
        $transports = Mockery::mock(EmailProviderTransportFactory::class);
        $transports->shouldReceive('makeImap')->once()->andReturn($imap);
        $transports->shouldReceive('makeSmtp')->once()->andReturn($smtp);
        $this->app->instance(EmailTestService::class, new EmailTestService(
            app(EmailAccountProviderRuntimeResolver::class),
            $transports,
            new EmailProviderVerificationDeadline,
        ));

        $queueName = 'order6-health-deadline-worker';
        Queue::connection('database')->push(
            new EmailAccountHealthCheckJob((int) $account->id),
            '',
            $queueName,
        );
        $worker = app('queue.worker');
        $options = new WorkerOptions(
            name: 'order6-health-deadline-test',
            memory: 256,
            timeout: 240,
            sleep: 0,
            maxTries: 1,
            force: true,
            stopWhenEmpty: true,
            maxJobs: 1,
            maxTime: 30,
        );
        $signalHandlers = collect([
            SIGALRM,
            defined('SIGTERM') ? SIGTERM : null,
            defined('SIGQUIT') ? SIGQUIT : null,
            defined('SIGUSR2') ? SIGUSR2 : null,
            defined('SIGCONT') ? SIGCONT : null,
        ])->filter()->mapWithKeys(
            fn (int $signal): array => [$signal => pcntl_signal_get_handler($signal)],
        )->all();
        $previousAsync = pcntl_async_signals();

        try {
            $this->assertSame(0, $worker->daemon('database', $queueName, $options));
        } finally {
            pcntl_alarm(0);
            foreach ($signalHandlers as $signal => $handler) {
                pcntl_signal((int) $signal, $handler);
            }
            pcntl_async_signals($previousAsync);
        }

        $check = EmailHealthCheck::query()->where('account_id', $account->id)->sole();
        $diagnostic = json_encode([
            'imap_status' => $check->imap_status,
            'smtp_status' => $check->smtp_status,
            'error_code' => $check->error_code,
            'error_message' => $check->error_message,
        ], JSON_THROW_ON_ERROR);
        $this->assertSame('OK', $check->imap_status, $diagnostic);
        $this->assertSame('OK', $check->smtp_status, $diagnostic);
        $this->assertGreaterThan(0, $observedInnerRemaining, 'The provider deadline must replace the worker alarm during I/O.');
        $this->assertNull($check->error_code);
        $this->assertDatabaseMissing('jobs', ['queue' => $queueName]);
    }

    private function activeIntegrationAccount(): EmailAccount
    {
        $id = (string) Str::uuid();
        Integration::query()->create([
            'id' => $id,
            'name' => 'Health deadline provider',
            'type' => 'email_provider',
            'status' => 'active',
            'config' => ['provider_status' => 'active'],
            'secrets' => null,
            'is_healthy' => true,
        ]);
        $connection = EmailProviderConnection::query()->create([
            'integration_id' => $id,
            'status' => 'active',
            'configuration_version' => 1,
            'verified_configuration_version' => 1,
            'verified_credential_version' => 1,
            'imap_host' => '8.8.8.8',
            'imap_port' => 993,
            'imap_transport' => 'implicit_tls',
            'imap_endpoint_policy_id' => 'standard.imap.993.implicit_tls',
            'imap_auth_type' => 'password',
            'smtp_host' => '1.1.1.1',
            'smtp_port' => 465,
            'smtp_transport' => 'implicit_tls',
            'smtp_endpoint_policy_id' => 'standard.smtp.465.implicit_tls',
            'smtp_auth_type' => 'password',
            'trust_mode' => 'public',
            'capabilities' => ['imap' => true, 'smtp' => true],
            'last_verification_code' => 'verified',
            'last_verified_at' => now(),
        ]);
        $ciphertext = app(EmailProviderCredentialCipher::class)->encrypt([
            'imap_username' => 'health-imap-user-canary',
            'imap_secret' => 'health-imap-secret-canary',
            'smtp_username' => 'health-smtp-user-canary',
            'smtp_secret' => 'health-smtp-secret-canary',
        ]);
        $version = EmailProviderCredentialVersion::query()->create([
            'provider_integration_id' => $id,
            'version' => 1,
            'state' => EmailProviderCredentialVersion::STATE_ACTIVE,
            ...$ciphertext,
            'credential_fingerprint' => hash('sha256', $id),
            'verified_configuration_version' => 1,
            'verification_code' => 'verified',
            'staged_at' => now(),
            'verified_at' => now(),
            'activated_at' => now(),
        ]);
        $connection->forceFill(['active_credential_version_id' => $version->id])->save();

        return EmailAccount::query()->create([
            'address' => 'health-deadline@example.test',
            'from_name' => 'Health Deadline',
            'account_kind' => EmailAccount::KIND_SYSTEM,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => ['system'],
            'delete_policy' => 'local_only',
            'provider_integration_id' => $id,
            'provider_credential_source' => 'integration',
            'provider_binding_version' => 1,
            'provider_bound_at' => now(),
        ]);
    }
}
