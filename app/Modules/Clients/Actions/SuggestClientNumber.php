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
        $maxNumber = Client::query()->max('client_number');

        return str_pad((string) (((int) $maxNumber) + 1), 5, '0', STR_PAD_LEFT);
    }
}
