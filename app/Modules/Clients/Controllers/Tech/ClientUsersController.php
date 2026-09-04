<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Actions\MigrateClientUsersToContacts;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\Contact\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientUsersController extends Controller
{
    /**
     * Legacy Client Contact routes remain as compatibility aliases only.
     * Every visible workflow is redirected to the canonical Contact module.
     */
    public function index(Request $request, ?int $client = null): RedirectResponse
    {
        $parameters = array_filter([
            'client_id' => $client,
            'site_id' => $client ? null : $request->session()->get('active_site_id'),
            'q' => $request->string('search')->toString() ?: null,
        ], fn ($value): bool => filled($value));

        if ($client) {
            Client::query()->findOrFail($client);
            $request->session()->put('active_client_id', $client);
            $request->session()->forget('active_site_id');
        }

        return redirect()->route('tech.contacts.index', $parameters);
    }

    public function show(
        ClientUser $ClientUser,
        MigrateClientUsersToContacts $migration,
    ): RedirectResponse {
        return redirect()->route(
            'tech.contacts.show',
            $this->canonicalContact($ClientUser, $migration),
        );
    }

    public function edit(
        ClientUser $ClientUser,
        MigrateClientUsersToContacts $migration,
    ): RedirectResponse {
        return redirect()->route(
            'tech.contacts.edit',
            $this->canonicalContact($ClientUser, $migration),
        );
    }

    public function create(Request $request, Client $client): RedirectResponse
    {
        $siteId = $request->session()->get('active_site_id');
        $siteBelongsToClient = $siteId && ClientSite::query()
            ->whereKey($siteId)
            ->where('client_id', $client->id)
            ->exists();

        return redirect()->route('tech.contacts.create', array_filter([
            'client_id' => $client->id,
            'site_id' => $siteBelongsToClient ? $siteId : null,
        ]));
    }

    public function store(
        Request $request,
        Client $client,
        StoreContact $storeContact,
    ): RedirectResponse {
        $validated = $this->validateLegacyContact($request, $client->id);
        $contact = $storeContact->handle(
            $this->canonicalPayload($validated, $client->id, false)
        );

        return redirect()
            ->route('tech.contacts.show', $contact)
            ->with('status', 'Contact created in the central Contacts register.');
    }

    public function update(
        Request $request,
        ClientUser $ClientUser,
        MigrateClientUsersToContacts $migration,
        StoreContact $storeContact,
    ): RedirectResponse {
        $contact = $this->canonicalContact($ClientUser, $migration);
        $site = ClientSite::query()->findOrFail($request->integer('client_site_id'));
        $validated = $this->validateLegacyContact($request, $site->client_id);
        $updated = $storeContact->handle(
            $this->canonicalPayload($validated, $site->client_id, true, $contact)
        );

        return redirect()
            ->route('tech.contacts.show', $updated)
            ->with('status', 'Contact updated in the central Contacts register.');
    }

    public function delete(
        ClientUser $ClientUser,
        MigrateClientUsersToContacts $migration,
    ): RedirectResponse {
        $contact = $this->canonicalContact($ClientUser, $migration);

        return redirect()
            ->route('tech.contacts.show', $contact)
            ->with('warning', 'Contact deletion must be reviewed in the central Contacts workflow; no historical relationship was removed.');
    }

    private function canonicalContact(
        ClientUser $clientUser,
        MigrateClientUsersToContacts $migration,
    ): Contact {
        if ($clientUser->contact_id) {
            return Contact::query()->findOrFail($clientUser->contact_id);
        }

        return $migration->migrateOne($clientUser);
    }

    private function validateLegacyContact(Request $request, int $clientId): array
    {
        return $request->validate([
            'client_site_id' => [
                'required',
                Rule::exists('client_sites', 'id')
                    ->where(fn ($query) => $query->where('client_id', $clientId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'co_address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'county' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);
    }

    private function canonicalPayload(
        array $validated,
        int $clientId,
        bool $updateExisting,
        ?Contact $contact = null,
    ): array {
        return [
            'existing_contact_id' => $contact?->id,
            'display_name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'job_title' => $validated['role'] ?? null,
            'preferred_language' => $validated['language'] ?? null,
            'relation_type' => 'contact',
            'client_id' => $clientId,
            'site_id' => $validated['client_site_id'],
            'address' => $validated['address'] ?? null,
            'co_address' => $validated['co_address'] ?? null,
            'zip' => $validated['zip'] ?? null,
            'city' => $validated['city'] ?? null,
            'county' => $validated['county'] ?? null,
            'country' => $validated['country'] ?? null,
            'update_existing' => $updateExisting,
            'created_from' => 'legacy_client_contact_alias',
        ];
    }
}
