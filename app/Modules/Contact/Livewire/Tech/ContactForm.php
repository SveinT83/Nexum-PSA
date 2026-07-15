<?php

namespace App\Modules\Contact\Livewire\Tech;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Support\ContactSettings;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ContactForm extends Component
{
    public ?int $contactId = null;

    public ?int $activeClientId = null;

    public ?int $activeSiteId = null;

    public ?int $existing_contact_id = null;

    public ?string $display_name = null;

    public ?string $organization_name = null;

    public ?string $email = null;

    public ?string $phone = null;

    public bool $sms_allowed = false;

    public ?string $job_title = null;

    public string $relation_type = 'contact';

    public ?int $client_id = null;

    public ?int $site_id = null;

    public ?int $selected_organization_client_id = null;

    public ?string $selected_organization_client_name = null;

    public function mount(?int $activeClientId = null, ?int $activeSiteId = null, ?int $contactId = null): void
    {
        $this->contactId = $contactId;
        $this->activeClientId = $activeClientId;
        $this->activeSiteId = $activeSiteId;
        $this->relation_type = app(ContactSettings::class)->get()['default_relation_type'];

        if ($contactId) {
            $this->hydrateFromContact($contactId);

            return;
        }

        if ($activeSiteId) {
            $site = ClientSite::query()->with('client')->find($activeSiteId);
            $this->site_id = $site?->id;
            $this->client_id = $site?->client_id;
            $this->selected_organization_client_id = $site?->client_id;
            $this->organization_name = $site?->client?->name;

            return;
        }

        $this->client_id = $activeClientId;
        $this->selected_organization_client_id = $activeClientId;
        $this->organization_name = $activeClientId
            ? Client::query()->whereKey($activeClientId)->value('name')
            : null;
    }

    private function hydrateFromContact(int $contactId): void
    {
        $contact = Contact::query()
            ->with(['emails', 'phones', 'relations.related', 'clientUser.site.client'])
            ->findOrFail($contactId);

        $clientRelation = $contact->relations->first(fn ($relation) => $relation->related instanceof Client);
        $siteRelation = $contact->relations->first(fn ($relation) => $relation->related instanceof ClientSite);
        $site = $siteRelation?->related ?: $contact->clientUser?->site;
        $client = $clientRelation?->related ?: $site?->client ?: $contact->clientUser?->site?->client;

        $this->existing_contact_id = $contact->id;
        $this->display_name = $contact->display_name;
        $this->organization_name = $client?->name ?: $contact->organization_name;
        $this->email = $contact->emails->firstWhere('is_primary', true)?->email ?: $contact->emails->first()?->email;
        $primaryPhone = $contact->phones->firstWhere('is_primary', true) ?: $contact->phones->first();
        $this->phone = $primaryPhone?->phone;
        $this->sms_allowed = (bool) $primaryPhone?->sms_allowed;
        $this->job_title = $contact->job_title;
        $this->client_id = $client?->id;
        $this->selected_organization_client_id = $client?->id;
        $this->selected_organization_client_name = $client?->name;
        $this->site_id = $site?->id;
        $this->relation_type = $siteRelation?->relation_type ?: $clientRelation?->relation_type ?: app(ContactSettings::class)->get()['default_relation_type'];
    }

    public function updatedOrganizationName(): void
    {
        if (
            $this->selected_organization_client_id
            && ! $this->organizationMatchesSelectedClient()
            && trim((string) $this->organization_name) !== trim((string) $this->selected_organization_client_name)
        ) {
            $this->client_id = null;
            $this->selected_organization_client_id = null;
            $this->selected_organization_client_name = null;
            $this->site_id = null;
        }

        if (
            ! $this->activeClientId
            && ! $this->activeSiteId
            && ! $this->client_id
            && $this->site_id
        ) {
            $this->site_id = null;
        }

        $exactClient = $this->clientSuggestions()
            ->first(fn (Client $client) => mb_strtolower($client->name) === mb_strtolower(trim((string) $this->organization_name)));

        if ($exactClient) {
            $this->selectClient($exactClient->id);
        }
    }

    public function updatedClientId(): void
    {
        if ($this->site_id && ! ClientSite::query()->whereKey($this->site_id)->where('client_id', $this->client_id)->exists()) {
            $this->site_id = null;
        }
    }

    public function selectClient(int $clientId): void
    {
        $client = Client::query()->find($clientId);

        if (! $client) {
            return;
        }

        $this->client_id = $client->id;
        $this->selected_organization_client_id = $client->id;
        $this->selected_organization_client_name = $client->name;
        $this->organization_name = $client->name;
        $this->site_id = $this->defaultSiteIdForClient($client);
    }

    public function selectExistingContact(int $contactId): void
    {
        $contact = Contact::query()
            ->with(['emails', 'phones', 'relations.related', 'clientUser.site.client'])
            ->find($contactId);

        if (! $contact) {
            return;
        }

        $clientRelation = $contact->relations->first(fn ($relation) => $relation->related instanceof Client);
        $siteRelation = $contact->relations->first(fn ($relation) => $relation->related instanceof ClientSite);
        $site = $siteRelation?->related ?: $contact->clientUser?->site;
        $client = $clientRelation?->related ?: $site?->client ?: $contact->clientUser?->site?->client;

        $this->existing_contact_id = $contact->id;
        $this->display_name = $contact->display_name;
        $this->organization_name = $client?->name ?: $contact->organization_name ?: $this->organization_name;
        $this->job_title = $contact->job_title ?: $this->job_title;
        $this->email = $contact->emails->firstWhere('is_primary', true)?->email ?: $contact->emails->first()?->email ?: $this->email;
        $primaryPhone = $contact->phones->firstWhere('is_primary', true) ?: $contact->phones->first();
        $this->phone = $primaryPhone?->phone ?: $this->phone;
        $this->sms_allowed = (bool) ($primaryPhone?->sms_allowed ?? $this->sms_allowed);
        $this->client_id = $client?->id;
        $this->selected_organization_client_id = $client?->id;
        $this->selected_organization_client_name = $client?->name;
        $this->site_id = $site?->id;
    }

