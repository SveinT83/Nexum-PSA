<?php

namespace App\Modules\Integration\Jobs;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Actions\PushKnowledgeToBookStack;
use App\Modules\Integration\Services\BookStack\BookStackClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes Knowledge records that users explicitly marked for BookStack sync.
 */
class PushPendingKnowledgeToBookStack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function handle(): void
    {
        $integration = Integration::where('type', 'book_stack')->first();
        $config = $integration?->config ?? [];

        if (! $integration || $integration->status !== 'active' || ! ($config['two_way_sync_enabled'] ?? false)) {
            return;
        }

        $tokenId = $integration->getSecret('token_id');
        $tokenSecret = $integration->getSecret('token_secret');

        if (! $integration->server || ! $tokenId || ! $tokenSecret) {
            $integration->forceFill([
                'is_healthy' => false,
                'last_error' => 'BookStack scheduled push is missing server, token id, or token secret.',
            ])->save();
            return;
        }

        $client = new BookStackClient($integration->server, $tokenId, $tokenSecret);

        (new PushKnowledgeToBookStack($integration, $client))->execute();
    }
}
