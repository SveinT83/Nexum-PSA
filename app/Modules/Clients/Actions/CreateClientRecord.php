<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a Client while keeping automatic number allocation race-safe.
 */
class CreateClientRecord
{
    private const MAX_AUTOMATIC_NUMBER_ATTEMPTS = 5;

    public function __construct(private readonly SuggestClientNumber $suggestClientNumber) {}

    /**
     * Run the Client insert and its required related writes in one transaction.
     *
     * @param  array<string, mixed>  $attributes
     * @param  Closure(Client): void  $afterCreate
     */
    public function handle(array $attributes, Closure $afterCreate): Client
    {
        $automaticNumber = blank($attributes['client_number'] ?? null);

        for ($attempt = 1; $attempt <= self::MAX_AUTOMATIC_NUMBER_ATTEMPTS; $attempt++) {
            $attemptAttributes = $attributes;
            if ($automaticNumber) {
                $attemptAttributes['client_number'] = $this->suggestClientNumber->handle();
            }

            try {
                return DB::transaction(function () use ($attemptAttributes, $afterCreate): Client {
                    $client = Client::query()->create($attemptAttributes);
                    $afterCreate($client);

                    return $client;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isClientNumberConflict($exception)) {
                    throw $exception;
                }

                if ($automaticNumber && $attempt < self::MAX_AUTOMATIC_NUMBER_ATTEMPTS) {
                    continue;
                }

                $message = $automaticNumber
                    ? 'Nexum could not allocate a unique client number. Please try again.'
                    : 'This client number is already in use.';

                throw ValidationException::withMessages([
                    'client_number' => $message,
                ]);
            }
        }

        throw ValidationException::withMessages([
            'client_number' => 'Nexum could not allocate a unique client number. Please try again.',
        ]);
    }

    private function isClientNumberConflict(UniqueConstraintViolationException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'clients_client_number_unique')
            || str_contains($message, 'unique constraint failed: clients.client_number')
            || str_contains($message, "for key 'clients.client_number'")
            || str_contains($message, "for key 'client_number'");
    }
}
