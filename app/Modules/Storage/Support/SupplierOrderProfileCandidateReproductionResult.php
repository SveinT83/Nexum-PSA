<?php

namespace App\Modules\Storage\Support;

final class SupplierOrderProfileCandidateReproductionResult
{
    public function __construct(
        public readonly int $currentSamples,
        public readonly int $protectedFixtureSamples,
        public readonly int $historicalSamples,
    ) {}

    public function historicalMinimumMet(int $minimum): bool
    {
        return $this->historicalSamples >= max(1, $minimum);
    }

    public function bootstrapMinimumMet(int $minimum): bool
    {
        return ($this->currentSamples + $this->historicalSamples) >= max(1, $minimum);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'current_samples' => $this->currentSamples,
            'protected_fixture_samples' => $this->protectedFixtureSamples,
            'historical_samples' => $this->historicalSamples,
        ];
    }
}
