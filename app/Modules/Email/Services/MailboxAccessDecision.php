<?php

namespace App\Modules\Email\Services;

use Carbon\CarbonImmutable;

final readonly class MailboxAccessDecision
{
    public const SOURCE_DENIED = 'denied';

    public const SOURCE_OWNER = 'owner';

    public const SOURCE_GRANT = 'grant';

    public const SOURCE_DELEGATION = 'delegation';

    public const SOURCE_BREAK_GLASS = 'break_glass';

    public function __construct(
        public bool $allowed,
        public string $operation,
        public string $source,
        public int $accountId,
        public int $actorId,
        public ?int $delegationId = null,
        public ?int $breakGlassAccessId = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $denialReason = null,
        public ?int $expiredDelegationId = null,
        public ?int $expiredBreakGlassAccessId = null,
    ) {}

    public function usesBreakGlass(): bool
    {
        return $this->allowed && $this->source === self::SOURCE_BREAK_GLASS;
    }
}
