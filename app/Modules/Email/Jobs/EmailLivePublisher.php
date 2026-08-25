<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Services\EmailLivePublisherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EmailLivePublisher implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 15;

    public function __construct(
        private readonly ?int $changeId = null,
    ) {
        $this->queue = config('email_live.queue', 'email-live');
    }

    public function handle(EmailLivePublisherService $service): void
    {
        if ($this->changeId) {
            $change = EmailLiveProjectionChange::find($this->changeId);
            if ($change) {
                $service->publish($change);
            }
        }

        // Every hint job also advances bounded fanout/recovery work. A source
        // change is not complete merely because its first claim succeeded.
        $service->publishPending();
    }
}
