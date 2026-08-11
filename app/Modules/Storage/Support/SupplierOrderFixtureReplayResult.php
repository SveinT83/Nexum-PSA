<?php

namespace App\Modules\Storage\Support;

final class SupplierOrderFixtureReplayResult
{
    /** @param list<array<string, mixed>> $results */
    public function __construct(
        public readonly int $total,
        public readonly int $passed,
        public readonly int $failed,
        public readonly int $protectedTotal,
        public readonly int $protectedPassed,
        public readonly array $results,
    ) {}

    public function allPassed(): bool
    {
        return $this->total > 0 && $this->failed === 0;
    }

    public function protectedPassed(): bool
    {
        return $this->protectedTotal > 0
            && $this->protectedPassed === $this->protectedTotal;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'passed' => $this->passed,
            'failed' => $this->failed,
            'protected_total' => $this->protectedTotal,
            'protected_passed' => $this->protectedPassed,
            'all_passed' => $this->allPassed(),
            'all_protected_passed' => $this->protectedPassed(),
            'results' => $this->results,
        ];
    }
}
