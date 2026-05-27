<?php

namespace App\Modules\Integration\Jobs;

use App\Modules\Integration\Services\AiChatCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupAiChats implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Run AI chat retention cleanup in the queue.
     */
    public function handle(AiChatCleanup $cleanup): void
    {
        $cleanup->run();
    }
}
