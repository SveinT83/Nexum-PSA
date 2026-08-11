<?php

namespace App\Modules\Storage\Support;

final class SupplierOrderPolicyDecision
{
    public const NEEDS_ATTENTION = 'needs_attention';

    public const SHADOW_COMPLETE = 'shadow_complete';

    public const CREATE_DRAFT = 'create_draft';

    public const REGISTER_ORDERED = 'register_ordered';

    /** @param list<string> $reasonCodes */
    public function __construct(
        public readonly string $outcome,
        public readonly array $reasonCodes,
        public readonly array $facts,
    ) {}

    public function permitsPurchaseOrderWrite(): bool
    {
        return in_array($this->outcome, [self::CREATE_DRAFT, self::REGISTER_ORDERED], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'reason_codes' => $this->reasonCodes,
            'facts' => $this->facts,
        ];
    }
}
