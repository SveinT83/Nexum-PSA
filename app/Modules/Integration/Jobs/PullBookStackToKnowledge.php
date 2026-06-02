<?php

namespace App\Modules\Integration\Jobs;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Actions\SyncBookStackToKnowledge;
use App\Modules\Integration\Services\BookStack\BookStackClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Periodically pulls BookStack content into Knowledge without requiring a user
 * to open the admin integration screen and click Sync Now.
 */
class PullBookStackToKnowledge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    /**
     * Prevent long BookStack pulls from overlapping when the scheduler ticks.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('book-stack-knowledge-pull'))->expireAfter(600),
        ];
    }

    public function handle(): void
    {
        $integration = Integration::where('type', 'book_stack')->first();
        $config = $integration?->config ?? [];

        if (! $integration || $integration->status !== 'active') {
            return;
        }

        if (! $this->isDue($config)) {
            return;
        }

        $tokenId = $integration->getSecret('token_id');
        $tokenSecret = $integration->getSecret('token_secret');
        $actor = $this->syncActor();

        if (! $integration->server || ! $tokenId || ! $tokenSecret || ! $actor) {
            $this->markMisconfigured($integration, ! $actor
                ? 'BookStack scheduled pull could not find an active sync actor.'
                : 'BookStack scheduled pull is missing server, token id, or token secret.');
            return;
        }

        $client = new BookStackClient($integration->server, $tokenId, $tokenSecret);

        (new SyncBookStackToKnowledge($integration, $client, $actor))->execute();

        $config = $integration->fresh()->config ?? [];
        $config['last_pull_at'] = now()->toIso8601String();
        $integration->forceFill(['config' => $config])->save();
    }

    /**
     * Use the configured interval, defaulting to hourly documentation pulls.
     */
    private function isDue(array $config): bool
    {
        $interval = max(1, (int) ($config['sync_interval_minutes'] ?? 60));
        $lastPullAt = $config['last_pull_at'] ?? null;

        if (! $lastPullAt) {
            return true;
        }

        return Carbon::parse($lastPullAt)->addMinutes($interval)->isPast();
    }

    /**
     * Imported BookStack pages need an owner/creator. Prefer active Admin users
     * and fall back to any active user if the role has not been seeded yet.
     */
    private function syncActor(): ?User
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->role('Admin')
            ->orderBy('id')
            ->first()
            ?? User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->orderBy('id')
                ->first();
    }

    private function markMisconfigured(Integration $integration, string $message): void
    {
        $integration->forceFill([
            'is_healthy' => false,
            'last_error' => $message,
        ])->save();
    }
}
