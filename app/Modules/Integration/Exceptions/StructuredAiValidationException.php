<?php

namespace App\Modules\Integration\Exceptions;

use RuntimeException;

class StructuredAiValidationException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
