<?php

namespace App\Modules\Email\DTOs;

use InvalidArgumentException;

final readonly class EmailProviderReconciliationBindingSnapshot
{
    public function __construct(
        public int $bindingVersion,
        public int $configurationVersion,
        public int $credentialVersion,
        public string $runtimeFingerprint,
    ) {
        if ($bindingVersion < 1 || $configurationVersion < 1 || $credentialVersion < 0) {
            throw new InvalidArgumentException('Provider reconciliation binding versions are invalid.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $runtimeFingerprint)) {
            throw new InvalidArgumentException('Provider reconciliation runtime evidence is invalid.');
        }
    }
}
