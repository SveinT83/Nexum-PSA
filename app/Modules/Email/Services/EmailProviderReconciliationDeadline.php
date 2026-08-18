<?php

namespace App\Modules\Email\Services;

use Closure;

/**
 * Absolute provider-work deadline for one reconciliation reader call.
 *
 * Socket timeouts restart after each response byte and do not cover runtime
 * resolution or cleanup. A process alarm bounds the complete call and re-arms
 * a short cleanup budget so disconnect cannot hang after the main deadline.
 */
final class EmailProviderReconciliationDeadline
{
    public const CLEANUP_GRACE_SECONDS = 2;

    public const OUTER_FINALIZATION_MARGIN_SECONDS = 1;

    public function run(int $requestedSeconds, #[\SensitiveParameter] Closure $callback): mixed
    {
        if (! function_exists('pcntl_alarm')
            || ! function_exists('pcntl_signal')
            || ! function_exists('pcntl_signal_get_handler')
            || ! function_exists('pcntl_async_signals')
            || ! defined('SIGALRM')) {
            throw new EmailProviderReconciliationReadException(
                'provider_reconciliation_deadline_unavailable',
            );
        }

        $seconds = max(1, min(
            EmailProviderReconciliationPolicy::PROVIDER_TIME_CAP_SECONDS,
            $requestedSeconds,
        ));
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $previousAsync = pcntl_async_signals();
        $previousRemaining = pcntl_alarm(0);
        $requiredOuterBudget = $seconds
            + self::CLEANUP_GRACE_SECONDS
            + self::OUTER_FINALIZATION_MARGIN_SECONDS;
        if ($previousRemaining > 0 && $previousRemaining <= $requiredOuterBudget) {
            // Laravel queue workers own an outer SIGALRM for the complete job.
            // Refuse to open a socket when that outer budget cannot contain
            // the bounded read, cleanup, and one finalization margin.
            pcntl_alarm($previousRemaining);

            throw new EmailProviderReconciliationReadException(
                'provider_reconciliation_deadline_conflict',
            );
        }

        $startedAt = hrtime(true);
        $deadlineExpired = false;
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use (&$deadlineExpired): never {
            // restart_syscalls=false is essential: a blocked provider read or
            // disconnect must unwind instead of silently restarting.
            pcntl_alarm(self::CLEANUP_GRACE_SECONDS);
            if ($deadlineExpired) {
                throw new EmailProviderReconciliationReadException(
                    'provider_reconciliation_cleanup_deadline_exceeded',
                );
            }

            $deadlineExpired = true;
            throw new EmailProviderReconciliationReadException(
                'provider_reconciliation_deadline_exceeded',
            );
        }, false);
        pcntl_alarm($seconds);

        try {
            return $callback();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
            if ($previousRemaining > 0) {
                $elapsedSeconds = (int) ceil(max(
                    0,
                    hrtime(true) - $startedAt,
                ) / 1_000_000_000);
                pcntl_alarm(max(1, $previousRemaining - $elapsedSeconds));
            }
        }
    }
}
