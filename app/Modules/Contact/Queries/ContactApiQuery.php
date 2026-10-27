<?php

namespace App\Modules\Contact\Queries;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Resources\Api\V1\ContactResource;
use App\Modules\Contact\Support\ContactApiInput;
use App\Modules\Contact\Support\ContactSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Stateless canonical ownership filters match Client/Site browser membership. */
class ContactApiQuery
{
    public function handle(Request $request)
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'], 'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:100'], 'status' => ['sometimes', Rule::in(array_keys(ContactSettings::STATUS_OPTIONS))],
            'client_id' => ['sometimes', 'nullable', 'integer', 'exists:clients,id'], 'site_id' => ['sometimes', 'nullable', 'integer', 'exists:client_sites,id'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        [$client, $site] = ContactApiInput::context($data['client_id'] ?? null, $data['site_id'] ?? null);
        $query = Contact::query()->with(ContactResource::RELATIONS)->orderBy('display_name')->orderBy('id');
        if ($q = trim($data['q'] ?? '')) {
            $query->where(function ($nested) use ($q): void {
                foreach (['display_name', 'organization_name', 'job_title'] as $field) {
                    $nested->orWhere($field, 'like', '%'.$q.'%');
                }
                $nested->orWhereHas('emails', fn ($e) => $e->where('email', 'like', '%'.$q.'%'))
                    ->orWhereHas('phones', fn ($p) => $p->where('phone', 'like', '%'.$q.'%'));
            });
        }
        if ($request->filled('email')) {
            $query->whereHas('emails', fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))]));
        }
        if ($request->filled('phone')) {
            $query->whereIn('id', app(ContactIdentityMatches::class)->handle(null, $data['phone'])->pluck('id'));
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if ($site) {
            $query->whereHas('relations', fn ($q) => $q->where('related_type', $site->getMorphClass())->where('related_id', $site->id));
        } elseif ($client) {
            $ids = $client->sites()->pluck('id');
            $query->whereHas('relations', function ($q) use ($client, $ids): void {
                $q->where(fn ($c) => $c->where('related_type', $client->getMorphClass())->where('related_id', $client->id))
                    ->orWhere(fn ($s) => $s->where('related_type', (new ClientSite)->getMorphClass())->whereIn('related_id', $ids));
            });
        }

        return $query;
    }
}
