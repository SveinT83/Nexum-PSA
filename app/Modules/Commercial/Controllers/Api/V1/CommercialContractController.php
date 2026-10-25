<?php

namespace App\Modules\Commercial\Controllers\Api\V1;

use App\Modules\Commercial\Actions\AttestLegacyContractCustomerDocument;
use App\Modules\Commercial\Actions\BuildContractPdf;
use App\Modules\Commercial\Actions\BuildContractTermSnapshots;
use App\Modules\Commercial\Actions\ContractItemWorkflow;
use App\Modules\Commercial\Actions\ContractLifecycleWorkflow;
use App\Modules\Commercial\Actions\ContractMetadataWorkflow;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Queries\CommercialCatalogueQuery;
use App\Modules\Commercial\Queries\CommercialContractQuery;
use App\Modules\Commercial\Resources\Api\V1\CommercialContractResource;
use App\Modules\Commercial\Support\CommercialInput;
use App\Modules\Commercial\Support\CommercialProjection;
use App\Modules\Commercial\Support\CommercialRevision;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractTermSnapshotReadiness;
use Illuminate\Http\Request;

/** Complete staff Contract transport; shared actions own evidence, calculations and lifecycle. */
class CommercialContractController extends CommercialApiController
{
    private function contract(Request $request, bool $optional = false): ?Contracts
    {
        $id = $request->route('contract');
        abort_unless(is_numeric($id), 404);

        return $optional ? Contracts::query()->find($id) : Contracts::query()->findOrFail($id);
    }

    public function data(Request $request, Contracts $contract): array
    {
        $contract->load(['client', 'sla', 'items.service', 'items.timeRates', 'termSnapshots.termVersion'])->loadCount('items');
        $data = (new CommercialContractResource($contract))->resolve($request);

        return $data + $contract->only(['allow_license_additions', 'allow_license_increases', 'allow_license_decreases', 'allow_license_price_updates', 'terms_snapshot', 'dpa_snapshot', 'legal_snapshot', 'sla_snapshot', 'general_snapshot', 'sent_at', 'accepted_at', 'accepted_by_name', 'viewed_at', 'cc_email']) + [
            'items' => $contract->items->map(fn ($i) => CommercialProjection::item($i))->all(),
            'term_versions' => $contract->termSnapshots->map(fn ($t) => $t->only(['id', 'term_id', 'term_version_id', 'type', 'name', 'content', 'checksum', 'version_label']))->all(),
            'editable' => $contract->isEditable(), 'ready' => $contract->isReady(), 'terms_current' => app(ContractTermSnapshotReadiness::class)->isCurrent($contract), 'revision' => CommercialRevision::of($contract),
        ];
    }

