<?php

namespace App\Modules\Integration\Exceptions;

use RuntimeException;

/** Only reviewed field diagnostics may escape; raw bodies, headers and HTTP exceptions remain private. */
final class TripletexException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $status = null, public readonly array $fieldErrors = [])
    {
        parent::__construct('Tripletex: '.str_replace('_', ' ', $reason).'.');
    }
}
