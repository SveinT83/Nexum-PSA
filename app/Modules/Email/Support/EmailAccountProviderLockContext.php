<?php

namespace App\Modules\Email\Support;

/**
 * Process-local proof for a nested synchronous operation.
 *
 * FetchImapAccount already owns the account provider lease through queue
 * middleware. A synchronous StoreInboundMessage child must reuse that lease
 * instead of deadlocking while trying to acquire the same non-reentrant cache
 * lock. This proof never enters a serialized job payload, so an async/legacy
 * job cannot claim it owns a lease.
 */
final class EmailAccountProviderLockContext
{
    /** @var array<int, int> */
    private static array $depth = [];

    public static function held(int $accountId): bool
    {
        return (self::$depth[$accountId] ?? 0) > 0;
    }

    public static function withinHeld(int $accountId, #[\SensitiveParameter] \Closure $callback): mixed
    {
        self::$depth[$accountId] = (self::$depth[$accountId] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            self::$depth[$accountId]--;
            if (self::$depth[$accountId] < 1) {
                unset(self::$depth[$accountId]);
            }
        }
    }
}
