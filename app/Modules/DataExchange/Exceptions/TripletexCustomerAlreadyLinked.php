<?php

namespace App\Modules\DataExchange\Exceptions;

use Illuminate\Validation\ValidationException;

/** Another verified registration already owns this provider identity. */
final class TripletexCustomerAlreadyLinked extends ValidationException
{
    public int $clientId;

    public static function forClient(int $clientId): self
    {
        $exception = self::withMessages(['tripletex_customer_id' => 'This Tripletex customer is already linked to a Nexum Client.']);
        $exception->clientId = $clientId;

        return $exception;
    }
}
