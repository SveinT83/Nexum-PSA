<?php

namespace App\Modules\DataExchange\Support;

class DataExchangeFieldDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'string',
        public bool $exportable = true,
        public bool $importable = false,
        public bool $sensitive = false,
        public bool $blocked = false,
        public ?string $relation = null,
    ) {}

    public function blockedCopy(): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            type: $this->type,
            exportable: false,
            importable: false,
            sensitive: true,
            blocked: true,
            relation: $this->relation,
        );
    }
}
