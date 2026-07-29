<?php

namespace App\Modules\Integration\Support;

final class AiExecutionTrace
{
    private int $attemptNumber = 0;

    public function __construct(public readonly AiExecutionContext $context) {}

    /**
     * Allocate the next ordered provider attempt for the logical execution.
     */
    public function nextAttemptNumber(): int
    {
        return ++$this->attemptNumber;
    }
}
