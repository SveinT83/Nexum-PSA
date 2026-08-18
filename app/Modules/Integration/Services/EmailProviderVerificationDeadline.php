<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;

final class EmailProviderVerificationDeadline
{
    /**
     * Bound DNS resolution and both authenticated provider probes with one
     * absolute wall-clock alarm. If this runtime cannot interrupt blocking I/O
     * safely, verification is unavailable rather than silently unbounded.
     */
    public function run(#[\SensitiveParameter] \Closure $callback): mixed
    {
        if (! function_exists('pcntl_alarm')
            || ! function_exists('pcntl_signal')
            || ! function_exists('pcntl_signal_get_handler')
            || ! function_exists('pcntl_async_signals')
            || ! defined('SIGALRM')) {
            throw new EmailProviderSecurityException('provider_verification_deadline_unavailable');
        }

        $seconds = max(
            2,
            min(120, (int) config('email_provider_security.verification_deadline_seconds', 60)),
        );
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $previousAsync = pcntl_async_signals();
        $startedAt = hrtime(true);
        $previousRemaining = pcntl_alarm(0);

        $cleanupSeconds = max(
            1,
            min(5, (int) config('email_provider_security.verification_cleanup_grace_seconds', 2)),
        );
        $outerAlarmMargin = max(
            1,
            min(30, (int) config('email_provider_security.verification_outer_alarm_margin_seconds', 10)),
        );

        if ($previousRemaining > 0
            && $previousRemaining <= $seconds + $cleanupSeconds + $outerAlarmMargin) {
            // A worker/request deadline may own SIGALRM already. Only compose
            // the provider deadline when the outer owner has enough remaining
            // time for the probe, bounded cleanup, and final persistence.
            pcntl_alarm($previousRemaining);

            throw new EmailProviderSecurityException('provider_verification_deadline_conflict');
        }

        $deadlineExpired = false;
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use (&$deadlineExpired, $cleanupSeconds): never {
            // The first alarm starts a separate, short cleanup budget before
            // unwinding the provider probe. If graceful disconnect/stop also
            // blocks, the re-armed alarm interrupts it. Re-arm on every signal
            // so a library cannot regain an unbounded wait by swallowing one.
            pcntl_alarm($cleanupSeconds);

            if ($deadlineExpired) {
                throw new EmailProviderSecurityException('provider_verification_cleanup_deadline_exceeded');
            }

            $deadlineExpired = true;
            throw new EmailProviderSecurityException('provider_verification_deadline_exceeded');
        }, false);
        pcntl_alarm($seconds);

        try {
            return $callback();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);

            if ($previousRemaining > 0) {
                // Monotonic elapsed time is rounded up so nesting can never
                // extend the worker/request budget it temporarily replaced.
                $elapsedSeconds = max(1, (int) ceil((hrtime(true) - $startedAt) / 1_000_000_000));
                pcntl_alarm(max(1, $previousRemaining - $elapsedSeconds));
            }
        }
    }
}
