<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Resources\Api\V1\EmailComposerDraftResource;
use App\Modules\Email\Services\EmailComposerDraftService;
use App\Modules\Email\Services\EmailDraftConflictException;
use App\Modules\Email\Services\EmailDraftFence;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Email Mail drafts', description: 'Private, fenced Mail drafts and idempotent submission.')]
class MailboxDraftAttachmentsController extends Controller
{
    #[OA\Post(path: '/api/v1/email/mailbox/drafts/{draft}/attachments', operationId: 'storeEmailMailboxDraftAttachments', summary: 'Upload bounded private attachments to an exact draft version', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], parameters: [new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Draft with safe attachment metadata'), new OA\Response(response: 409, description: 'Opaque draft version conflict'), new OA\Response(response: 422, description: 'Attachment validation failed')])]
    public function store(
        Request $request,
        string $draft,
        EmailComposerDraftService $drafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $current = $this->visibleDraft($request, $draft);
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:512'],
            'attachments' => ['required', 'array', 'min:1', 'max:5'],
            'attachments.*' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $saved = $drafts->storeAttachments(
                $request->user(),
                $current,
                $validated['attachments'],
                $fence->version($current, $validated['version']),
            );
        } catch (EmailDraftConflictException $exception) {
            return $this->conflict($exception);
        }

        return new EmailComposerDraftResource($saved);
    }

    #[OA\Delete(path: '/api/v1/email/mailbox/drafts/{draft}/attachments/{attachment}', operationId: 'deleteEmailMailboxDraftAttachment', summary: 'Remove one exact-generation private draft attachment', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], parameters: [new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'attachment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Draft after attachment removal'), new OA\Response(response: 404, description: 'Draft or attachment outside current scope'), new OA\Response(response: 409, description: 'Opaque draft version conflict')])]
    public function destroy(
        Request $request,
        string $draft,
        string $attachment,
        EmailComposerDraftService $drafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $current = $this->visibleDraft($request, $draft);
        $validated = $request->validate(['version' => ['required', 'string', 'max:512']]);
        $attachment = EmailComposerDraftAttachment::query()
            ->where('public_id', $attachment)
            ->where('email_composer_draft_id', $current->id)
            ->where('draft_generation_id', $current->generation_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $saved = $drafts->removeAttachment(
                $request->user(),
                $attachment,
                $fence->version($current, $validated['version']),
            );
        } catch (EmailDraftConflictException $exception) {
            return $this->conflict($exception);
        }

        return new EmailComposerDraftResource($saved);
    }

    private function visibleDraft(Request $request, string $publicId): EmailComposerDraft
    {
        $actor = $request->user();
        abort_if(! $actor?->isActive() || $actor->isSystemActor(), 403);
        $draft = EmailComposerDraft::query()
            ->with(['account', 'placement'])
            ->where('public_id', $publicId)
            ->where('user_id', $actor->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->firstOrFail();
        $access = app(MailboxAccess::class);
        $needsView = $draft->mode !== SendEmailComposerMessage::MODE_COMPOSE;
        $hasCurrentSource = ! $needsView
            || ($draft->placement?->message?->hasActiveProviderPlacement($draft->placement) ?? false);

        abort_if(
            ! $draft->account
            || ! $hasCurrentSource
            || ! $access->canAccessAccount($actor, $draft->account, MailboxAccess::SEND)
            || ($needsView && ! $access->canAccessAccount($actor, $draft->account, MailboxAccess::VIEW)),
            404,
        );

        return $draft;
    }

    private function conflict(EmailDraftConflictException $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'email_draft_version_conflict',
                'message' => $exception->getMessage(),
            ],
            'data' => $exception->currentDraft
                ? new EmailComposerDraftResource($exception->currentDraft->load('attachments'))
                : null,
        ], 409);
    }
}
