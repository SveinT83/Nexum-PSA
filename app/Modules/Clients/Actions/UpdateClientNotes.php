<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;

/** Only Notes changes; omitted settings can never be reset by an autosave. */
class UpdateClientNotes
{
    public function handle(Client $client, ?string $notes): void
    {
        $client->forceFill(['notes' => filled($notes) ? $notes : null])->save();
    }
}
