<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class EmailCollaborationPresenceService
{
    public const ACTIVITY_READING = 'reading';

    public const ACTIVITY_TYPING = 'typing';

    public function __construct(
        private readonly EmailSharedDraftAuthorization $authorization,
    ) {}

    /** @return array<string, mixed>|null */
    public function heartbeat(
        User $actor,
        EmailConversation $conversation,
        EmailMailboxPlacement $placement,
        string $activity,
        string $tabToken,
    ): ?array {
        $account = $conversation->account()->firstOrFail();
        $this->authorization->assertSource(
            $actor,
            $account,
            $conversation,
            $placement,
            $activity === self::ACTIVITY_TYPING,
        );

        try {
            $cache = $this->cache();
            $key = $this->scopeKey($conversation, $placement);
            $entries = $this->entries($cache, $key);
            $entryKey = $this->entryKey($actor, $activity, $tabToken);
            $now = now()->timestamp;
            $floor = max(1, (int) config('email_live.presence_heartbeat_floor_seconds', 10));
            $existing = $entries[$entryKey] ?? null;

            if (is_array($existing) && ($now - (int) ($existing['heartbeat_at'] ?? 0)) < $floor) {
                return $existing;
            }

            $entry = [
                'actor_id' => (int) $actor->id,
                'activity' => $activity,
                'tab_hash' => $this->tabHash($tabToken),
                'heartbeat_at' => $now,
                'expires_at' => $now + $this->ttl($activity),
            ];
            $entries[$entryKey] = $entry;
            $entries = $this->removeExpired($entries, $now);
            $cache->put($key, $entries, now()->addSeconds($this->maxTtl() + 5));

            return $entry;
        } catch (\Throwable) {
            // Presence is a hint only. Cache/Redis failure means absent rather
            // than stale or guessed collaborator state.
            return null;
        }
    }

    /** @return list<array{user_id: int, user_name: string, activity: string, expires_at: string}> */
    public function snapshot(
        User $actor,
        EmailConversation $conversation,
        EmailMailboxPlacement $placement,
    ): array {
        $account = $conversation->account()->firstOrFail();
        $this->authorization->assertSource($actor, $account, $conversation, $placement, false);

        try {
            $cache = $this->cache();
            $key = $this->scopeKey($conversation, $placement);
            $entries = $this->removeExpired($this->entries($cache, $key), now()->timestamp);
            $cache->put($key, $entries, now()->addSeconds($this->maxTtl() + 5));

            return collect($entries)
                ->groupBy(fn (array $entry): string => $entry['actor_id'].':'.$entry['activity'])
                ->map(function ($tabs) use ($account, $conversation, $placement): ?array {
                    $entry = $tabs->sortByDesc('expires_at')->first();
                    $collaborator = User::query()->find($entry['actor_id']);

                    if (! $collaborator?->isActive() || $collaborator->isSystemActor()) {
                        return null;
                    }

                    try {
                        $this->authorization->assertSource(
                            $collaborator,
                            $account,
                            $conversation,
                            $placement,
                            $entry['activity'] === self::ACTIVITY_TYPING,
                        );
                    } catch (\Throwable) {
                        return null;
                    }

                    return [
                        'user_id' => (int) $collaborator->id,
                        'user_name' => (string) $collaborator->name,
                        'activity' => (string) $entry['activity'],
                        'expires_at' => now()->setTimestamp((int) $entry['expires_at'])->toIso8601String(),
                    ];
                })
                ->filter()
                ->sortBy([['activity', 'desc'], ['user_name', 'asc']])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function leave(
        User $actor,
        EmailConversation $conversation,
        EmailMailboxPlacement $placement,
        string $activity,
        string $tabToken,
    ): void {
        $account = $conversation->account()->firstOrFail();
        $this->authorization->assertSource(
            $actor,
            $account,
            $conversation,
            $placement,
            $activity === self::ACTIVITY_TYPING,
        );

        try {
            $cache = $this->cache();
            $key = $this->scopeKey($conversation, $placement);
            $entries = $this->entries($cache, $key);
            unset($entries[$this->entryKey($actor, $activity, $tabToken)]);
            $entries = $this->removeExpired($entries, now()->timestamp);

            if ($entries === []) {
                $cache->forget($key);
            } else {
                $cache->put($key, $entries, now()->addSeconds($this->maxTtl() + 5));
            }
        } catch (\Throwable) {
            // TTL remains the correctness cleanup when best-effort leave fails.
        }
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('email_live.presence_store', 'redis'));
    }

    private function scopeKey(EmailConversation $conversation, EmailMailboxPlacement $placement): string
    {
        $scope = implode('|', [
            (int) $conversation->account_id,
            (int) $conversation->id,
            (int) $placement->id,
        ]);

        return 'email-collaboration-presence:v1:'.hash_hmac('sha256', $scope, (string) config('app.key'));
    }

    private function entryKey(User $actor, string $activity, string $tabToken): string
    {
        return hash('sha256', $actor->id.'|'.$activity.'|'.$this->tabHash($tabToken));
    }

    private function tabHash(string $tabToken): string
    {
        return hash_hmac('sha256', trim($tabToken), (string) config('app.key'));
    }

    /** @return array<string, array<string, mixed>> */
    private function entries(Repository $cache, string $key): array
    {
        $entries = $cache->get($key, []);

        return is_array($entries) ? $entries : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function removeExpired(array $entries, int $now): array
    {
        return array_filter(
            $entries,
            fn (array $entry): bool => (int) ($entry['expires_at'] ?? 0) > $now,
        );
    }

    private function ttl(string $activity): int
    {
        return $activity === self::ACTIVITY_TYPING
            ? max(5, (int) config('email_live.presence_typing_ttl_seconds', 25))
            : max(5, (int) config('email_live.presence_reading_ttl_seconds', 45));
    }

    private function maxTtl(): int
    {
        return max($this->ttl(self::ACTIVITY_READING), $this->ttl(self::ACTIVITY_TYPING));
    }
}
