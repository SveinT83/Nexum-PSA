<?php

namespace App\Modules\Integration\Exceptions;

use RuntimeException;

class IntegrationHubDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = 'Integration Hub access denied.',
        public readonly int $httpStatus = 403,
        public readonly string $resultStatus = 'denied',
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
