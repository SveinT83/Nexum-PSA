<?php

namespace App\Modules\Integration\Support;

final readonly class AiPrivacyGatewayResult
{
    public function __construct(
        public array $payload,
        public int $redactionCount,
        public array $removedFields,
        public string $fingerprint,
    ) {}
}
