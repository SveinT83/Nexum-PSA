<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Services\EmailCanonicalCorrelationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessEmailCanonicalCorrelationRun implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-canonical-correlation:'.$this->runId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('email-canonical-correlation:'.$this->runId))
                ->releaseAfter(5)
                ->expireAfter(300),
        ];
    }

    public function handle(EmailCanonicalCorrelationRunner $runner): void
    {
        if ($runner->processBatch($this->runId)) {
            self::dispatch($this->runId)->onQueue('email');
        }
    }
}
