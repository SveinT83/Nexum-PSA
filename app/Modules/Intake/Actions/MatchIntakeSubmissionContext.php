<?php

namespace App\Modules\Intake\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;

class MatchIntakeSubmissionContext
{
    /**
     * @return array{matched_client_id:int|null,matched_site_id:int|null,matched_contact_id:int|null,matched_client_user_id:int|null,match_method:string|null}
     */
    public function handle(array $normalized): array
    {
        $email = $this->clean($normalized['contact_email'] ?? null);
        $orgNo = $this->digits($normalized['org_no'] ?? null);
        $website = $this->host($normalized['website'] ?? null);
        $companyName = $this->clean($normalized['company_name'] ?? null);

        if ($email !== '') {
            $clientUser = ClientUser::query()
                ->with(['site.client', 'contact'])
                ->where('email', $email)
                ->first();

            if ($clientUser) {
                return $this->result(
                    $clientUser->site?->client,
                    $clientUser->site,
                    $clientUser->contact,
                    $clientUser,
                    'client_user_email',
                );
            }

            $contact = Contact::query()
                ->with(['clientUser.site.client'])
                ->whereHas('emails', fn ($query) => $query->where('email', $email))
                ->first();

            if ($contact) {
                return $this->result(
                    $contact->clientUser?->site?->client,
                    $contact->clientUser?->site,
                    $contact,
                    $contact->clientUser,
                    'contact_email',
                );
            }

            $client = Client::query()->where('billing_email', $email)->first();

            if ($client) {
                return $this->result($client, $this->defaultSite($client), null, null, 'client_billing_email');
            }
        }

        if ($orgNo !== '') {
            $client = Client::query()
                ->where('org_no', $orgNo)
                ->orWhere('org_no', $normalized['org_no'] ?? null)
                ->first();

            if ($client) {
                return $this->result($client, $this->defaultSite($client), null, null, 'client_org_no');
            }
        }

        if ($website !== '') {
            $client = Client::query()
                ->where('website', 'like', '%'.$website.'%')
                ->first();

            if ($client) {
                return $this->result($client, $this->defaultSite($client), null, null, 'client_website');
            }
        }

        if ($companyName !== '') {
            $client = Client::query()
                ->whereRaw('LOWER(name) = ?', [strtolower($companyName)])
                ->first();

            if ($client) {
                return $this->result($client, $this->defaultSite($client), null, null, 'client_name');
            }
        }

        return $this->result(null, null, null, null, null);
    }

    private function result(
        ?Client $client,
        ?ClientSite $site,
        ?Contact $contact,
        ?ClientUser $clientUser,
        ?string $method,
    ): array {
        return [
            'matched_client_id' => $client?->id,
            'matched_site_id' => $site?->id,
            'matched_contact_id' => $contact?->id,
            'matched_client_user_id' => $clientUser?->id,
            'match_method' => $method,
        ];
    }

    private function defaultSite(Client $client): ?ClientSite
    {
        return $client->sites()
            ->where('is_default', true)
            ->orderBy('name')
            ->first()
            ?: $client->sites()->orderBy('name')->first();
    }

    private function clean(mixed $value): string
    {
        return trim((string) $value);
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function host(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $host = parse_url(str_starts_with($value, 'http') ? $value : 'https://'.$value, PHP_URL_HOST);

        return strtolower((string) preg_replace('/^www\./', '', (string) $host));
    }
}
