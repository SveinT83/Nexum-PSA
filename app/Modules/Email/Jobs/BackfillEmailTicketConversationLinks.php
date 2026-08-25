<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Services\EmailTicketConversationLinkMigrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class BackfillEmailTicketConversationLinks implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(
        private readonly int $runId,
        private readonly int $actorId,
    ) {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-ticket-link-migration:'.$this->runId;
    }

    public function handle(EmailTicketConversationLinkMigrator $migrator): void
    {
        if ($migrator->processBatch($this->runId, $this->actorId)) {
            try {
                $this->dispatchContinuation();
            } catch (Throwable $exception) {
                if ($migrator->markContinuationDispatchFailed($this->runId)) {
                    throw new RuntimeException(
                        'email_ticket_link_migration_continuation_dispatch_failed',
                        0,
                        $exception,
                    );
                }

                // A synchronous continuation may already have recorded a more
                // precise terminal result. Preserve that result and exception.
                throw $exception;
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(EmailTicketConversationLinkMigrator::class)->markWorkerFailed($this->runId);
        } catch (Throwable) {
            // Queue failure hooks cannot repair a ledger while its database is
            // unavailable. Never replace the original worker failure here.
        }
    }

    protected function dispatchContinuation(): void
    {
        self::dispatch($this->runId, $this->actorId);
    }
}
