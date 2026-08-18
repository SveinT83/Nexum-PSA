<?php

namespace App\Modules\Email\Contracts;

/**
 * Optional short-lived latency hint. Correctness never depends on a hint.
 */
interface EmailProviderIdleHintReader
{
    public function waitForOpaqueHint(
        int $accountId,
        int $expectedBindingVersion,
        int $maxSeconds,
    ): bool;
}
