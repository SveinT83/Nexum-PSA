<?php

namespace App\Modules\Contact\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Support\ContactSettings;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->get('q'));
        $context = $this->contactContext($request);
        $clientFilterWasOverridden = $request->query->has('client_id');
        $siteFilterWasOverridden = $request->query->has('site_id');
        $clientFilter = $clientFilterWasOverridden
            ? ($request->integer('client_id') ?: null)
            : $context['activeClient']?->id;
        $siteFilter = $siteFilterWasOverridden
            ? ($request->integer('site_id') ?: null)
            : $context['activeSite']?->id;

        if ($siteFilter) {
            $selectedSite = ClientSite::query()->find($siteFilter);
            $clientFilter = $selectedSite?->client_id ?: $clientFilter;
        }

        $contacts = Contact::query()
            ->with(['emails', 'phones', 'relations.related', 'clientUser.site.client'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('display_name', 'like', '%'.$search.'%')
                        ->orWhere('organization_name', 'like', '%'.$search.'%')
                        ->orWhere('job_title', 'like', '%'.$search.'%')
                        ->orWhereHas('emails', fn ($emailQuery) => $emailQuery->where('email', 'like', '%'.$search.'%'))
                        ->orWhereHas('phones', fn ($phoneQuery) => $phoneQuery->where('phone', 'like', '%'.$search.'%'));
                });
            })
            ->when($siteFilter, fn ($query) => $this->scopeBySite($query, (int) $siteFilter))
            ->when(! $siteFilter && $clientFilter, fn ($query) => $this->scopeByClient($query, (int) $clientFilter))
            ->orderBy('display_name')
            ->paginate(25)
            ->withQueryString();

        return view('contact::Tech.index', [
            'contacts' => $contacts,
            'search' => $search,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'sites' => ClientSite::query()->with('client:id,name')->orderBy('name')->get(['id', 'client_id', 'name']),
            'activeClient' => $context['activeClient'],
            'activeSite' => $context['activeSite'],
            'selectedClientId' => $clientFilter,
            'selectedSiteId' => $siteFilter,
            'clientFilterWasOverridden' => $clientFilterWasOverridden,
            'siteFilterWasOverridden' => $siteFilterWasOverridden,
            'stats' => [
                'total' => Contact::query()->count(),
                'active' => Contact::query()->where('status', 'active')->count(),
                'people' => Contact::query()->where('type', 'person')->count(),
                'with_email' => Contact::query()->whereHas('emails')->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $context = $this->contactContext($request);

        return view('contact::Tech.create', [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'sites' => ClientSite::query()->with('client:id,name')->orderBy('name')->get(['id', 'client_id', 'name']),
            'activeClient' => $context['activeClient'],
            'activeSite' => $context['activeSite'],
        ]);
    }

    public function clearContext(Request $request): RedirectResponse
    {
        $request->session()->forget(['active_client_id', 'active_site_id']);

        return redirect()->route('tech.contacts.index');
    }

    public function store(Request $request, StoreContact $storeContact): RedirectResponse
    {
        $context = $this->contactContext($request);
        $settings = app(ContactSettings::class);

        $validated = $request->validate([
            'existing_contact_id' => ['nullable', Rule::exists('contacts', 'id')],
            'display_name' => ['required', 'string', 'max:255'],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'preferred_language' => ['nullable', 'string', 'max:10'],
            'relation_type' => ['nullable', Rule::in($settings->enabledRelationValues($request->string('relation_type')->toString()))],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'site_id' => ['nullable', Rule::exists('client_sites', 'id')],
        ]);

        if ($context['activeClient']) {
            $validated['client_id'] = $context['activeClient']->id;

            if (! $context['activeSite'] && ! empty($validated['site_id'])) {
                $siteBelongsToClient = ClientSite::query()
                    ->whereKey($validated['site_id'])
                    ->where('client_id', $context['activeClient']->id)
                    ->exists();

                if (! $siteBelongsToClient) {
                    throw ValidationException::withMessages([
                        'site_id' => 'The selected site does not belong to the active client.',
                    ]);
                }
            }
        }

        if ($context['activeSite']) {
            $validated['site_id'] = $context['activeSite']->id;
            $validated['client_id'] = $context['activeSite']->client_id;
        }

        $contact = $storeContact->handle($validated);

        return redirect()
            ->route('tech.contacts.show', $contact)
            ->with('status', 'Contact created.');
    }

    public function show(Contact $contact): View
    {
        $contact->load(['emails', 'phones', 'addresses', 'relations', 'externalRefs', 'clientUser.site.client', 'user']);

        return view('contact::Tech.show', [
            'contact' => $contact,
        ]);
    }

    public function edit(Request $request, Contact $contact): View
    {
        $context = $this->contactContext($request);

        return view('contact::Tech.edit', [
            'contact' => $contact,
            'activeClient' => $context['activeClient'],
            'activeSite' => $context['activeSite'],
        ]);
    }

    private function contactContext(Request $request): array
    {
        $activeSite = ClientSite::query()
            ->with('client:id,name')
            ->find($request->session()->get('active_site_id'));

        $activeClient = $activeSite?->client ?: Client::query()
            ->find($request->session()->get('active_client_id'));

        return [
            'activeClient' => $activeClient,
            'activeSite' => $activeSite,
        ];
    }

    private function scopeByClient($query, int $clientId): void
    {
        $clientType = (new Client())->getMorphClass();

        $query->where(function ($nested) use ($clientId, $clientType): void {
            $nested
                ->whereHas('relations', function ($relationQuery) use ($clientId, $clientType): void {
                    $relationQuery
                        ->where('related_type', $clientType)
                        ->where('related_id', $clientId);
                })
                ->orWhereHas('clientUser.site', fn ($siteQuery) => $siteQuery->where('client_id', $clientId));
        });
    }

    private function scopeBySite($query, int $siteId): void
    {
        $siteType = (new ClientSite())->getMorphClass();

        $query->where(function ($nested) use ($siteId, $siteType): void {
            $nested
                ->whereHas('relations', function ($relationQuery) use ($siteId, $siteType): void {
                    $relationQuery
                        ->where('related_type', $siteType)
                        ->where('related_id', $siteId);
                })
                ->orWhereHas('clientUser', fn ($clientUserQuery) => $clientUserQuery->where('client_site_id', $siteId));
        });
    }
}