    public function save(StoreContact $storeContact)
    {
        $validated = $this->validate([
            'existing_contact_id' => ['nullable', Rule::exists('contacts', 'id')],
            'display_name' => ['required', 'string', 'max:255'],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'sms_allowed' => ['boolean'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'relation_type' => ['required', Rule::in(array_keys($this->relationOptions()))],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'site_id' => ['nullable', Rule::exists('client_sites', 'id')],
        ]);
        $validated['update_existing'] = (bool) $this->contactId;

        if ($this->client_id && $this->organizationMatchesSelectedClient()) {
            $validated['organization_name'] = null;
        }

        if ($this->activeSiteId) {
            $site = ClientSite::query()->find($this->activeSiteId);
            $validated['site_id'] = $site?->id;
            $validated['client_id'] = $site?->client_id;
        } elseif ($this->activeClientId) {
            $validated['client_id'] = $this->activeClientId;
        }

        $contact = $storeContact->handle($validated);

        session()->flash('status', 'Contact saved.');

        return redirect()->route('tech.contacts.show', $contact);
    }

    public function duplicateMatches(): Collection
    {
        $email = trim((string) $this->email);
        $phone = $this->normalizePhone($this->phone);

        if ($email === '' && $phone === '') {
            return collect();
        }

        return Contact::query()
            ->with(['emails', 'phones'])
            ->when($this->contactId, fn ($query) => $query->whereKeyNot($this->contactId))
            ->where(function ($query) use ($email, $phone): void {
                if ($email !== '') {
                    $query->orWhereHas('emails', fn ($emailQuery) => $emailQuery->where('email', $email));
                }

                if ($phone !== '') {
                    $query->orWhereHas('phones');
                }
            })
            ->limit(10)
            ->get()
            ->filter(function (Contact $contact) use ($phone): bool {
                if ($phone === '') {
                    return true;
                }

                return $contact->phones->contains(
                    fn ($contactPhone) => $this->normalizePhone($contactPhone->phone) === $phone
                ) || trim((string) $this->email) !== '';
            })
            ->values();
    }

    public function selectedExistingContact(): ?Contact
    {
        if (! $this->existing_contact_id) {
            return null;
        }

        return Contact::query()
            ->with(['emails', 'phones'])
            ->find($this->existing_contact_id);
    }

    public function clientSuggestions(): Collection
    {
        $search = trim((string) $this->organization_name);

        if (mb_strlen($search) < 2) {
            return collect();
        }

        if ($this->organizationMatchesSelectedClient()) {
            return collect();
        }

        return Client::query()
            ->where('name', 'like', '%'.$search.'%')
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name']);
    }

    public function titleSuggestions(): Collection
    {
        $search = trim((string) $this->job_title);

        if (mb_strlen($search) < 2) {
            return collect();
        }

        return Contact::query()
            ->whereNotNull('job_title')
            ->where('job_title', 'like', '%'.$search.'%')
            ->distinct()
            ->orderBy('job_title')
            ->limit(5)
            ->pluck('job_title');
    }

    public function siteOptions(): Collection
    {
        return ClientSite::query()
            ->when($this->client_id, fn ($query) => $query->where('client_id', $this->client_id))
            ->with('client:id,name')
            ->orderBy('name')
            ->get(['id', 'client_id', 'name']);
    }

    public function relationOptions(): array
    {
        return app(ContactSettings::class)->relationOptions($this->relation_type);
    }

    public function render()
    {
        $selectedClient = $this->client_id ? Client::query()->find($this->client_id) : null;
        $selectedSite = $this->site_id ? ClientSite::query()->with('client:id,name')->find($this->site_id) : null;

        if ($selectedClient && $this->selected_organization_client_id === $selectedClient->id) {
            $this->organization_name = $selectedClient->name;
        }

        return view('contact::Livewire.Tech.contact-form', [
            'duplicateMatches' => $this->duplicateMatches(),
            'clientSuggestions' => $this->clientSuggestions(),
            'titleSuggestions' => $this->titleSuggestions(),
            'siteOptions' => $this->siteOptions(),
            'relationOptions' => $this->relationOptions(),
            'selectedClient' => $selectedClient,
            'selectedSite' => $selectedSite,
            'showSiteField' => (bool) ($selectedClient || $selectedSite || $this->activeClientId || $this->activeSiteId),
        ]);
    }

    private function normalizePhone(?string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($normalized, '0047') && strlen($normalized) === 12) {
            return substr($normalized, 4);
        }

        if (str_starts_with($normalized, '47') && strlen($normalized) === 10) {
            return substr($normalized, 2);
        }

        return $normalized;
    }

    private function organizationMatchesSelectedClient(): bool
    {
        if (! $this->client_id) {
            return false;
        }

        $clientName = Client::query()->whereKey($this->client_id)->value('name');

        return $clientName && mb_strtolower($clientName) === mb_strtolower(trim((string) $this->organization_name));
    }

    private function defaultSiteIdForClient(Client $client): ?int
    {
        return $client->sites()
            ->where('is_default', true)
            ->orderBy('name')
            ->value('id');
    }
}
