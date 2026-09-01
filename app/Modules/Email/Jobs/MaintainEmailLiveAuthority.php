<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Services\EmailLiveAccessRecomputeService;
use App\Modules\Email\Services\EmailLiveAuthorityBoundaryService;
use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use App\Modules\Email\Services\EmailLivePublisherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Recovers durable authority and publisher work missed between commit and dispatch. */
class MaintainEmailLiveAuthority implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct()
    {
        $this->onQueue((string) config('email_live.queue', 'email-live'));
    }

    public function handle(
        EmailLiveAccessRecomputeService $access,
        EmailLiveAuthorityBoundaryService $boundaries,
        EmailLiveAuthorityCoordinator $authority,
        EmailLivePublisherService $publisher,
    ): void {
        $boundaries->processDue();
        $authority->reconcileGlobalDrift();
        $access->processPending();
        $publisher->publishPending();
    }
}
