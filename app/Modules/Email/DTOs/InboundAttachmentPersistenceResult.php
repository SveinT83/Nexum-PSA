<?php

namespace App\Modules\Email\DTOs;

/**
 * Content-free outcome for one inbound attachment persistence pass.
 *
 * Policy rejections are intentional terminal decisions. Read, storage, or
 * metadata failures mean an otherwise eligible provider item was not safely
 * projected and must remain retryable for reconciliation callers.
 */
final readonly class InboundAttachmentPersistenceResult
{
    /**
     * @param  array<int, string>  $failureCodes
     */
    public function __construct(
        public int $persistedCount,
        public int $policyRejectedCount,
        public int $failedCount,
        public array $failureCodes,
        public bool $countLimitReached = false,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failedCount > 0;
    }
}
