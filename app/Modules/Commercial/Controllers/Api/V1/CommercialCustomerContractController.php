<?php

namespace App\Modules\Commercial\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Actions\ContractCustomerWorkflow;
use App\Modules\Commercial\Actions\ExecuteCommercialCommand;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\CommercialInput;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\PortalContractAccess;
use Illuminate\Http\Request;

/** Customer JSON never serializes staff costs, source metadata, credentials or a chosen staff identity. */
class CommercialCustomerContractController extends Controller
{
    private function contract(Request $request, bool $lock = false): Contracts
    {
        $flow = app(ContractCustomerWorkflow::class);
        if ($request->route('token') !== null) {
            $token = (string) $request->route('token');
            $request->attributes->set('commercial_public_hash', hash('sha256', $token));

            return $flow->publicContract($token, $lock);
        }
        $context = $flow->currentPortal($request);
        $q = Contracts::query();
        if ($lock) {
            $q->lockForUpdate();
        }$c = $q->findOrFail($request->route('contract'));
        abort_unless(app(PortalContractAccess::class)->canView($context, $c), 404);

        return $c;
    }

    private function data(Contracts $contract, bool $allowUnavailable = false): array
    {
        $document = null;
        $ready = true;
        try {
            $document = app(ContractCustomerDocument::class)->resolve($contract);
        } catch (\DomainException|\UnexpectedValueException $error) {
            if (! $allowUnavailable) {
                abort(409, 'The captured customer document requires manual verification.');
            }$ready = false;
        }
        $data = ['id' => $contract->id, 'description' => $contract->description, 'approval_status' => $contract->approval_status, 'customer_document' => $document, 'document_ready' => $ready, 'can_accept' => $ready && app(PortalContractAccess::class)->canAccept($contract), 'accepted_at' => $contract->accepted_at?->toIso8601String(), 'accepted_by_name' => $contract->accepted_by_name];
        $data['revision'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $data;
    }

    public function index(Request $request)
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $context = app(ContractCustomerWorkflow::class)->currentPortal($request);
        $page = app(PortalContractAccess::class)->visibleContracts($context)->latest('start_date')->paginate($request->integer('per_page', 15));

        return response()->json(['data' => $page->getCollection()->map(fn ($c) => $this->data($c, true)), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()], 'links' => ['next' => $page->nextPageUrl()]]);
    }

    public function show(Request $request)
    {
        return response()->json(['data' => $this->data($this->contract($request))]);
    }

    private function command(Request $request, callable $execute): array
    {
        $contract = $this->contract($request);

        return app(ExecuteCommercialCommand::class)->handle($request, function () use ($request, &$contract) {
            $contract = $this->contract($request, true);
        }, function () use ($request, &$contract, $execute) {
            abort_unless($request->header('If-Match'), 428, 'Read the customer document and supply If-Match.');
            abort_unless(hash_equals($this->data($contract)['revision'], trim($request->header('If-Match'), '"')), 409, 'The customer document changed.');

            return $execute($contract);
        });
    }

    public function viewed(Request $request)
    {
        CommercialInput::validate($request, []);

        return response()->json($this->command($request, function ($c) use ($request) {
            app(ContractCustomerWorkflow::class)->viewed($request, $c, $request->route('token'));

            return ['data' => $this->data($c->refresh())];
        }));
    }

    public function accept(Request $request)
    {
        $data = CommercialInput::validate($request, ['name' => 'required|string|max:255', 'confirm' => 'required|accepted']);

        return response()->json($this->command($request, function ($c) use ($request, $data) {
            $flow = app(ContractCustomerWorkflow::class);
            $accepted = $request->route('token') !== null ? $flow->acceptPublic($request, $request->route('token'), $data) : $flow->acceptPortal($request, $c, $data);
            abort_unless($accepted, 409, 'The document cannot be accepted in its current state.');

            return ['data' => $this->data($c->refresh())];
        }));
    }
}
