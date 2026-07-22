<?php

namespace App\Modules\Integration\Exceptions;

use RuntimeException;

class CloudFactoryApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
