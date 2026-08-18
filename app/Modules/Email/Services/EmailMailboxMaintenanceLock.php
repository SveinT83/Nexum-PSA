<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Support\EmailAccountProviderLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Validation\ValidationException;

class EmailMailboxMaintenanceLock
{
    /**
     * Acquire the exact shared lock used by polling and queued provider work.
     * Synchronous previews fail closed instead of waiting behind an operation
     * whose provider snapshot could change while the request is blocked.
     *
     * @throws ValidationException
     */
    public function acquire(int $accountId, int $seconds = 360): Lock
    {
        $lock = EmailAccountProviderLock::acquire($accountId, $seconds);

        if (! $lock) {
            throw ValidationException::withMessages([
                'mailbox' => 'Another provider mailbox operation is active. Try again after it finishes.',
            ]);
        }

        return $lock;
    }

    public function key(int $accountId): string
    {
        return EmailAccountProviderLock::cacheKey($accountId);
    }
}
