<?php

namespace App\Modules\Storage\Support;

final class SupplierItemResolutionSummary
{
    public function __construct(
        public readonly int $resolved,
        public readonly int $created,
        public readonly int $review,
        public readonly int $ambiguous,
        public readonly int $unresolved,
        public readonly array $reasonCodes,
    ) {}

    public function allResolved(): bool
    {
        return $this->review === 0
            && $this->ambiguous === 0
            && $this->unresolved === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'all_resolved' => $this->allResolved(),
            'resolved' => $this->resolved,
            'created' => $this->created,
            'review' => $this->review,
            'ambiguous' => $this->ambiguous,
            'unresolved' => $this->unresolved,
            'reason_codes' => array_values(array_unique($this->reasonCodes)),
        ];
    }
}
