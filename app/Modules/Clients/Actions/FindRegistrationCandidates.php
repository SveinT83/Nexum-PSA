<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use App\Modules\Contact\Queries\ContactIdentityMatches;
use App\Modules\DataExchange\Models\TripletexCustomerLink;
use App\Modules\DataExchange\Services\TripletexCustomerNumbers;
use App\Modules\Integration\Exceptions\TripletexException;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Return a choice of identities without copying contact data or starting an external write. */
final class FindRegistrationCandidates
{
    public function allowed(Request $request): bool
    {
        $actor = $request->user();

        return $actor && ! $actor->isSystemActor()
            && app(DomainApiAuthorization::class)->permits($request, 'client.view')
            && (! $request->is('api/*') || $actor->tokenCan('clients.read'));
    }

    public function handle(Request $request, array $input): array
    {
        abort_unless($this->allowed($request), 403);
        $emails = array_values(array_unique(array_filter(array_map(
            static fn ($value) => mb_strtolower(trim((string) $value)),
            [$input['email'] ?? null, $input['billing_email'] ?? null]
        ))));
        $phone = $this->phone($input['phone'] ?? null);
        $org = $this->organization($input['org_no'] ?? null);
        if (! $emails && ! $phone && ! $org) {
            return [];
        }

        $reasons = [];
        if ($org) {
            // Stored organization numbers may include formatting from older imports.
            $variants = preg_match('/^[0-9]{9}$/D', $org) ? [$org, 'NO'.$org, 'NO'.$org.'MVA'] : [$org];
            $ids = Client::query()->whereIn(DB::raw("UPPER(REPLACE(REPLACE(TRIM(org_no),' ',''),'-',''))"), $variants)->pluck('id');
            foreach ($ids as $id) {
                $reasons[$id]['org_no'] = true;
            }
        }
        foreach (Client::query()->whereIn(DB::raw('LOWER(TRIM(billing_email))'), $emails)->pluck('id') as $id) {
            $reasons[$id]['email'] = true;
        }

        $contactsAllowed = app(DomainApiAuthorization::class)->permits($request, 'contact.view')
            && (! $request->is('api/*') || $request->user()->tokenCan('contacts.read'));
        if ($contactsAllowed) {
            foreach ($emails ?: [null] as $email) {
                foreach (app(ContactIdentityMatches::class)->handle($email, $phone) as $contact) {
                    $byEmail = $email && $contact->emails->contains(fn ($channel) => mb_strtolower(trim($channel->email)) === $email);
                    $byPhone = $phone && $contact->phones->contains(fn ($channel) => $this->phone($channel->phone) === $phone);
                    foreach ($contact->relations as $relation) {
                        if ($relation->related_type !== (new Client)->getMorphClass()
                            || $relation->starts_at?->isFuture() || $relation->ends_at?->isPast()) {
                            continue;
                        }
                        if ($byEmail) {
                            $reasons[$relation->related_id]['email'] = true;
                        }
                        if ($byPhone) {
                            $reasons[$relation->related_id]['phone'] = true;
                        }
                    }
                }
            }
        }

        $choices = [];
        $authority = app(TripletexCustomerNumbers::class);
        if ($connection = $authority->connection()) {
            try {
                $rows = (new TripletexClient($connection))->registrationCustomers();
            } catch (TripletexException $e) {
                abort(503, 'Tripletex customer lookup could not be verified.');
            }
            foreach ($rows as $row) {
                if (($row['isInactive'] ?? true) !== false || ($row['customerNumber'] ?? 0) < 1) {
                    continue;
                }
                $match = [];
                if ($org && $this->organization($row['organizationNumber'] ?? null) === $org) {
                    $match[] = 'org_no';
                }
                if (array_intersect($emails, array_map(static fn ($v) => mb_strtolower(trim((string) $v)), [$contactsAllowed ? ($row['email'] ?? '') : '', $row['invoiceEmail'] ?? '']))) {
                    $match[] = 'email';
                }
                if ($contactsAllowed && $phone && in_array($phone, [$this->phone($row['phoneNumber'] ?? null), $this->phone($row['phoneNumberMobile'] ?? null)], true)) {
                    $match[] = 'phone';
                }
                if (! $match) {
                    continue;
                }
                $link = TripletexCustomerLink::where('connection_id', $connection->id)->where('customer_id', $row['id'])->first();
                if ($link && $link->status === 'linked' && $link->client_id && Client::whereKey($link->client_id)->where('active', true)->exists()) {
                    foreach ($match as $field) {
                        $reasons[$link->client_id][$field] = true;
                    }

                    continue;
                }
                $occupied = $link || Client::where('client_number', (string) $row['customerNumber'])->exists();
                $choices[] = ['action' => $occupied ? 'review' : 'link_provider', 'id' => $row['id'],
                    'number' => (string) $row['customerNumber'], 'name' => $row['name'],
                    'org_no' => $row['organizationNumber'] ?? '', 'matched_fields' => $match];
            }
        }
        $local = [];
        foreach (Client::query()->whereKey(array_keys($reasons))->where('active', true)->orderBy('name')->get() as $client) {
            $local[] = ['action' => 'use_existing', 'id' => $client->id, 'number' => (string) $client->client_number,
                'name' => $client->name, 'org_no' => (string) $client->org_no, 'matched_fields' => array_keys($reasons[$client->id])];
        }

        return array_merge($local, $choices);
    }

    private function organization(?string $value): ?string
    {
        $value = strtoupper(preg_replace('/[\s-]+/', '', trim((string) $value)));
        if (preg_match('/^(?:NO)?([0-9]{9})(?:MVA)?$/D', $value, $matches)) {
            return $matches[1];
        }

        return preg_match('/^[A-Z0-9]{6,50}$/D', $value) ? $value : null;
    }

    private function phone(?string $value): ?string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\+?[0-9\s().-]+$/D', $value)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value);
        if (! str_starts_with($value, '+') && ! str_starts_with($digits, '00') && strlen($digits) === 8) {
            return '+47'.$digits;
        }
        $digits = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;

        return preg_match('/^[1-9][0-9]{6,14}$/D', $digits) ? '+'.$digits : null;
    }
}
