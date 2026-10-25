<?php

namespace App\Modules\Commercial\Controllers\Api\V1;

use App\Modules\Commercial\Actions\ExecuteCommercialCommand;
use App\Modules\Commercial\Models\CommercialCommandReceipt;
use App\Modules\Commercial\Models\CommercialDeliveryAttempt;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Services\CommercialDelivery;
use App\Modules\Commercial\Support\CommercialInput;
use App\Modules\Commercial\Support\CommercialRevision;
use Illuminate\Http\Request;

/** Explicit preview/submit/read-back transport for frozen contract communication. */
class CommercialCommunicationController extends CommercialApiController
{
    private function contract(Request $r): Contracts
    {
        return Contracts::query()->findOrFail($r->route('contract'));
    }

    private function attempt(Request $r, Contracts $c): CommercialDeliveryAttempt
    {
        return CommercialDeliveryAttempt::query()->where('contract_id', $c->id)->where('actor_id', $r->user()->id)->findOrFail($r->route('delivery') ?? $r->input('preview_id'));
    }

    public function preview(Request $r)
    {
        $data = CommercialInput::validate($r, ['kind' => 'required|in:quote,contract,resend', 'cc_email' => 'nullable|email|max:255']);
        $c = $this->contract($r);

        return response()->json($this->command($r, $c, function () use ($r, $c, $data) {
            $flow = app(CommercialDelivery::class);
            $attempt = $flow->preview($c, $r->user(), $data['kind'], $data['cc_email'] ?? null);

            return ['data' => $flow->projection($attempt, true), 'contract_revision' => CommercialRevision::of($c->fresh())];
        }), 201);
    }

    public function submit(Request $r)
    {
        abort_unless(config('commercial.api_delivery_enabled', false), 409, 'API delivery is disabled until deployment review enables it.');
        $input = CommercialInput::validate($r, ['preview_id' => 'required|uuid', 'preview_hash' => 'required|string|size:64']);
        $c = $this->contract($r);

        return response()->json($this->command($r, $c, function () use ($r, $c, $input) {
            $attempt = $this->attempt($r, $c);
            abort_unless($attempt->kind === $r->route()->defaults['commercial_delivery_kind'], 422, 'The preview belongs to a different action.');
            $flow = app(CommercialDelivery::class);
            $attempt = $flow->submit($attempt, $c, $r->user(), $input['preview_hash']);

            return ['data' => $flow->projection($attempt), 'contract_revision' => CommercialRevision::of($c->fresh())];
        }), 202);
    }

    public function show(Request $r)
    {
        $c = $this->contract($r);

        return response()->json(['data' => app(CommercialDelivery::class)->projection($this->attempt($r, $c))]);
    }

    public function recover(Request $r)
    {
        abort_unless(config('commercial.api_delivery_enabled', false), 409, 'API delivery is disabled until deployment review enables it.');
        CommercialInput::validate($r, []);
        $c = $this->contract($r);

        return response()->json($this->command($r, $c, function () use ($r, $c) {
            $attempt = $this->attempt($r, $c);
            $flow = app(CommercialDelivery::class);
            $flow->dispatch($attempt);

            return ['data' => $flow->projection($attempt)];
        }), 202);
    }

    public function operation(Request $r)
    {
        $receipt = CommercialCommandReceipt::query()->where('actor_key', ExecuteCommercialCommand::actorKey($r))->findOrFail($r->route('operation'));
        $route = app('router')->getRoutes()->getByName($receipt->operation);
        abort_unless($route, 404);
        app(\App\Modules\Integration\Support\DomainApiAuthorization::class)->authorize($r, $route->defaults['commercial_permission'] ?? 'commercial.contract_manage');

        return response()->json(['data' => ['id' => $receipt->id, 'operation' => $receipt->operation, 'created_at' => $receipt->created_at, 'completed' => true, 'result' => $receipt->result]]);
    }
}
