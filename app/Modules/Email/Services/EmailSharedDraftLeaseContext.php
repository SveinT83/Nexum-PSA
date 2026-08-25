<?php

namespace App\Modules\Email\Services;

final readonly class EmailSharedDraftLeaseContext
{
    public function __construct(
        public string $leaseToken,
        public int $fencingToken,
        public int $contentVersion,
        public string $sourceVersion,
    ) {}
}
