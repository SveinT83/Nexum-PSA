<?php

namespace App\Modules\Integration\Exceptions;

use RuntimeException;

class AiPolicyDeniedException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('AI policy denied: '.$reasonCode);
    }
}
