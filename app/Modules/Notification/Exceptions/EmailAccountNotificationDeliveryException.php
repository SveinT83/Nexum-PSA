<?php

namespace App\Modules\Notification\Exceptions;

use RuntimeException;

/**
 * Safe synchronous delivery failure with no recipient, content or provider text.
 */
final class EmailAccountNotificationDeliveryException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('The Email notification was not sent ('.$reasonCode.').');
    }
}
