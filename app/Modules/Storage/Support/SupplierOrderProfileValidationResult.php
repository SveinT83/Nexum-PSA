<?php

namespace App\Modules\Storage\Support;

final class SupplierOrderProfileValidationResult
{
    /**
     * @param  list<array{code: string, path: string, message: string}>  $errors
     * @param  list<array{code: string, path: string, message: string}>  $warnings
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings = [],
    ) {}

    public function valid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
