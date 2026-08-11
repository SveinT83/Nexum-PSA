<?php

namespace App\Modules\Storage\Support;

use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;

final class SupplierOrderProfileMatchResult
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_NONE = 'none';

    /** @param list<int> $candidateProfileIds */
    public function __construct(
        public readonly string $status,
        public readonly ?PurchaseOrderImportProfile $profile = null,
        public readonly ?PurchaseOrderImportProfileVersion $version = null,
        public readonly array $candidateProfileIds = [],
        public readonly ?string $reasonCode = null,
    ) {}

    public function matched(): bool
    {
        return $this->status === self::STATUS_MATCHED
            && $this->profile !== null
            && $this->version !== null;
    }
}
