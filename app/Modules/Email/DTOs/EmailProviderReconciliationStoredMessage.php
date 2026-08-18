<?php

namespace App\Modules\Email\DTOs;

use InvalidArgumentException;

final readonly class EmailProviderReconciliationStoredMessage
{
    public function __construct(
        public int $messageId,
        public int $placementId,
        public ?string $identityHash,
        public string $placementDisposition,
        public int $placementSyncVersion,
    ) {
        if ($messageId < 1 || $placementId < 1) {
            throw new InvalidArgumentException('Stored reconciliation identity is invalid.');
        }

        if ($identityHash !== null && ! preg_match('/^[a-f0-9]{64}$/', $identityHash)) {
            throw new InvalidArgumentException('Stored reconciliation evidence hash is invalid.');
        }

        if (! in_array($placementDisposition, EmailPlacementCreateResult::dispositions(), true)
            || $placementSyncVersion < 1) {
            throw new InvalidArgumentException('Stored reconciliation placement disposition is invalid.');
        }
    }

    public function reconciliationPlacementPending(): bool
    {
        return $this->placementDisposition !== EmailPlacementCreateResult::PREEXISTING;
    }
}
