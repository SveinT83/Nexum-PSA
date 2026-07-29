<?php

namespace App\Modules\Integration\Support;

final readonly class AiPolicyDecision
{
    public function __construct(
        public bool $allowed,
        public string $reasonCode,
        public array $effectiveLimits = [],
    ) {}

    public static function allow(array $limits = []): self
    {
        return new self(true, 'allowed', $limits);
    }

    public static function deny(string $reasonCode): self
    {
        return new self(false, $reasonCode);
    }
}
