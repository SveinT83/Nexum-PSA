<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderTransportFactory;
use App\Modules\Integration\Services\EmailProviderVerificationDeadline;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class EmailTestService
{
    private const DEADLINE_REASONS = [
        'provider_verification_deadline_unavailable',
        'provider_verification_deadline_conflict',
        'provider_verification_deadline_exceeded',
        'provider_verification_cleanup_deadline_exceeded',
    ];

    public function __construct(
        private readonly EmailAccountProviderRuntimeResolver $runtimeResolver,
        private readonly EmailProviderTransportFactory $transports,
        private readonly EmailProviderVerificationDeadline $deadline,
    ) {}

    public function run(
        #[\SensitiveParameter] EmailAccount $account,
        #[\SensitiveParameter] ?int $expectedBindingVersion = null,
    ): EmailTestResult
    {
        $providerLock = EmailAccountProviderLock::acquire((int) $account->id, $this->lockSeconds());

        if (! $providerLock) {
            throw new EmailProviderSecurityException('provider_work_locked');
        }

        try {
            return $this->runLocked($account, $expectedBindingVersion);
        } finally {
            $providerLock->release();
        }
    }

    private function runLocked(
        #[\SensitiveParameter] EmailAccount $account,
        #[\SensitiveParameter] ?int $expectedBindingVersion,
    ): EmailTestResult
    {
        $res = new EmailTestResult();
        $expectedBindingVersion ??= $this->runtimeResolver
            ->captureBindingVersion($account);

        try {
            $this->deadline->run(function () use ($account, $expectedBindingVersion, $res): void {
                $absoluteDeadline = microtime(true) + $this->deadlineSeconds();
                try {
                    $runtime = $this->runtimeResolver->resolve($account, $expectedBindingVersion);
                } catch (\Throwable $exception) {
                    $this->rethrowDeadline($exception);
                    $res->imap_error_code = 'PROVIDER_RUNTIME_UNAVAILABLE';
                    $res->imap_error_message = 'The mailbox provider runtime is unavailable.';
                    $res->smtp_error_code = 'PROVIDER_RUNTIME_UNAVAILABLE';
                    $res->smtp_error_message = 'The mailbox provider runtime is unavailable.';
                    Log::warning('Email provider runtime test failed', [
                        'account_id' => $account->id,
                        'code' => 'PROVIDER_RUNTIME_UNAVAILABLE',
                        'exception' => $exception::class,
                    ]);

                    return;
                }

                // IMAP test
                $t0 = microtime(true);
                $client = null;
                try {
                    $client = $this->transports->makeImap(
                        $runtime,
                        $this->remainingTimeout($absoluteDeadline),
                    );
                    $client->connect();

                    // An authenticated connection is the complete bounded probe.
                    $res->imap_ok = true;
                } catch (\Throwable $exception) {
                    $this->rethrowDeadline($exception);
                    [$code, $msg] = $this->imapErrorClassify($exception);
                    $res->imap_error_code = $code;
                    $res->imap_error_message = $msg;
                    Log::warning('IMAP test failed', [
                        'account_id' => $account->id,
                        'code' => $code,
                        'exception' => $exception::class,
                    ]);
                } finally {
                    if ($client !== null) {
                        try {
                            $client->disconnect();
                        } catch (\Throwable $exception) {
                            $this->rethrowDeadline($exception);
                        }
                    }

                    $res->imap_ms = (microtime(true) - $t0) * 1000.0;
                }

                // SMTP test, sharing the exact same absolute deadline.
                $t1 = microtime(true);
                $transport = null;
                try {
                    $transport = $this->transports->makeSmtp(
                        $runtime,
                        $this->remainingTimeout($absoluteDeadline),
                    );
                    $transport->start();
                    $res->smtp_ok = true;
                } catch (\Throwable $exception) {
                    $this->rethrowDeadline($exception);
                    [$code, $msg] = $this->smtpErrorClassify($exception);
                    $res->smtp_error_code = $code;
                    $res->smtp_error_message = $msg;
                    Log::warning('SMTP test failed', [
                        'account_id' => $account->id,
                        'code' => $code,
                        'exception' => $exception::class,
                    ]);
                } finally {
                    if ($transport !== null) {
                        try {
                            $transport->stop();
                        } catch (\Throwable $exception) {
                            $this->rethrowDeadline($exception);
                        }
                    }

                    $res->smtp_ms = (microtime(true) - $t1) * 1000.0;
                }
            });
        } catch (EmailProviderSecurityException $exception) {
            if (! $this->isDeadline($exception)) {
                throw $exception;
            }

            $code = strtoupper($exception->reasonCode);
            $message = 'The provider check was blocked by its absolute safety deadline.';
            if (! $res->imap_ok && $res->imap_error_code === null) {
                $res->imap_error_code = $code;
                $res->imap_error_message = $message;
            }
            if (! $res->smtp_ok && $res->smtp_error_code === null) {
                $res->smtp_error_code = $code;
                $res->smtp_error_message = $message;
            }
            Log::warning('Email provider test was blocked by its safety deadline.', [
                'account_id' => $account->id,
                'code' => $code,
                'exception' => $exception::class,
            ]);
        }

        $this->persistResult($account, $res);

        return $res;
    }

    private function persistResult(
        #[\SensitiveParameter] EmailAccount $account,
        EmailTestResult $res,
    ): void {
        // Persist health
        $account->last_test_at = Carbon::now();
        $account->last_test_result = $res->overall();
        $account->last_error_code = null;
        $account->last_error_message = null;
        if (! $res->imap_ok || ! $res->smtp_ok) {
            // choose worst error
            if (! $res->imap_ok) {
                $account->last_error_code = $res->imap_error_code;
                $account->last_error_message = $res->imap_error_message;
            } else {
                $account->last_error_code = $res->smtp_error_code;
                $account->last_error_message = $res->smtp_error_message;
            }
        }
        if ($res->imap_ok) {
            $account->last_successful_fetch_at = Carbon::now();
        }
        if ($res->smtp_ok) {
            $account->last_successful_send_at = Carbon::now();
        }
        $account->save();
    }

    private function rethrowDeadline(#[\SensitiveParameter] \Throwable $exception): void
    {
        $cause = $exception;

        for ($depth = 0; $depth < 8 && $cause !== null; $depth++) {
            if ($cause instanceof EmailProviderSecurityException && $this->isDeadline($cause)) {
                // Provider libraries may wrap the SIGALRM exception together
                // with endpoint or response text. Recreate only the stable
                // deadline reason and sever the complete provider chain.
                throw new EmailProviderSecurityException($cause->reasonCode);
            }

            $cause = $cause->getPrevious();
        }
    }

    private function isDeadline(EmailProviderSecurityException $exception): bool
    {
        return in_array($exception->reasonCode, self::DEADLINE_REASONS, true);
    }

    private function remainingTimeout(float $deadline): int
    {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new EmailProviderSecurityException('provider_verification_deadline_exceeded');
        }

        return min(
            $remaining,
            max(1, min(60, (int) config('email_provider_security.connection_timeout_seconds', 20))),
        );
    }

    private function deadlineSeconds(): int
    {
        return max(
            2,
            min(120, (int) config('email_provider_security.verification_deadline_seconds', 60)),
        );
    }

    private function lockSeconds(): int
    {
        $cleanup = max(
            1,
            min(5, (int) config('email_provider_security.verification_cleanup_grace_seconds', 2)),
        );

        return max(180, $this->deadlineSeconds() + $cleanup + 60);
    }

    private function imapErrorClassify(#[\SensitiveParameter] \Throwable $e): array
    {
        $m = strtolower($e->getMessage());
        if (str_contains($m, 'auth')) return ['IMAP_AUTH', 'Authentication failed'];
        if (str_contains($m, 'connect') && str_contains($m, 'timeout')) return ['IMAP_CONNECT', 'Connection timed out'];
        if (str_contains($m, 'connect') && str_contains($m, 'refused')) return ['IMAP_CONNECT', 'Connection refused'];
        if (str_contains($m, 'tls') || str_contains($m, 'ssl')) return ['IMAP_TLS', 'TLS/SSL negotiation failed'];
        return ['IMAP_ERROR', 'The IMAP provider check failed.'];
    }

    private function smtpErrorClassify(#[\SensitiveParameter] \Throwable $e): array
    {
        $m = strtolower($e->getMessage());
        if (str_contains($m, 'auth')) return ['SMTP_AUTH', 'Authentication failed'];
        if (str_contains($m, 'connect') && str_contains($m, 'timeout')) return ['SMTP_CONNECT', 'Connection timed out'];
        if (str_contains($m, 'connect') && str_contains($m, 'refused')) return ['SMTP_CONNECT', 'Connection refused'];
        if (str_contains($m, 'tls') || str_contains($m, 'ssl') || str_contains($m, 'starttls')) return ['SMTP_TLS', 'TLS/SSL negotiation failed'];
        return ['SMTP_ERROR', 'The SMTP provider check failed.'];
    }
}
