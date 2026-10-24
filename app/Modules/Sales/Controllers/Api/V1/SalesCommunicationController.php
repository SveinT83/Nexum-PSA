<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Sales\Actions\SalesWorkflow;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesApiCommandReceipt;
use App\Modules\Sales\Models\SalesDeliveryAttempt;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Resources\Api\V1\SalesProjection;
use App\Modules\Sales\Services\SalesCommunications;
use App\Modules\Sales\Support\SalesApiAccess;
use Illuminate\Http\Request;

/** Explicit preview/send/read-back routes bind exact reviewed recipients, version and provider. */
class SalesCommunicationController extends SalesApiController
{
    public function quotePreview(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version, SalesCommunications $mail)
    {
        $this->version($opportunity, $version);
        DomainApiInput::validate($request, []);
        $receipt = $this->command($request, $opportunity, fn () => ['type' => 'delivery', 'id' => $mail->preview($request->user(), 'quote', $opportunity, $version)->id], true, $version);

        return response()->json(['data' => $mail->project(SalesDeliveryAttempt::findOrFail($receipt['id']), true), 'operation' => $receipt], 201);
    }

    public function quoteSend(Request $request, SalesOpportunity $opportunity, SalesQuoteVersion $version, SalesWorkflow $workflow, SalesCommunications $mail)
    {
        $data = DomainApiInput::validate($request, ['preview_id' => ['required', 'uuid'], 'preview_hash' => ['required', 'string', 'size:64']]);
        $this->version($opportunity, $version);
        $receipt = $this->command($request, $opportunity, function () use ($request, $opportunity, $version, $data, $workflow) {
            $request->attributes->set('sales_delivery_preview_id', $data['preview_id']);
            $request->attributes->set('sales_delivery_preview_hash', $data['preview_hash']);
            // Send input is a reviewed preview identity; the workflow's own mutations accept no caller fields.
            $result = app()->call([$workflow, 'sendQuote'], ['request' => $request, 'sale' => $opportunity]);

            return ['type' => 'delivery', 'id' => $data['preview_id'], 'version_id' => $version->id, 'notice' => $result->level, 'message' => $result->message];
        }, true, $version);

        return response()->json(['data' => ['quote' => SalesProjection::version($version->fresh()), 'delivery' => $mail->project(SalesDeliveryAttempt::findOrFail($receipt['id']))], 'operation' => $receipt]);
    }

    public function emailPreview(Request $request, SalesOpportunity $opportunity, SalesCommunications $mail)
    {
        $input = $request->all();
        $input['type'] = 'email_out';
        $receipt = $this->command($request, $opportunity, fn () => ['type' => 'delivery', 'id' => $mail->preview($request->user(), 'activity', $opportunity, null, $input)->id]);

        return response()->json(['data' => $mail->project(SalesDeliveryAttempt::findOrFail($receipt['id']), true), 'operation' => $receipt], 201);
    }

    public function emailSend(Request $request, SalesOpportunity $opportunity, SalesCommunications $mail, SalesWorkflow $workflow)
    {
        $data = DomainApiInput::validate($request, ['preview_id' => ['required', 'uuid'], 'preview_hash' => ['required', 'string', 'size:64']]);
        $receipt = $this->command($request, $opportunity, function () use ($request, $opportunity, $mail, $workflow, $data) {
            $attempt = $mail->assertPreview($data['preview_id'], $request->user(), 'activity', $opportunity, null, $data['preview_hash']);
            $input = Request::create('/', 'POST', $attempt->payload['input']);
            $input->setUserResolver(fn () => $request->user());
            $input->attributes->set('sales_delivery_preview_id', $attempt->id);
            $input->attributes->set('sales_delivery_preview_hash', $data['preview_hash']);
            $result = $workflow->storeActivity($input, $opportunity);

            return ['type' => 'delivery', 'id' => $attempt->id, 'activity_id' => $result->data['activity_id']];
        });

        return response()->json(['data' => ['activity' => SalesProjection::activity(SalesActivity::findOrFail($receipt['activity_id'])), 'delivery' => $mail->project(SalesDeliveryAttempt::findOrFail($receipt['id']))], 'operation' => $receipt], 201);
    }

    public function show(Request $request, string $operation, SalesCommunications $mail)
    {
        $receipt = SalesApiCommandReceipt::query()->whereKey($operation)->where('actor_key', SalesApiAccess::actorKey($request))->firstOrFail();
        $data = ['operation_id' => $receipt->id] + $receipt->result;
        if (($data['type'] ?? '') === 'delivery') {
            $attempt = SalesDeliveryAttempt::query()->whereKey($data['id'])->where('actor_id', $request->user()->id)->firstOrFail();
            SalesApiAccess::authorize($request, $attempt->kind === 'quote' ? 'sales.quote_manage' : 'sales.view');
            $data['delivery'] = $mail->project($attempt, true);
        }

        return response()->json(['data' => $data]);
    }

    public function dispatch(Request $request, string $operation, SalesCommunications $mail)
    {
        DomainApiInput::validate($request, []);
        $receipt = SalesApiCommandReceipt::query()->whereKey($operation)->where('actor_key', SalesApiAccess::actorKey($request))->firstOrFail();
        abort_unless(($receipt->result['type'] ?? '') === 'delivery', 404);
        $attempt = SalesDeliveryAttempt::query()->whereKey($receipt->result['id'])->where('actor_id', $request->user()->id)->firstOrFail();
        SalesApiAccess::authorize($request, $attempt->kind === 'quote' ? 'sales.quote_manage' : 'sales.view');
        abort_unless($request->user()->tokenCan($attempt->kind === 'quote' ? 'sales.quotes.send' : 'sales.email.send'), 403);
        $command = app(\App\Modules\Sales\Actions\ExecuteSalesApiCommand::class)->handle($request, 'sales.delivery.dispatch:'.$attempt->id, [], function () use ($request, $attempt) {
            SalesApiAccess::authorize($request, $attempt->kind === 'quote' ? 'sales.quote_manage' : 'sales.view');
            SalesDeliveryAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
        }, function () use ($mail, $attempt) {
            $mail->dispatch($attempt->refresh());

            return ['type' => 'delivery', 'id' => $attempt->id];
        });

        return response()->json(['data' => $mail->project($attempt->refresh()), 'operation' => $command]);
    }
}
