<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;

/**
 * Generates the next five-digit client number used by client creation forms.
 */
class SuggestClientNumber
{
    public function handle(): string
    {
        $maxNumber = Client::query()
            ->whereNotNull('client_number')
            ->pluck('client_number')
            ->map(fn (mixed $number): int => (int) (preg_replace('/\D+/', '', (string) $number) ?: 0))
            ->max() ?: 0;

        do {
            $maxNumber++;
            $candidate = str_pad((string) $maxNumber, 5, '0', STR_PAD_LEFT);
        } while (Client::query()->where('client_number', $candidate)->exists());

        return $candidate;
    }
}
