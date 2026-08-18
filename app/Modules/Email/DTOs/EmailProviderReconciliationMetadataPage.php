<?php

namespace App\Modules\Email\DTOs;

use InvalidArgumentException;

final readonly class EmailProviderReconciliationMetadataPage
{
    /** @var array<int, EmailProviderReconciliationMessageMetadata> */
    public array $messages;

    /**
     * @param  array<int, EmailProviderReconciliationMessageMetadata>  $messages
     */
    public function __construct(
        array $messages,
        public bool $terminal = false,
        public ?int $completeThroughUid = null,
    ) {
        foreach ($messages as $message) {
            if (! $message instanceof EmailProviderReconciliationMessageMetadata) {
                throw new InvalidArgumentException('Provider metadata pages require typed messages.');
            }
        }

        if ($completeThroughUid !== null && $completeThroughUid < 0) {
            throw new InvalidArgumentException(
                'A provider-confirmed UID window boundary cannot be negative.',
            );
        }

        if ($terminal && $messages !== []) {
            throw new InvalidArgumentException(
                'Only an explicit empty provider page may close a frozen UID inventory.',
            );
        }

        $this->messages = array_values($messages);
    }
}
