<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Actions\ApplyEmailConversationAcknowledgement;
use App\Modules\Email\Models\EmailConversationActionRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessEmailConversationAcknowledgementRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $runId)
    {
        $this->onQueue('default');
    }

    public function handle(ApplyEmailConversationAcknowledgement $apply): void
    {
        $run = EmailConversationActionRun::query()->with('requester')->find($this->runId);
        if (! $run || ! $run->requester || $run->status === EmailConversationActionRun::STATUS_CANCELLED) {
            return;
        }

        $run = $apply->handle($run, $run->requester);
        if ($run->status === EmailConversationActionRun::STATUS_APPLYING) {
            self::dispatch($run->id);
        }
    }
}
