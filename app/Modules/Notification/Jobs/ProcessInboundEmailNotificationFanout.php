<?php

namespace App\Modules\Notification\Jobs;

use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Advance one payload-free fanout ID; page exceptions never reach failed-job logs. */
class ProcessInboundEmailNotificationFanout implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 5;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    public function __construct(public int $fanoutId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return 'inbound-email-notification-fanout:'.$this->fanoutId;
    }

    public function handle(
        DispatchInboundEmailNotification $fanouts,
        InboundEmailNotificationFanoutReadiness $readiness,
    ): void {
        if (! $readiness->ready()) {
            if ($this->job !== null) {
                $this->release(60);
            }

            return;
        }

        try {
            $fanouts->advance($this->fanoutId);
        } catch (Throwable) {
            // The action owns safe, finite recovery. Never serialize an
            // exception carrying recipient or canonical-notification data.
            return;
        }
    }
}
