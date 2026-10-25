<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Clients\ClientResource;
use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\Clients\ClientSite;
use App\Modules\Clients\Actions\SuggestClientNumber;
use App\Modules\Clients\Resources\Api\V1\ClientSiteResource;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Http\Request;

/** Pure discoverable context: it never creates integrations, reserves numbers or mutates a session. */
class ClientWorkspaceController extends Controller
{
    public function context(Request $request)
    {
        return response()->json(['data' => [
            'formats' => ClientFormat::activeOptions()->map(fn ($f) => $f->only(['id', 'name', 'code']))->values(),
            'filters' => ['active' => [true, false], 'contract_filter' => ['without_contract', 'with_contract', 'won_contract'], 'rmm_filter' => ['linked', 'unlinked']],
            'sort' => ['name', 'client_number', 'org_no', 'format', 'billing_email', 'status', 'contracts'],
            'page_size' => ['default' => 15, 'maximum' => 100],
            'number' => ['authority' => app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class)->connection() ? 'tripletex' : 'local',
                'local_suggestion' => app(SuggestClientNumber::class)->handle(), 'reserved' => false],
            'capabilities' => [
                'create' => $request->user()->tokenCan('clients.create') && app(DomainApiAuthorization::class)->permits($request, 'client.create'),
                'update' => $request->user()->tokenCan('clients.update') && app(DomainApiAuthorization::class)->permits($request, 'client.update'),
                'settings' => $request->user()->tokenCan('clients.settings.read') && app(DomainApiAuthorization::class)->permits($request, 'client.manage_settings'),
            ],
            'defaults' => ['site_name' => 'Default', 'active' => true, 'lead_temperature' => 3],
            'constraints' => ['local_number_digits' => 5, 'provider_number_request_key' => 'uuid', 'primary_contact' => ['display_name', 'email', 'phone', 'job_title']],
        ]]);
    }

    public function site(Request $request, ClientSite $site)
    {
        return new ClientSiteResource($site->load('client'));
    }

    public function workspace(Request $request, Client $client)
    {
        $links = ['sites' => url('/api/v1/clients/'.$client->id.'/sites')];
        // Owner APIs remain authoritative; token grants and current owner permissions must both pass.
        foreach ([
            ['contacts', 'contact.view', 'contacts.read', '/clients/'.$client->id.'/contacts'],
            ['assets', 'asset.view', 'assets.read', '/clients/'.$client->id.'/assets'],
            ['tickets', 'ticket.view', 'tickets.read', '/tickets?client_id='.$client->id],
            ['tasks', 'task.view', 'tasks.read', '/tasks?owner_type=client&owner_id='.$client->id],
            ['contracts', 'commercial.view', 'commercial.read', '/commercial/contracts?client_id='.$client->id],
            ['timebank', 'commercial.timebank.view', 'commercial.timebank.read', '/commercial/clients/'.$client->id.'/timebank'],
            ['task_templates', 'task.view', 'tasks.templates.read', '/task-template-targets?owner_type=client&owner_id='.$client->id],
            ['signals', 'signal.view', 'clients.signals.read', '/signals?client_id='.$client->id],
            ['risk', 'risk.view', 'risk.read', '/risk/assessments?client_id='.$client->id],
        ] as [$key, $permission, $ability, $path]) {
            if ($request->user()->tokenCan($ability) && app(DomainApiAuthorization::class)->permits($request, $permission)) {
                $links[$key] = in_array($key, ['contacts', 'tickets', 'tasks', 'contracts', 'signals', 'risk'], true)
                    ? url('/api/v1/clients/'.$client->id.'/related/'.$key) : url('/api/v1'.$path);
            }
        }

        return response()->json(['data' => ['client' => (new ClientResource($client->load('sites')))->resolve($request), 'links' => $links]]);
    }
}
