<?php

namespace App\Modules\Storage\Support;

final class SupplierOrderExtractionResult
{
    /**
     * @param  array<string, mixed>|null  $document
     * @param  list<array{code: string, path: string, message: string}>  $errors
     * @param  list<array{code: string, path: string, message: string}>  $warnings
     */
    public function __construct(
        public readonly ?array $document,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly SupplierOrderNormalizedDocument $normalized,
        public readonly string $definitionChecksum,
    ) {}

    public function valid(): bool
    {
        return $this->document !== null && $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid(),
            'document' => $this->document,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'definition_checksum' => $this->definitionChecksum,
            'normalized' => $this->normalized->toArray(),
        ];
    }
}
