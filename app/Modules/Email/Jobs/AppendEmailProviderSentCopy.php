<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Services\EmailSentReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AppendEmailProviderSentCopy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $emailSentReconciliationId,
    ) {}

    public function handle(EmailSentReconciliationService $service): void
    {
        $reconciliation = EmailSentReconciliation::query()
            ->find($this->emailSentReconciliationId);

        if (! $reconciliation) {
            return;
        }

        // The service owns the exact binding, duplicate-write, raw-snapshot,
        // provider-lock, and ambiguous-result guards. This job never retries an
        // uncertain provider write automatically.
        $service->appendProviderSentCopy($reconciliation);
    }
}
