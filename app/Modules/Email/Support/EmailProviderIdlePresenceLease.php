<?php

namespace App\Modules\Email\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * A drain-presence lease for a short IDLE wait. It is deliberately separate
 * from the provider-operation lock so IDLE never blocks ordinary reads or
 * writes, while credential pause/cutover can still wait until every provider
 * socket has closed before declaring the runtime drained.
 */
final class EmailProviderIdlePresenceLease
{
    public const CACHE_PREFIX = 'email-provider-idle-presence:';

    public static function acquire(int $accountId, int $seconds = 35): ?Lock
    {
        $lock = Cache::lock(self::key($accountId), max(1, $seconds));

        return $lock->get() ? $lock : null;
    }

    public static function key(int $accountId): string
    {
        return self::CACHE_PREFIX.$accountId;
    }

    /**
     * Readiness probes use the same atomic token-owned lock. Acquiring the
     * probe proves no listener is present; failure is a fail-closed active
     * result. A worker lost without finally is released only by the bounded
     * cache TTL.
     */
    public static function active(int $accountId): bool
    {
        $probe = Cache::lock(self::key($accountId), 1);
        if (! $probe->get()) {
            return true;
        }

        $probe->release();

        return false;
    }
}
