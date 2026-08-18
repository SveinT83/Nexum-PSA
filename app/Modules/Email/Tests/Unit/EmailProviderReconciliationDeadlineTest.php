<?php

namespace App\Modules\Email\Tests\Unit;

use App\Modules\Email\Services\EmailProviderReconciliationDeadline;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use App\Modules\Email\Support\EmailAccountProviderLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EmailProviderReconciliationDeadlineTest extends TestCase
{
    #[Test]
    public function insufficient_outer_alarm_is_preserved_and_reconciliation_fails_closed(): void
    {
        if (! $this->deadlineAvailable()) {
            $this->markTestSkipped('PCNTL alarms are unavailable in this runtime.');
        }

        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $previousAsync = pcntl_async_signals();
        $previousRemaining = pcntl_alarm(0);
        $handler = static function (): void {};

        try {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, $handler, false);
            pcntl_alarm(4);

            try {
                (new EmailProviderReconciliationDeadline)->run(1, static fn (): bool => true);
                $this->fail('Reconciliation must not steal an existing process alarm.');
            } catch (EmailProviderReconciliationReadException $exception) {
                $this->assertSame('provider_reconciliation_deadline_conflict', $exception->safeCode);
            }

            $remaining = pcntl_alarm(0);
            $this->assertGreaterThanOrEqual(1, $remaining);
            $this->assertLessThanOrEqual(4, $remaining);
            $this->assertSame($handler, pcntl_signal_get_handler(SIGALRM));
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
            if ($previousRemaining > 0) {
                pcntl_alarm($previousRemaining);
            }
        }
    }

    #[Test]
    public function sufficient_outer_alarm_is_composed_restored_and_elapsed_time_is_deducted(): void
    {
        if (! $this->deadlineAvailable()) {
            $this->markTestSkipped('PCNTL alarms are unavailable in this runtime.');
        }

        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $previousAsync = pcntl_async_signals();
        $previousRemaining = pcntl_alarm(0);
        $handler = static function (): void {};

        try {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, $handler, true);
            pcntl_alarm(8);

            $result = (new EmailProviderReconciliationDeadline)->run(1, static function (): string {
                usleep(100_000);

                return 'read-complete';
            });

            $this->assertSame('read-complete', $result);
            $remaining = pcntl_alarm(0);
            $this->assertGreaterThanOrEqual(1, $remaining);
            $this->assertLessThanOrEqual(7, $remaining);
            $this->assertSame($handler, pcntl_signal_get_handler(SIGALRM));
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
            if ($previousRemaining > 0) {
                pcntl_alarm($previousRemaining);
            }
        }
    }

    #[Test]
    public function real_queue_worker_alarm_contains_the_inner_provider_deadline(): void
    {
        if (! $this->deadlineAvailable()) {
            $this->markTestSkipped('PCNTL alarms are unavailable in this runtime.');
        }

        EmailProviderReconciliationDeadlineWorkerProbe::$readCompleted = false;
        EmailProviderReconciliationDeadlineWorkerProbe::$outerAlarmRemaining = 0;
        $queue = app('queue')->connection('sync');
        $payloadMethod = new ReflectionMethod($queue, 'createPayload');
        $payloadMethod->setAccessible(true);
        $payload = $payloadMethod->invoke(
            $queue,
            new EmailProviderReconciliationDeadlineWorkerProbe,
            'email',
            '',
            null,
        );
        $job = new SyncJob(app(), $payload, 'sync', 'email');
        $worker = new EmailProviderReconciliationDeadlineTestWorker(
            app('queue'),
            app('events'),
            app(ExceptionHandler::class),
            static fn (): bool => false,
        );

        $worker->processWithTimeout($job, new WorkerOptions(timeout: 20));

        $this->assertTrue(EmailProviderReconciliationDeadlineWorkerProbe::$readCompleted);
        $this->assertGreaterThan(
            0,
            EmailProviderReconciliationDeadlineWorkerProbe::$outerAlarmRemaining,
            'The Laravel Worker alarm must be restored before the queued handle returns.',
        );
    }

    #[Test]
    public function absolute_deadline_interrupts_connect_and_incremental_read_work(): void
    {
        if (! $this->deadlineAvailable()) {
            $this->markTestSkipped('PCNTL alarms are unavailable in this runtime.');
        }

        $deadline = new EmailProviderReconciliationDeadline;
        $started = microtime(true);
        try {
            $deadline->run(1, static function (): never {
                sleep(10);
                throw new \LogicException('unreachable');
            });
            $this->fail('A hanging connect simulation must be interrupted.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_reconciliation_deadline_exceeded', $exception->safeCode);
        }
        $this->assertLessThan(3.0, microtime(true) - $started);

        $started = microtime(true);
        try {
            $deadline->run(1, static function (): never {
                while (true) {
                    // A provider can drip data before every socket timeout.
                    // The absolute alarm must still end the complete call.
                    usleep(200_000);
                }
            });
            $this->fail('An incremental read simulation must be interrupted.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_reconciliation_deadline_exceeded', $exception->safeCode);
        }
        $this->assertLessThan(3.0, microtime(true) - $started);
    }

    #[Test]
    public function cleanup_deadline_interrupts_disconnect_while_outer_provider_lock_stays_owned(): void
    {
        if (! $this->deadlineAvailable()) {
            $this->markTestSkipped('PCNTL alarms are unavailable in this runtime.');
        }

        $accountId = 8_187_771;
        $lock = EmailAccountProviderLock::acquire($accountId, 10);
        $this->assertNotNull($lock);
        $deadline = new EmailProviderReconciliationDeadline;
        $lockHeldDuringCleanup = false;
        $started = microtime(true);
        try {
            $deadline->run(1, function () use ($accountId, &$lockHeldDuringCleanup): never {
                try {
                    sleep(10);
                } finally {
                    $lockHeldDuringCleanup = EmailAccountProviderLock::acquire($accountId, 1) === null;
                    // Simulate a provider disconnect that also hangs. The
                    // re-armed cleanup alarm must interrupt this wait.
                    sleep(10);
                }
                throw new \LogicException('unreachable');
            });
            $this->fail('A hanging disconnect simulation must be interrupted.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame(
                'provider_reconciliation_cleanup_deadline_exceeded',
                $exception->safeCode,
            );
        } finally {
            $lock->release();
        }

        $this->assertTrue($lockHeldDuringCleanup);
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    private function deadlineAvailable(): bool
    {
        return function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_signal_get_handler')
            && function_exists('pcntl_async_signals')
            && defined('SIGALRM');
    }
}

final class EmailProviderReconciliationDeadlineWorkerProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static bool $readCompleted = false;

    public static int $outerAlarmRemaining = 0;

    public int $timeout = 20;

    public function handle(EmailProviderReconciliationDeadline $deadline): void
    {
        self::$readCompleted = $deadline->run(1, static fn (): bool => true);
        self::$outerAlarmRemaining = pcntl_alarm(0);
        if (self::$outerAlarmRemaining > 0) {
            pcntl_alarm(self::$outerAlarmRemaining);
        }
    }
}

final class EmailProviderReconciliationDeadlineTestWorker extends Worker
{
    public function processWithTimeout(QueueJob $job, WorkerOptions $options): void
    {
        $previousAsync = pcntl_async_signals();
        pcntl_async_signals(true);
        $this->registerTimeoutHandler($job, $options);

        try {
            $this->process('sync', $job, $options);
        } finally {
            $this->resetTimeoutHandler();
            pcntl_async_signals($previousAsync);
        }
    }
}