    public function index(Request $request)
    {
        $request->validate(['q' => 'sometimes|string|max:255', 'client_id' => 'sometimes|integer|exists:clients,id', 'status' => 'sometimes|string|max:50', 'period' => 'sometimes|string|max:50', 'sort' => 'sometimes|string|max:100']);
        $workspace = app(CommercialContractQuery::class)->handle($request);
        $page = $workspace['contracts'];
        $data = $page->getCollection()->map(fn ($c) => $this->data($request, $c))->all();

        return response()->json(['data' => $data, 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()], 'links' => ['next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()]]);
    }

    public function show(Request $request)
    {
        $data = $this->data($request, $this->contract($request));

        return response()->json(['data' => $data])->header('ETag', '"'.$data['revision'].'"');
    }

    public function context(Request $request)
    {
        $lookups = [];
        foreach (app(CommercialCatalogueQuery::class)->lookups('contracts') as $key) {
            $lookups[$key] = url('/api/v1/commercial/contracts/context/'.$key);
        }

        return response()->json(['data' => ['lookups' => $lookups, 'defaults' => app(\App\Modules\Commercial\Queries\CommercialContractFormContext::class)->defaults(), 'states' => ['draft', 'negotiation', 'quote_lost', 'sent_quote', 'sent_contract', 'approved', 'won'], 'billing_intervals' => ['monthly', 'quarterly', 'yearly', 'one_time'], 'sale_currencies' => ['NOK'], 'discount_types' => ['percent', 'amount'], 'revision_header' => 'If-Match', 'idempotency_header' => 'Idempotency-Key']]);
    }

    public function lookup(Request $request)
    {
        $page = app(CommercialCatalogueQuery::class)->lookup($request, 'contracts', $request->route('lookup'));
        $page->setCollection($page->getCollection()->map(fn ($r) => $r->attributesToArray()));

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()], 'links' => ['next' => $page->nextPageUrl()]]);
    }

    public function store(Request $request)
    {
        return response()->json($this->command($request, null, function () use ($request) {
            $c = app(ContractMetadataWorkflow::class)->store($request);

            return ['data' => $this->data($request, $c)];
        }, false), 201);
    }

    public function update(Request $request)
    {
        $c = $this->contract($request);

        return response()->json($this->command($request, $c, function () use ($request, $c) {
            $saved = app(ContractMetadataWorkflow::class)->update($request, $c);
            abort_unless($saved, 409, 'Sent and accepted contracts are immutable.');

            return ['data' => $this->data($request, $saved)];
        }, false));
    }

    public function destroy(Request $request)
    {
        CommercialInput::validate($request, []);
        $c = $this->contract($request, true);

        return response()->json($this->command($request, $c, function () use ($c) {
            abort_unless($c, 404);
            abort_unless($c->approval_status === 'draft', 409, 'Only draft contracts can be deleted.');
            abort_if($c->end_date?->isPast(), 409, 'An ended contract cannot be deleted.');
            $c->delete();

            return ['data' => ['id' => $c->id, 'deleted' => true]];
        }));
    }

    public function items(Request $request)
    {
        $c = $this->contract($request);

        return response()->json(['data' => $c->items()->with('timeRates')->orderBy('id')->get()->map(fn ($i) => CommercialProjection::item($i)), 'meta' => ['revision' => CommercialRevision::of($c)]]);
    }

    public function defaults(Request $request)
    {
        $request->validate(['service_id' => 'required|integer|exists:services,id']);
        $c = $this->contract($request);
        $data = (new ContractItemWorkflow($c))->defaults($request->integer('service_id'));
        $data = \Illuminate\Support\Arr::except($data, ['contract_id', 'item_cost', 'cost_unit_price', 'cost_currency', 'licence_metadata', 'sla_label', 'tax_rate']);

        return response()->json(['data' => $data, 'meta' => ['revision' => CommercialRevision::of($c)]]);
    }

    public function saveItem(Request $request)
    {
        $c = $this->contract($request);
        $id = $request->route('item');
        $data = $this->command($request, $c, function () use ($request, $c, $id) {
            $engine = new ContractItemWorkflow($c);
            $rules = $engine->inputRules();
            foreach ($rules as $key => $rule) {
                if (! str_contains($key, '*')) {
                    $rules[$key] = 'sometimes|'.str_replace('required|', '', $rule);
                }
            }
            if (! $id) {
                $rules['service_id'] = 'required|integer|exists:services,id';
            }
            $input = CommercialInput::validate($request, $rules);
            $item = $engine->persist($input, $id ? (int) $id : null);

            return ['data' => CommercialProjection::item($item), 'contract_revision' => CommercialRevision::of($c->fresh())];
        });

        return response()->json($data, $id ? 200 : 201);
    }

    public function removeItem(Request $request)
    {
        CommercialInput::validate($request, []);
        $c = $this->contract($request);
        $id = (int) $request->route('item');

        return response()->json($this->command($request, $c, function () use ($c, $id) {
            $engine = new ContractItemWorkflow($c);
            $index = collect($engine->items)->search(fn ($i) => (int) ($i['id'] ?? 0) === $id);
            abort_if($index === false, 404);
            $engine->removeItem($index);

            return ['data' => ['id' => $id, 'deleted' => true], 'contract_revision' => CommercialRevision::of($c->fresh())];
        }));
    }

    public function syncCadence(Request $request)
    {
        CommercialInput::validate($request, []);
        $c = $this->contract($request);
        $id = (int) $request->route('item');

        return response()->json($this->command($request, $c, function () use ($c, $id) {
            $engine = new ContractItemWorkflow($c);
            $index = collect($engine->items)->search(fn ($i) => (int) ($i['id'] ?? 0) === $id);
            abort_if($index === false, 404);
            $engine->syncBillingIntervalFromService($index);

            return ['data' => CommercialProjection::item($c->items()->findOrFail($id)), 'contract_revision' => CommercialRevision::of($c->fresh())];
        }));
    }

    public function pricing(Request $request)
    {
        $c = $this->contract($request);
        $engine = new ContractItemWorkflow($c);

        return response()->json(['data' => ['pricing' => $c->pricingTotals(), 'editor' => (new \App\Modules\Commercial\Support\ContractEditorPricing($engine->items))->summary(), 'revision' => CommercialRevision::of($c)]]);
    }

    public function previewPricing(Request $request)
    {
        $c = $this->contract($request);
        $rules = ['items' => 'required|array|max:500'];
        $fields = ['item_id' => 'sometimes|integer|min:1', 'service_id' => 'sometimes|integer|exists:services,id', 'unit_price' => 'required|numeric|min:0', 'quantity' => 'required|integer|min:1', 'price_currency' => 'sometimes|in:NOK', 'billing_interval' => 'required|in:monthly,quarterly,yearly,one_time', 'discount_value' => 'nullable|numeric|min:0', 'discount_type' => 'nullable|in:percent,amount', 'setup_fee' => 'nullable|numeric|min:0'];
        foreach ($fields as $key => $rule) {
            $rules['items.*.'.$key] = $rule;
        }
        $input = CommercialInput::validate($request, $rules);
        $states = [];
        $engine = new ContractItemWorkflow($c);
        foreach ($input['items'] as $row) {
            $base = [];
            if (isset($row['item_id'])) {
                $base = collect($engine->items)->first(fn ($i) => (int) ($i['id'] ?? 0) === (int) $row['item_id']);
                abort_unless($base, 404);
            } elseif (isset($row['service_id'])) {
                $base = $engine->defaults((int) $row['service_id']);
            }
            $states[] = array_merge($base, $row);
        }

        try {
            $calculator = app(\App\Modules\Commercial\Support\ContractPricing::class);
            $lines = array_map(fn ($row) => $calculator->calculateLine($row), $input['items']);
            $totals = $calculator->calculateTotals($input['items']);
        } catch (\InvalidArgumentException|\OverflowException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return response()->json(['data' => ['lines' => $lines, 'pricing' => $totals, 'editor' => (new \App\Modules\Commercial\Support\ContractEditorPricing($states))->summary(), 'revision' => CommercialRevision::of($c)]]);
    }

    public function terms(Request $request)
    {
        $c = $this->contract($request);
        $builder = app(BuildContractTermSnapshots::class);

        return response()->json(['data' => ['snapshots' => $c->only(['terms_snapshot', 'dpa_snapshot', 'legal_snapshot', 'sla_snapshot', 'general_snapshot']), 'generated' => $c->isEditable() ? $builder->handle($c) : null, 'current' => app(ContractTermSnapshotReadiness::class)->isCurrent($c), 'revision' => CommercialRevision::of($c)]]);
    }

    public function refreshTerms(Request $request)
    {
        CommercialInput::validate($request, []);
        $c = $this->contract($request);

        return response()->json($this->command($request, $c, function () use ($request, $c) {
            abort_unless(app(ContractLifecycleWorkflow::class)->refreshTerms($c, $request->user()->id), 409, 'Sent and accepted terms are immutable.');

            return ['data' => $this->data($request, $c->refresh())];
        }));
    }

    public function saveTerms(Request $request)
    {
        $c = $this->contract($request);
        $rules = [];
        foreach (['terms_snapshot', 'dpa_snapshot', 'legal_snapshot', 'sla_snapshot', 'general_snapshot'] as $field) {
            $rules[$field] = 'sometimes|nullable|string|max:200000';
        }
        $input = CommercialInput::validate($request, $rules);

        return response()->json($this->command($request, $c, function () use ($request, $c, $input, $rules) {
            $updates = array_merge($c->only(array_keys($rules)), $input);
            abort_unless(app(ContractLifecycleWorkflow::class)->saveTerms($c, $updates, $request->user()->id), 409, 'Sent and accepted terms are immutable.');

            return ['data' => $this->data($request, $c->refresh())];
        }));
    }

    public function approve(Request $request)
    {
        CommercialInput::validate($request, []);
        $c = $this->contract($request);

        return response()->json($this->command($request, $c, function () use ($request, $c) {
            $result = app(ContractLifecycleWorkflow::class)->approve($c, $request->user());
            abort_if(isset($result['error']), 409, $result['error'] ?? '');

            return ['data' => $this->data($request, $result['contract'])];
        }));
    }

    public function attestationPreview(Request $request)
    {
        $request->validate(['document_type' => 'required|in:Tilbud,Avtale']);
        $c = $this->contract($request);
        $documents = app(ContractCustomerDocument::class);
        abort_if($c->isEditable() || $documents->hasStoredSnapshot($c), 409, 'This contract does not require legacy attestation.');
        $preview = $documents->previewForLegacyAttestation($c, null, $request->input('document_type'));

        return response()->json(['data' => ['internal_reconstruction' => $preview, 'fingerprint' => $documents->fingerprint($preview), 'document_type' => $request->input('document_type'), 'revision' => CommercialRevision::of($c)]]);
    }

    public function attest(Request $request)
    {
        $input = CommercialInput::validate($request, ['attestation_note' => 'required|string|min:20|max:2000', 'confirm_legacy_attestation' => 'required|accepted', 'legacy_attestation_fingerprint' => 'required|string|size:64|regex:/\A[0-9a-f]{64}\z/D', 'legacy_attestation_document_type' => 'required|in:Tilbud,Avtale']);
        $c = $this->contract($request);

        return response()->json($this->command($request, $c, function () use ($request, $c, $input) {
            app(AttestLegacyContractCustomerDocument::class)->handle($c, $request->user(), $input['attestation_note'], true, $input['legacy_attestation_fingerprint'], $input['legacy_attestation_document_type']);

            return ['data' => $this->data($request, $c->refresh())];
        }));
    }

    public function pdf(Request $request)
    {
        $artifact = app(BuildContractPdf::class)->handle($this->contract($request));

        return response($artifact['bytes'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$artifact['filename'].'"']);
    }
}
