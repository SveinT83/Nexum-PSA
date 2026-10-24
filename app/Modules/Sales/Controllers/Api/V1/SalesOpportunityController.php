<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Sales\Actions\MarkSalesOpportunityLost;
use App\Modules\Sales\Actions\ReopenSalesOpportunity;
use App\Modules\Sales\Actions\SalesOpportunityWorkflow;
use App\Modules\Sales\Actions\SalesWorkflow;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Queries\SalesApiWorkspace;
use App\Modules\Sales\Resources\Api\V1\SalesProjection;
use Illuminate\Http\Request;

/** Typed opportunity adapters; all business mutations are shared with the browser. */
class SalesOpportunityController extends SalesApiController
{
    public function index(Request $request)
    {
        $query = SalesOpportunity::query()->with(['client', 'primaryContact', 'owner'])
            ->when(! $request->filled('status') && ! $request->filled('q'), fn ($q) => $q->where('status', '!=', 'lost'))
            ->when($request->filled('q'), function ($q) use ($request) {
                $needle = '%'.$request->string('q')->trim().'%';
                $q->where(fn ($inner) => $inner->where('title', 'like', $needle)->orWhere('opportunity_key', 'like', $needle)->orWhereHas('client', fn ($client) => $client->where('name', 'like', $needle)));
            });
        foreach (['status', 'client_id', 'owner_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('owner')) {
            $query->where('owner_id', $request->integer('owner'));
        }
        $query->orderByDesc('is_unread')->orderByRaw('CASE WHEN next_follow_up_at IS NOT NULL AND next_follow_up_at < ? THEN 0 ELSE 1 END', [now()])->latest('updated_at')->orderBy('id');

        return response()->json(SalesProjection::page($query->paginate($request->integer('per_page') ?: 20), fn ($item) => SalesProjection::opportunity($item)));
    }

    public function context(Request $request, SalesApiWorkspace $workspace)
    {
        return response()->json(['data' => $workspace->context($request)]);
    }

    public function opportunityContext(Request $request, SalesOpportunity $opportunity, SalesApiWorkspace $workspace)
    {
        return response()->json(['data' => $workspace->context($request, $opportunity)]);
    }

    public function dashboard(SalesApiWorkspace $workspace)
    {
        return response()->json(['data' => $workspace->dashboard()]);
    }

    public function show(SalesOpportunity $opportunity)
    {
        return response()->json(['data' => SalesProjection::opportunity($opportunity)]);
    }

    public function store(Request $request, SalesOpportunityWorkflow $workflow)
    {
        $receipt = $this->command($request, null, fn () => ['type' => 'opportunity', 'id' => $workflow->store($request)->id], false);

        return response()->json(['data' => SalesProjection::opportunity(SalesOpportunity::findOrFail($receipt['id'])), 'operation' => $receipt], 201);
    }

    public function update(Request $request, SalesOpportunity $opportunity, SalesOpportunityWorkflow $workflow)
    {
        $receipt = $this->command($request, $opportunity, fn () => ['type' => 'opportunity', 'id' => $workflow->update($request, $opportunity)->id], false);

        return response()->json(['data' => SalesProjection::opportunity($opportunity->refresh()), 'operation' => $receipt]);
    }

    public function markLost(Request $request, SalesOpportunity $opportunity, MarkSalesOpportunityLost $action)
    {
        $data = DomainApiInput::validate($request, ['lost_reason' => ['required', 'string', 'max:4000'], 'internal_note' => ['nullable', 'string', 'max:4000']]);
        $receipt = $this->command($request, $opportunity, function () use ($action, $opportunity, $data, $request) {
            $action->handle($opportunity, $data['lost_reason'], $data['internal_note'] ?? null, $request->user());

            return ['type' => 'opportunity', 'id' => $opportunity->id];
        }, false);

        return response()->json(['data' => SalesProjection::opportunity($opportunity->refresh()), 'operation' => $receipt]);
    }

    public function reopen(Request $request, SalesOpportunity $opportunity, ReopenSalesOpportunity $action)
    {
        $data = DomainApiInput::validate($request, ['status' => ['required', \Illuminate\Validation\Rule::in(ReopenSalesOpportunity::REOPEN_STATUSES)]]);
        $receipt = $this->command($request, $opportunity, function () use ($action, $opportunity, $data, $request) {
            $action->handle($opportunity, $data['status'], $request->user());

            return ['type' => 'opportunity', 'id' => $opportunity->id];
        }, false);

        return response()->json(['data' => SalesProjection::opportunity($opportunity->refresh()), 'operation' => $receipt]);
    }

    public function activities(Request $request, SalesOpportunity $opportunity)
    {
        return response()->json(SalesProjection::page($opportunity->activities()->with('actor')->paginate($request->integer('per_page') ?: 25), fn ($row) => SalesProjection::activity($row)));
    }

    public function storeActivity(Request $request, SalesOpportunity $opportunity, SalesWorkflow $workflow)
    {
        // Outbound mail requires an explicitly reviewed preview; internal note/inbound/journal retain this path.
        abort_if($request->input('type') === 'email_out', 422, 'Use the email preview and send command.');
        $receipt = $this->command($request, $opportunity, function () use ($request, $opportunity, $workflow) {
            $result = $workflow->storeActivity($request, $opportunity);

            return ['type' => 'activity', 'id' => $result->data['activity_id']];
        }, $request->input('type') === 'internal_note' && $request->filled('notify_user_id'));

        return response()->json(['data' => SalesProjection::activity(SalesActivity::findOrFail($receipt['id'])), 'operation' => $receipt], 201);
    }

    public function markRead(Request $request, SalesOpportunity $opportunity, SalesWorkflow $workflow)
    {
        DomainApiInput::validate($request, []);
        $receipt = $this->command($request, $opportunity, function () use ($request, $opportunity, $workflow) {
            $workflow->markRead($request, $opportunity);

            return ['type' => 'opportunity', 'id' => $opportunity->id];
        }, false);

        return response()->json(['data' => SalesProjection::opportunity($opportunity->refresh()), 'operation' => $receipt]);
    }

    public function markActivityRead(Request $request, SalesOpportunity $opportunity, SalesActivity $activity, SalesWorkflow $workflow)
    {
        DomainApiInput::validate($request, []);
        abort_unless((int) $activity->opportunity_id === (int) $opportunity->id, 404);
        $receipt = $this->command($request, $opportunity, function () use ($request, $opportunity, $activity, $workflow) {
            $workflow->markActivityRead($request, $opportunity, $activity);

            return ['type' => 'activity', 'id' => $activity->id];
        });

        return response()->json(['data' => SalesProjection::activity($activity->refresh()), 'opportunity' => SalesProjection::opportunity($opportunity->refresh()), 'operation' => $receipt]);
    }

    public function stakeholders(SalesOpportunity $opportunity)
    {
        return response()->json(['data' => SalesProjection::opportunity($opportunity)['stakeholders']]);
    }

    public function storeStakeholder(Request $request, SalesOpportunity $opportunity, SalesWorkflow $workflow)
    {
        $receipt = $this->command($request, $opportunity, function () use ($request, $opportunity, $workflow) {
            $workflow->storeStakeholder($request, $opportunity);

            return ['type' => 'opportunity', 'id' => $opportunity->id];
        });

        return response()->json(['data' => SalesProjection::opportunity($opportunity->refresh()), 'operation' => $receipt]);
    }
}
