<?php

namespace App\Modules\Ticket\Services;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Domain policy failures are actionable; unexpected infrastructure messages never become API validation text. */
final class TicketRuleApiFailure
{
    public static function run(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RuntimeException $exception) {
            if ($exception::class !== RuntimeException::class) {
                throw $exception;
            }
            $message = $exception->getMessage();
            $prefixes = ['Ticket Rule ', 'The Ticket Rule ', 'The selected ', 'The draft ', 'This draft ', 'The immutable ', 'The publisher ', 'Published Ticket ', 'This published ', 'The full-rerun ', 'The Ticket changed ', 'Only failed ', 'This action ', 'The retry ', 'Full rerun ', 'A preview ', 'The action retry '];
            $safe = collect($prefixes)->contains(fn ($prefix) => str_starts_with($message, $prefix));
            throw ValidationException::withMessages(['rule' => $safe ? $message : 'The rule operation could not be completed under the current policy.']);
        }
    }
}
