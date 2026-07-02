<?php

namespace App\Modules\WorkContext\Actions;

use App\Models\Clients\Client;
use App\Modules\WorkContext\Models\WorkContext;
use Illuminate\Validation\ValidationException;

class ResolveWorkContext
{
    public function __construct(private readonly EnsureWorkContextDefaults $defaults)
    {
    }

    public function fromPayload(array $payload, string $clientKey = 'client_id'): WorkContext
    {
        return $this->fromClientId($payload[$clientKey] ?? null);
    }

    public function fromClientId(int|string|null $clientId): WorkContext
    {
        if ($clientId === null || $clientId === '') {
            return $this->defaults->internalContext();
        }

        if (! is_numeric($clientId) || (int) $clientId < 1) {
            throw ValidationException::withMessages([
                'client_id' => 'The selected Client is invalid.',
            ]);
        }

        $client = Client::query()->find((int) $clientId);

        if (! $client) {
            throw ValidationException::withMessages([
                'client_id' => 'The selected Client was not found.',
            ]);
        }

        return $this->defaults->clientContext($client);
    }

    public function internal(): WorkContext
    {
        return $this->defaults->internalContext();
    }

    public function client(Client $client): WorkContext
    {
        return $this->defaults->clientContext($client);
    }
}
