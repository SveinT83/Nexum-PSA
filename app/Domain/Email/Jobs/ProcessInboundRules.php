<?php

namespace App\Domain\Email\Jobs;

use App\Domain\Email\Models\EmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInboundRules implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public int $emailMessageId) {}

    public function handle(): void
    {
        $message = EmailMessage::find($this->emailMessageId);
        if (!$message) {
            return;
        }
        // TODO: Evaluate rules (OnInbound) and route message (Tickets or leave in Inbox).
    }
}
