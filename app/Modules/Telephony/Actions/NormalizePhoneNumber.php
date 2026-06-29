<?php

namespace App\Modules\Telephony\Actions;

class NormalizePhoneNumber
{
    public function handle(?string $phone, string $defaultCountryCode = '+47'): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $defaultCountryCode = '+'.ltrim($defaultCountryCode, '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($phone, '+')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        $defaultDigits = ltrim($defaultCountryCode, '+');

        if (str_starts_with($digits, $defaultDigits) && strlen($digits) > strlen($defaultDigits)) {
            return '+'.$digits;
        }

        if ($defaultCountryCode === '+47' && strlen($digits) === 8) {
            return '+47'.$digits;
        }

        return '+'.$digits;
    }
}
