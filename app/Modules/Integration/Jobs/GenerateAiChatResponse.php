<?php

namespace App\Modules\Integration\Jobs;

use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Services\AiChatResponder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateAiChatResponse implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $chatId,
        public int $pendingMessageId,
    ) {
    }

    /**
     * Generate the assistant response after the technician sees the message.
     */
    public function handle(AiChatResponder $responder): void
    {
        $chat = AiChat::find($this->chatId);

        if (! $chat) {
            return;
        }

        $responder->respond($chat, $this->pendingMessageId);
    }
}
