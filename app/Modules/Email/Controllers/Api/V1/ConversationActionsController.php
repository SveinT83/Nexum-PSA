<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\CancelEmailConversationAcknowledgement;
use App\Modules\Email\Actions\PreviewEmailConversationAcknowledgement;
use App\Modules\Email\Jobs\ProcessEmailConversationAcknowledgementRun;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationActionItem;
use App\Modules\Email\Models\EmailConversationActionRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationActionsController extends Controller
{
    public function preview(Request $request, PreviewEmailConversationAcknowledgement $preview): JsonResponse
    {
        $data = $request->validate([
            'scope_kind' => ['required', Rule::in([
                EmailConversationActionRun::SCOPE_ACTIVE_ACCOUNT_CONVERSATION,
                EmailConversationActionRun::SCOPE_EXPLICIT_MULTI_ACCOUNT,
            ])],
            'account_id' => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'placement_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'placement_ids.*' => ['integer', 'distinct'],
            'target_personal_unread' => ['required', 'boolean'],
            'provider_seen_requested' => ['sometimes', 'boolean'],
            'item_cap' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:160'],
        ]);
        $actor = $request->user();

        try {
            $run = $data['scope_kind'] === EmailConversationActionRun::SCOPE_ACTIVE_ACCOUNT_CONVERSATION
                ? $preview->activeAccountConversation(
                    $actor,
                    EmailAccount::query()->findOrFail((int) ($data['account_id'] ?? 0)),
                    EmailConversation::query()->findOrFail((int) ($data['conversation_id'] ?? 0)),
                    $data['idempotency_key'],
                    (bool) $data['target_personal_unread'],
                    (bool) ($data['provider_seen_requested'] ?? false),
                    $data['item_cap'] ?? null,
                )
                : $preview->explicitMultiAccount(
                    $actor,
                    $data['placement_ids'] ?? [],
                    $data['idempotency_key'],
                    (bool) $data['target_personal_unread'],
                    (bool) ($data['provider_seen_requested'] ?? false),
                    $data['item_cap'] ?? null,
                );
        } catch (AuthorizationException) {
            abort(404);
        }

        return response()->json(['data' => $this->serializeRun($run)], 201);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        return response()->json(['data' => $this->serializeRun($this->ownedRun($request, $run))]);
    }

    public function apply(Request $request, string $run): JsonResponse
    {
        $run = $this->ownedRun($request, $run);
        ProcessEmailConversationAcknowledgementRun::dispatch($run->id);

        return response()->json(['data' => $this->serializeRun($run), 'queued' => true], 202);
    }

    public function retry(Request $request, string $run): JsonResponse
    {
        $run = $this->ownedRun($request, $run);
        ProcessEmailConversationAcknowledgementRun::dispatch($run->id);

        return response()->json(['data' => $this->serializeRun($run), 'queued' => true], 202);
    }

    public function cancel(
        Request $request,
        string $run,
        CancelEmailConversationAcknowledgement $cancel,
    ): JsonResponse {
        $run = $cancel->handle($this->ownedRun($request, $run), $request->user());

        return response()->json(['data' => $this->serializeRun($run)]);
    }

    private function ownedRun(Request $request, string $publicId): EmailConversationActionRun
    {
        $run = EmailConversationActionRun::query()
            ->with('items.remoteOperation')
            ->where('public_id', $publicId)
            ->where('requested_by', $request->user()->id)
            ->first();
        if (! $run) {
            abort(404);
        }

        return $run;
    }

    /** @return array<string, mixed> */
    private function serializeRun(EmailConversationActionRun $run): array
    {
        $run->loadMissing('items.remoteOperation');

        return [
            'id' => $run->public_id,
            'operation' => $run->operation,
            'scope_kind' => $run->scope_kind,
            'status' => $run->status,
            'target_personal_unread' => $run->target_personal_unread,
            'provider_seen_requested' => $run->provider_seen_requested,
            'counts' => [
                'accounts' => $run->account_count,
                'items' => $run->item_count,
                'personal_applied' => $run->personal_applied_count,
                'provider_pending' => $run->provider_pending_count,
                'provider_succeeded' => $run->provider_succeeded_count,
                'denied' => $run->denied_count,
                'stale' => $run->stale_count,
                'failed' => $run->failed_count,
            ],
            'error_code' => $run->error_code,
            'expires_at' => $run->expires_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'items' => $run->items->map(fn (EmailConversationActionItem $item): array => [
                'id' => $item->public_id,
                'account_id' => $item->account_id,
                'personal' => [
                    'selected' => $item->personal_selected,
                    'status' => $item->personal_status,
                    'reason_code' => $item->personal_reason_code,
                ],
                'provider' => [
                    'selected' => $item->provider_selected,
                    'status' => $item->provider_status,
                    'reason_code' => $item->provider_reason_code,
                    'operation_id' => $item->remoteOperation?->public_id,
                ],
            ])->values()->all(),
        ];
    }
}
