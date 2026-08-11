<?php

namespace App\Modules\Notification\Exceptions;

use RuntimeException;

class TemporaryWebPushDeliveryException extends RuntimeException
{
    public static function forStatus(?int $status): self
    {
        return new self(
            $status
                ? "Temporary Web Push delivery failure (HTTP {$status})."
                : 'Temporary Web Push transport failure.'
        );
    }
}
