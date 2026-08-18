<?php

namespace App\Modules\Email\DTOs;

use InvalidArgumentException;

final readonly class EmailProviderReconciliationFolderState
{
    public function __construct(
        public int $uidValidity,
        public int $uidNext,
        public int $existsCount,
        public bool $supportsModseq,
        public ?int $highestModseq,
    ) {
        if ($uidValidity < 1 || $uidNext < 1 || $existsCount < 0) {
            throw new InvalidArgumentException('Provider folder state is incomplete.');
        }

        if ($supportsModseq && ($highestModseq === null || $highestModseq < 0)) {
            throw new InvalidArgumentException('CONDSTORE state is missing HIGHESTMODSEQ.');
        }

        if (! $supportsModseq && $highestModseq !== null) {
            throw new InvalidArgumentException('HIGHESTMODSEQ requires advertised CONDSTORE support.');
        }
    }

    public function scanThroughUid(): int
    {
        return max(0, $this->uidNext - 1);
    }

    public function stableWith(self $other): bool
    {
        if ($this->uidValidity !== $other->uidValidity
            || $this->uidNext !== $other->uidNext
            || $this->existsCount !== $other->existsCount
            || $this->supportsModseq !== $other->supportsModseq) {
            return false;
        }

        return ! $this->supportsModseq
            || $this->highestModseq === $other->highestModseq;
    }

    /** @return array<string, int|bool|null> */
    public function facts(): array
    {
        return [
            'uid_validity' => $this->uidValidity,
            'uid_next' => $this->uidNext,
            'exists_count' => $this->existsCount,
            'supports_modseq' => $this->supportsModseq,
            'highest_modseq' => $this->supportsModseq ? $this->highestModseq : null,
        ];
    }
}
