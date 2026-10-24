<?php

namespace App\Modules\Sales\Actions;

use App\Models\Clients\Client;
use App\Modules\Clients\Actions\CreateClientWithDefaults;
use App\Modules\Contact\Actions\StoreContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Sales quick-create is an adapter to the Client/Contact owners, never a parallel identity engine. */
class SalesQuickCreate
{
    public function clientData(Request $request): array
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'tripletex_number_mode' => 'sometimes|boolean',
            'tripletex_request_key' => 'nullable|uuid',
            'tripletex_customer_id' => 'nullable|integer|min:1',
            'name' => 'required|string|max:255',
            'client_number' => 'nullable|string|regex:/^\d{5}$/',
            'org_no' => 'nullable|string|max:50',
            'client_format_id' => 'nullable|exists:client_formats,id',
            'website' => 'nullable|url|max:255',
            'billing_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
            'active' => 'sometimes|accepted',
            'site_name' => 'required|string|max:255',
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|max:255',
            'user_phone' => 'nullable|string|max:50',
            'user_role' => 'nullable|string|max:100',
        ]);

        return $data;
    }

    public function client(Request $request): array
    {
        return app(CreateClientWithDefaults::class)->handle(array_merge($this->clientData($request), ['active' => true]));
    }

    public function contact(Request $request, Client $client): array
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['client_site_id' => 'nullable|exists:client_sites,id', 'name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'phone' => 'nullable|string|max:50', 'role' => 'nullable|string|max:100']);

        return DB::transaction(function () use ($client, $data) {
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $site = ! empty($data['client_site_id']) ? $client->sites()->find($data['client_site_id']) : ($client->sites()->where('is_default', true)->first() ?: $client->sites()->first());
            abort_if(! empty($data['client_site_id']) && ! $site, 422, 'The site must belong to the selected client.');
            $site ??= $client->sites()->create(['name' => 'General sites', 'is_default' => true]);
            $contact = app(StoreContact::class)->handle(['display_name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'job_title' => $data['role'] ?? null, 'role' => $data['role'] ?? null, 'client_id' => $client->id, 'site_id' => $site->id, 'relation_type' => 'contact', 'active' => true, 'created_from' => 'sales_quick_create']);
            $bridge = $contact->clientUsers()->where('client_site_id', $site->id)->firstOrFail();

            return ['contact' => $bridge->only(['id', 'contact_id', 'name', 'email', 'phone', 'role']) + ['label' => $bridge->name.' <'.$bridge->email.'>'], 'site' => $site->only(['id', 'name'])];
        });
    }
}
