<?php

namespace App\Modules\Email\DTOs;

use App\Modules\Email\Models\EmailMailboxPlacement;
use InvalidArgumentException;

/**
 * Result of the reconciliation-only, create-if-missing placement seam.
 *
 * A durable pending marker distinguishes a row created by an interrupted
 * reconciliation Store from an unrelated placement that must stay immutable.
 */
final readonly class EmailPlacementCreateResult
{
    public const CREATED_PENDING = 'created_pending';

    public const RESUMED_PENDING = 'resumed_pending';

    public const PREEXISTING = 'preexisting';

    public function __construct(
        public EmailMailboxPlacement $placement,
        public string $disposition,
    ) {
        if (! in_array($disposition, self::dispositions(), true)) {
            throw new InvalidArgumentException('Email placement create disposition is invalid.');
        }
    }

    /** @return list<string> */
    public static function dispositions(): array
    {
        return [
            self::CREATED_PENDING,
            self::RESUMED_PENDING,
            self::PREEXISTING,
        ];
    }

    public function reconciliationPending(): bool
    {
        return $this->disposition !== self::PREEXISTING;
    }
}
