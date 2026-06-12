<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\InboundEmailSignalClassifier;
use App\Modules\Email\Services\InboundEmailRuleEngine;
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

    public function handle(InboundEmailRuleEngine $ruleEngine, InboundEmailSignalClassifier $classifier): void
    {
        $message = EmailMessage::find($this->emailMessageId);
        if (! $message || $message->ticket_id !== null) {
            return;
        }

        $signal = $classifier->classifyAndRecord($message);

        if ($classifier->shouldStopTicketRouting($signal)) {
            $message->forceFill(['state' => 'archived'])->save();
            return;
        }

        $ruleEngine->process($message);
    }
}
