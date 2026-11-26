<?php

namespace App\Modules\Clients\Support;

/** Resolve only submitted customer addresses at creation; never use the signed-in employee email. */
final class ClientBillingEmail
{
    public static function fromInput(array $input): ?string
    {
        // Importing a selected provider customer requires a separately reviewed invoice recipient.
        if (filled($input['tripletex_customer_id'] ?? null)) {
            $invoice = trim((string) ($input['billing_email'] ?? ''));

            return $invoice !== '' ? $invoice : null;
        }

        // Ordinary registration retains its existing primary contact fallback.
        foreach ([$input['billing_email'] ?? null, $input['primary_contact']['email'] ?? null, $input['user_email'] ?? null] as $email) {
            if (is_string($email) && trim($email) !== '') {
                return trim($email);
            }
        }

        return null;
    }
}
