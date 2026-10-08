<?php

namespace App\Modules\DataExchange\Services;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\DataExchange\Models\TripletexCustomerLink;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit, drift-checked adoption is the only way to change an existing linked number. */
class TripletexCustomerLinks
{
    public function adopt(Integration $connection, Client $client, int $customerId, string $expectedLocal, string $expectedRemote, bool $acceptChange): void
    {
        Cache::lock(TripletexCustomerNumbers::lockKey($connection->id), 600)->block(30, function () use ($connection, $client, $customerId, $expectedLocal, $expectedRemote, $acceptChange) {
            $authority = app(TripletexCustomerNumbers::class);
            abort_unless(config('tripletex.enabled'), 422, 'Enable the Tripletex integration runtime before reviewing customer links.');
            $authority->ready($connection->fresh());
            $remote = (new TripletexClient($connection->fresh()))->customer($customerId);
            $authority->assertUsable($remote);
            abort_unless((string) $remote['customerNumber'] === $expectedRemote, 409, 'Tripletex changed. Review the link again.');
            DB::transaction(function () use ($connection, $client, $customerId, $expectedLocal, $expectedRemote, $acceptChange, $authority) {
                $current = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
                abort_unless((string) $current->client_number === $expectedLocal, 409, 'Client changed. Review the link again.');
                abort_unless($expectedLocal === $expectedRemote || $acceptChange, 422, 'Explicitly accept the Tripletex number before applying this change.');
                $authority->assertNumberFree($expectedRemote, $current->id);
                $link = TripletexCustomerLink::where('client_id', $current->id)->first();
                abort_if($link && ($link->connection_id !== $connection->id || $link->customer_id !== $customerId), 409, 'An established customer identity cannot be reassigned.');
                $other = TripletexCustomerLink::where('connection_id', $connection->id)->where('customer_id', $customerId)->first();
                abort_if($other && $other->client_id && $other->id !== $link?->id, 409, 'This customer is already linked.');
                $link ??= $other; // Explicit admin review may finish a verified orphan when the original form was lost.
                abort_if($link && ($link->company_id !== (int) $connection->config['company_id']
                    || $link->environment !== $connection->config['environment']), 409, 'The saved identity belongs to another company or environment.');
                $link ??= new TripletexCustomerLink(['connection_id' => $connection->id, 'request_key' => (string) Str::uuid(),
                    'company_id' => $connection->config['company_id'], 'environment' => $connection->config['environment']]);
                // Bypass ordinary model number edits only inside this explicit audited transaction.
                DB::table('clients')->where('id', $current->id)->update(['client_number' => $expectedRemote, 'updated_at' => now()]);
                $link->fill(['client_id' => $current->id, 'customer_id' => $customerId, 'customer_number' => $expectedRemote,
                    'status' => 'linked', 'checked_at' => now(), 'error_code' => null])->save();
                activity()->causedBy(auth()->user())->event('tripletex.customer.number_adopted')
                    ->withProperties(['client_id' => $current->id, 'customer_id' => $customerId,
                        'previous_number' => $expectedLocal, 'customer_number' => $expectedRemote])
                    ->log('Explicit Tripletex customer link and number adoption');
            });
        });
    }

    public function recover(Integration $connection, int $attemptId, int $customerId, string $expectedNumber): void
    {
        Cache::lock(TripletexCustomerNumbers::lockKey($connection->id), 600)->block(30, function () use ($connection, $attemptId, $customerId, $expectedNumber) {
            abort_unless(app(TripletexCustomerNumbers::class)->connection()?->id === $connection->id, 422);
            $state = TripletexCustomerLink::where('connection_id', $connection->id)->whereKey($attemptId)->firstOrFail();
            abort_unless(! $state->client_id && ! $state->customer_id && $state->status === 'sending', 409, 'This attempt does not need unknown-outcome recovery.');
            abort_unless($state->company_id === (int) $connection->config['company_id']
                && $state->environment === $connection->config['environment'], 409, 'The saved attempt belongs to another company or environment.');
            $remote = (new TripletexClient($connection->fresh()))->customer($customerId);
            app(TripletexCustomerNumbers::class)->assertUsable($remote);
            abort_unless($expectedNumber === (string) $remote['customerNumber']
                && $expectedNumber === $state->customer_number, 409, 'The provider number does not match the saved attempt.');
            abort_if(TripletexCustomerLink::where('connection_id', $connection->id)->where('customer_id', $customerId)->exists(), 409);
            $state->update(['customer_id' => $customerId, 'status' => 'verifying', 'error_code' => null]);
            activity()->causedBy(auth()->user())->event('tripletex.customer.recovered')
                ->withProperties(['attempt_id' => $state->id, 'customer_id' => $customerId])
                ->log('Tripletex unknown creation outcome reconciled; original form must be resubmitted');
        });
    }
}
