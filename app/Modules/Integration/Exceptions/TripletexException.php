<?php

namespace App\Modules\Integration\Exceptions;

use RuntimeException;

/** Provider bodies, request headers and chained HTTP exceptions must never escape. */
final class TripletexException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $status = null)
    {
        parent::__construct('Tripletex: '.str_replace('_', ' ', $reason).'.');
    }
}
