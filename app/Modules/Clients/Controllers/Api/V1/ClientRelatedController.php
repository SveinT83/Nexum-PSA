<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Integration\Support\DomainApiAuthorization;
use App\Modules\Integration\Support\DomainApiRead;
use Illuminate\Http\Request;

/** Explicit owner-domain projections for the Client workspace, never a raw relation dump. */
class ClientRelatedController extends Controller
{
    public const SECTIONS = [
        'contacts' => ['contacts.read', 'contact.view'],
        'tickets' => ['tickets.read', 'ticket.view'],
        'tasks' => ['tasks.read', 'task.view'],
        'contracts' => ['commercial.read', 'commercial.view'],
        'signals' => ['clients.signals.read', 'signal.view'],
        'risk' => ['risk.read', 'risk.view'],
    ];

    public function index(Request $request, Client $client, string $section)
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);
        [$scope, $permission] = self::SECTIONS[$section];
        abort_unless($request->user()->tokenCan($scope), 403, 'The owner-domain token ability is required.');
        app(DomainApiAuthorization::class)->authorize($request, $permission);
        $rows = match ($section) {
            'contacts' => app(\App\Modules\Contact\Queries\ContactsForClient::class)->handle($client)
                ->map(fn ($c) => $c->only(['id', 'display_name', 'organization_name', 'job_title', 'status']) + ['url' => url('/api/v1/contacts/'.$c->id)]),
            'tickets' => app(\App\Modules\Ticket\Queries\ClientTicketListQuery::class)->forClient($client, $request->user())
                ->map(fn ($t) => $t->only(['id', 'ticket_key', 'subject', 'status_id', 'priority_id', 'owner_id', 'updated_at'])),
            'tasks' => $this->tasks($request, $client),
            'contracts' => $client->contracts()->orderByDesc('updated_at')->get()->map(fn ($c) => $c->only(['id', 'name', 'approval_status', 'start_date', 'end_date'])),
            'signals' => \App\Modules\Signal\Models\Signal::query()->where(fn ($q) => $q->where('client_id', $client->id)->orWhere(fn ($q) => $q->where('subject_type', $client->getMorphClass())->where('subject_id', $client->id)))
                ->orderByDesc('occurred_at')->orderByDesc('id')->get()->map(fn ($s) => $s->only(['id', 'signal_type', 'severity', 'status', 'summary', 'occurred_at'])),
            'risk' => $client->riskAssessments()->orderBy('id')->get()->map(fn ($a) => $a->only(['id', 'name', 'title', 'status', 'updated_at'])),
        };

        return response()->json(DomainApiRead::paginateCollection($rows->values(), $request));
    }

    private function tasks(Request $request, Client $client)
    {
        $query = \App\Modules\Task\Models\Task::query()->where(fn ($q) => $q->where(fn ($q) => $q->where('owner_type', $client->getMorphClass())->where('owner_id', $client->id))
            ->orWhereHasMorph('owner', [\App\Modules\Ticket\Models\Ticket::class], fn ($q) => $q->where('client_id', $client->id)));

        return app(\App\Modules\Task\Services\TaskApiScope::class)->visible($query, $request->user())->orderByDesc('updated_at')->get()
            ->map(fn ($t) => $t->only(['id', 'title', 'status_id', 'assigned_to', 'completed_at', 'owner_type', 'owner_id']));
    }
}
