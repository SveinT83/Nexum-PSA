<?php

namespace App\Modules\Intake\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/** Browser and API share a reentrant distributed lock; provider calls never hold DB transactions. */
class IntakeWorkflowLock
{
    private static int $depth = 0;

    public static function run(Closure $action): mixed
    {
        if (self::$depth > 0) {
            return $action();
        }
        try {
            return Cache::lock('intake:workflow:v1', 600)->block(10, function () use ($action) {
                self::$depth++;
                try {
                    return $action();
                } finally {
                    self::$depth--;
                }
            });
        } catch (LockTimeoutException) {
            abort(409, 'Another Intake operation is running. Reload before retrying.');
        }
    }
}
