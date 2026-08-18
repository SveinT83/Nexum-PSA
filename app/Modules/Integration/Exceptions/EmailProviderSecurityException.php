<?php

namespace App\Modules\Integration\Exceptions;

use RuntimeException;

class EmailProviderSecurityException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = 'The Email provider configuration is not permitted.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
