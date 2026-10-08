<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use RuntimeException;

/**
 * Generates the next five-digit client number used by client creation forms.
 */
class SuggestClientNumber
{
    public function handle(): string
    {
        $usedNumbers = Client::query()
            ->whereNotNull('client_number')
            ->pluck('client_number')
            ->map(fn (mixed $number): string => trim((string) $number))
            ->filter(fn (string $number): bool => $number !== '' && ctype_digit($number))
            ->map(fn (string $number): int => (int) $number)
            ->filter(fn (int $number): bool => $number >= 1 && $number <= 99999)
            ->mapWithKeys(fn (int $number): array => [$number => true]);

        $maxNumber = $usedNumbers->keys()->max() ?: 0;

        for ($number = $maxNumber + 1; $number <= 99999; $number++) {
            if (! $usedNumbers->has($number)) {
                return str_pad((string) $number, 5, '0', STR_PAD_LEFT);
            }
        }

        for ($number = 1; $number <= $maxNumber; $number++) {
            if (! $usedNumbers->has($number)) {
                return str_pad((string) $number, 5, '0', STR_PAD_LEFT);
            }
        }

        throw new RuntimeException('No five-digit client numbers are available.');
    }
}
