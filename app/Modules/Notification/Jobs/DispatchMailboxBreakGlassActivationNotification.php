<?php

namespace App\Modules\Notification\Jobs;

use App\Modules\Notification\Actions\DispatchMailboxBreakGlassActivationNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchMailboxBreakGlassActivationNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [15, 30, 60, 120];

    public function __construct(public readonly int $accessId) {}

    public function handle(DispatchMailboxBreakGlassActivationNotice $notices): void
    {
        $notices->handle($this->accessId);
    }
}
