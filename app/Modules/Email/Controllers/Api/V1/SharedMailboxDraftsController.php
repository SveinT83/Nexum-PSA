<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Email\Actions\SubmitEmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Models\EmailSharedDraftLock;
use App\Modules\Email\Resources\Api\V1\EmailComposerDraftResource;
use App\Modules\Email\Resources\Api\V1\EmailOutboundSubmissionResource;
use App\Modules\Email\Services\EmailCollaborationGate;
use App\Modules\Email\Services\EmailDraftConflictException;
use App\Modules\Email\Services\EmailDraftFence;
use App\Modules\Email\Services\EmailSharedDraftLeaseContext;
use App\Modules\Email\Services\EmailSharedDraftLockedException;
use App\Modules\Email\Services\EmailSharedDraftService;
use App\Modules\Email\Services\EmailSharedDraftStaleException;
use App\Modules\Email\Services\EmailSubmissionConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Email Mail collaboration', description: 'Default-off shared Mail coordination without content-bearing events.')]
class SharedMailboxDraftsController extends Controller
{
    #[OA\Post(path: '/api/v1/email/mailbox/drafts/{draft}/share', operationId: 'shareEmailMailboxDraft', summary: 'Explicitly share one exact private reply draft', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 201, description: 'Shared draft created'), new OA\Response(response: 404, description: 'Draft/source outside ordinary shared scope'), new OA\Response(response: 409, description: 'Draft version conflict'), new OA\Response(response: 503, description: 'Collaboration remains disabled')])]
    public function share(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $this->ensureAvailable($gate);
        $actor = $this->humanActor($request);
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:512'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        $candidate = EmailComposerDraft::query()
            ->with(['account', 'placement.message', 'placement.conversation'])
            ->where('public_id', $draft)
            ->where('user_id', $actor->id)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->firstOrFail();

        try {
            if ($candidate->scope === EmailComposerDraft::SCOPE_SHARED) {
                return new EmailComposerDraftResource($sharedDrafts->recoverShare(
                    $candidate,
                    $actor,
                    $validated['version'],
                    $validated['idempotency_key'],
                    $fence,
                ));
            }

            abort_unless($candidate->scope === EmailComposerDraft::SCOPE_PRIVATE, 404);
            $shared = $sharedDrafts->share(
                $candidate,
                $actor,
                $fence->version($candidate, $validated['version']),
                $validated['idempotency_key'],
            );
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailDraftConflictException $exception) {
            return $this->draftConflict($exception);
        }

        return (new EmailComposerDraftResource($shared))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/email/mailbox/shared-drafts/{draft}', operationId: 'showEmailSharedMailboxDraft', summary: 'Read one explicitly shared draft with current ordinary mailbox View', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Shared draft and safe lease state'), new OA\Response(response: 404, description: 'Shared draft outside current scope'), new OA\Response(response: 503, description: 'Collaboration remains disabled')])]
    public function show(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
    ): EmailComposerDraftResource {
        $this->ensureAvailable($gate);

        try {
            return new EmailComposerDraftResource(
                $sharedDrafts->readable($draft, $this->humanActor($request)),
            );
        } catch (AuthorizationException) {
            abort(404);
        }
    }

    #[OA\Post(path: '/api/v1/email/mailbox/shared-drafts/{draft}/lease', operationId: 'acquireEmailSharedDraftLease', summary: 'Acquire or explicitly take over an expired shared-draft lease', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Opaque lease token issued once'), new OA\Response(response: 409, description: 'Source context stale'), new OA\Response(response: 423, description: 'Another current editor holds the lease')])]
    public function acquire(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): JsonResponse {
        $this->ensureAvailable($gate);
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:512'],
            'content_version' => ['required', 'integer', 'min:1'],
            'source_version' => ['required', 'string', 'max:512'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        $actor = $this->humanActor($request);

        try {
            $current = $sharedDrafts->readable($draft, $actor);
            $this->assertOpenVersions($current, $fence, $validated);
            $lease = $sharedDrafts->acquire($current, $actor, $validated['idempotency_key']);
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        } catch (EmailSharedDraftStaleException $exception) {
            return $this->stale($exception);
        } catch (EmailDraftConflictException $exception) {
            return $this->draftConflict($exception);
        }

        return response()->json(['data' => [
            'draft' => new EmailComposerDraftResource(
                $lease['draft']->load(['attachments', 'sharedLock.holder']),
            ),
            'lease_token' => $lease['lease_token'],
            'fencing_token' => (int) $lease['lock']->fencing_token,
            'content_version' => (int) $lease['lock']->content_version,
            'source_version' => $sharedDrafts->sourceVersion($lease['draft']),
            'expires_at' => $lease['lock']->lease_expires_at,
        ]]);
    }

    #[OA\Patch(path: '/api/v1/email/mailbox/shared-drafts/{draft}/lease', operationId: 'renewEmailSharedDraftLease', summary: 'Renew the exact current lease no faster than the bounded floor', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Current lease state'), new OA\Response(response: 409, description: 'Source context stale'), new OA\Response(response: 423, description: 'Lease token/fence/version is no longer current')])]
    public function renew(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): JsonResponse {
        $this->ensureAvailable($gate);
        [$current, $context] = $this->mutationContext($request, $draft, $sharedDrafts, $fence);

        try {
            $result = $sharedDrafts->renew($current, $request->user(), $context);
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        } catch (EmailSharedDraftStaleException $exception) {
            return $this->stale($exception);
        }

        return response()->json(['data' => [
            'fencing_token' => (int) $result['lock']->fencing_token,
            'content_version' => (int) $result['lock']->content_version,
            'source_version' => $sharedDrafts->sourceVersion($result['draft']),
            'expires_at' => $result['lock']->lease_expires_at,
        ]]);
    }

    #[OA\Delete(path: '/api/v1/email/mailbox/shared-drafts/{draft}/lease', operationId: 'releaseEmailSharedDraftLease', summary: 'Release only the exact current lease', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Lease released'), new OA\Response(response: 423, description: 'Lease token/fence/version is no longer current')])]
    public function release(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): JsonResponse {
        $this->ensureAvailable($gate);
        [$current, $context, $validated] = $this->mutationContext($request, $draft, $sharedDrafts, $fence, true);

        try {
            $lock = $sharedDrafts->release(
                $current,
                $request->user(),
                $context,
                $validated['idempotency_key'],
            );
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        }

        return response()->json(['data' => $this->lockData($lock)]);
    }

    #[OA\Patch(path: '/api/v1/email/mailbox/shared-drafts/{draft}', operationId: 'updateEmailSharedDraft', summary: 'Update shared content under exact lease/fence/content/source versions', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Updated shared draft'), new OA\Response(response: 409, description: 'Source context stale'), new OA\Response(response: 423, description: 'Lease token/fence/version is no longer current')])]
    public function update(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $this->ensureAvailable($gate);
        [$current, $context, $validated] = $this->mutationContext($request, $draft, $sharedDrafts, $fence);
        $payload = $request->validate([
            'to' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cc' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:512'],
            'body_html' => ['sometimes', 'nullable', 'string', 'max:120000'],
        ]);

        try {
            return new EmailComposerDraftResource(
                $sharedDrafts->save($current, $request->user(), $context, $payload),
            );
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        } catch (EmailSharedDraftStaleException $exception) {
            return $this->stale($exception);
        }
    }

    public function storeAttachments(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $this->ensureAvailable($gate);
        [$current, $context] = $this->mutationContext($request, $draft, $sharedDrafts, $fence);
        $validated = $request->validate([
            'attachments' => ['required', 'array', 'min:1', 'max:5'],
            'attachments.*' => ['required', 'file', 'max:10240'],
        ]);

        try {
            return new EmailComposerDraftResource(
                $sharedDrafts->storeAttachments(
                    $current,
                    $request->user(),
                    $context,
                    $validated['attachments'],
                ),
            );
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        } catch (EmailSharedDraftStaleException $exception) {
            return $this->stale($exception);
        }
    }

    public function removeAttachment(
        Request $request,
        string $draft,
        string $attachment,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $this->ensureAvailable($gate);
        [$current, $context] = $this->mutationContext($request, $draft, $sharedDrafts, $fence);
        $attachment = EmailComposerDraftAttachment::query()
            ->where('public_id', $attachment)
            ->where('email_composer_draft_id', $current->id)
            ->where('draft_generation_id', $current->generation_id)
            ->firstOrFail();

        try {
            return new EmailComposerDraftResource(
                $sharedDrafts->removeAttachment(
                    $current,
                    $attachment,
                    $request->user(),
                    $context,
                ),
            );
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        } catch (EmailSharedDraftStaleException $exception) {
            return $this->stale($exception);
        }
    }

    #[OA\Post(path: '/api/v1/email/mailbox/shared-drafts/{draft}/rebase-preview', operationId: 'previewEmailSharedDraftRebase', summary: 'Preview current sender/audience/thread source without changing authored content', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Bounded rebase proposal'), new OA\Response(response: 423, description: 'Lease token/fence/version is no longer current')])]
    public function rebasePreview(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): JsonResponse {
        $this->ensureAvailable($gate);
        [$current, $context] = $this->mutationContext($request, $draft, $sharedDrafts, $fence);

        try {
            $proposal = $sharedDrafts->rebasePreview($current, $request->user(), $context);
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        }

        return response()->json(['data' => [
            'source_placement_id' => $proposal['source_placement_id'],
            'to' => $proposal['to'],
            'cc' => $proposal['cc'],
            'subject' => $proposal['subject'],
            'rebase_token' => $proposal['rebase_token'],
        ]]);
    }

    #[OA\Post(path: '/api/v1/email/mailbox/shared-drafts/{draft}/rebase', operationId: 'rebaseEmailSharedDraft', summary: 'Confirm one exact rebase while preserving authored body and attachments', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Rebased shared draft'), new OA\Response(response: 409, description: 'Rebase preview changed'), new OA\Response(response: 423, description: 'Lease token/fence/version is no longer current')])]
    public function rebase(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $this->ensureAvailable($gate);
        [$current, $context, $validated] = $this->mutationContext($request, $draft, $sharedDrafts, $fence, true);
        $extra = $request->validate(['rebase_token' => ['required', 'string', 'max:512']]);

        try {
            return new EmailComposerDraftResource($sharedDrafts->rebase(
                $current,
                $request->user(),
                $context,
                $extra['rebase_token'],
                $validated['idempotency_key'],
            ));
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        } catch (EmailSharedDraftStaleException $exception) {
            return $this->stale($exception);
        }
    }

    #[OA\Post(path: '/api/v1/email/mailbox/shared-drafts/{draft}/preview', operationId: 'previewEmailSharedDraftSend', summary: 'Preview the exact Order 11 outbound snapshot under the current lease', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Safe exact outbound preview'), new OA\Response(response: 409, description: 'Source context stale'), new OA\Response(response: 423, description: 'Lease token/fence/version is no longer current')])]
    public function preview(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
        SubmitEmailComposerDraft $submissions,
    ): JsonResponse {
        $this->ensureAvailable($gate);
        [$current, $context] = $this->mutationContext($request, $draft, $sharedDrafts, $fence);

        try {
            $preview = $submissions->preview($current, $request->user(), $context);
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        } catch (EmailSharedDraftStaleException $exception) {
            return $this->stale($exception);
        }

        return response()->json(['data' => [
            'draft_id' => $current->public_id,
            'version' => $fence->issue($preview['draft']),
            'content_version' => (int) $preview['draft']->content_version,
            'source_version' => $sharedDrafts->sourceVersion($preview['draft']),
            'to' => collect($preview['to'])->pluck('email')->all(),
            'cc' => collect($preview['cc'])->pluck('email')->all(),
            'subject' => $preview['subject'],
            'body_html' => $preview['body_html'],
            'body_text' => $preview['body_text'],
            'threading' => $preview['threading'],
            'signature' => $preview['signature'],
            'attachments' => collect($preview['attachments'])->map(fn (array $item): array => [
                'id' => $item['id'],
                'filename' => $item['filename'],
                'content_type' => $item['content_type'],
                'size_bytes' => $item['size_bytes'],
                'position' => $item['position'],
            ])->all(),
        ]]);
    }

    #[OA\Post(path: '/api/v1/email/mailbox/shared-drafts/{draft}/send', operationId: 'sendEmailSharedDraft', summary: 'Reauthorize, fence and submit the shared snapshot through Order 11 once', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Accepted or reconciled submission'), new OA\Response(response: 409, description: 'Stale or submission conflict; never permission to resend'), new OA\Response(response: 423, description: 'Lease token/fence/version is no longer current')])]
    public function send(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
        SubmitEmailComposerDraft $submissions,
    ): EmailOutboundSubmissionResource|JsonResponse {
        $this->ensureAvailable($gate);
        $actor = $this->humanActor($request);
        $validated = $request->validate([
            ...$this->leaseRules(),
            'version' => ['required', 'string', 'max:512'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $current = $sharedDrafts->readable($draft, $actor, true);
            $expectedVersion = $fence->version($current, $validated['version']);
            $submission = $submissions->submit(
                $current,
                $actor,
                $validated['idempotency_key'],
                SubmitEmailComposerDraft::CHANNEL_API,
                $expectedVersion,
                $this->leaseContext($validated),
            );
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailDraftConflictException $exception) {
            return $this->draftConflict($exception);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        } catch (EmailSharedDraftStaleException $exception) {
            return $this->stale($exception);
        } catch (EmailSubmissionConflictException $exception) {
            return response()->json([
                'error' => ['code' => 'email_submission_conflict', 'message' => $exception->getMessage()],
                'data' => new EmailOutboundSubmissionResource(
                    $exception->submission->load(['draft', 'emailLog', 'sentReconciliation']),
                ),
            ], 409);
        }

        return new EmailOutboundSubmissionResource($submission);
    }

    public function discard(
        Request $request,
        string $draft,
        EmailCollaborationGate $gate,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $this->ensureAvailable($gate);
        [$current, $context, $validated] = $this->mutationContext($request, $draft, $sharedDrafts, $fence, true);

        try {
            return new EmailComposerDraftResource($sharedDrafts->discard(
                $current,
                $request->user(),
                $context,
                $validated['idempotency_key'],
            ));
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailSharedDraftLockedException $exception) {
            return $this->locked($exception);
        } catch (EmailSharedDraftStaleException $exception) {
            return $this->stale($exception);
        }
    }

    /** @return array{EmailComposerDraft, EmailSharedDraftLeaseContext, array<string, mixed>} */
    private function mutationContext(
        Request $request,
        string $publicId,
        EmailSharedDraftService $sharedDrafts,
        EmailDraftFence $fence,
        bool $requiresIdempotency = false,
    ): array {
        $validated = $request->validate([
            ...$this->leaseRules(),
            'version' => ['required', 'string', 'max:512'],
            'idempotency_key' => [$requiresIdempotency ? 'required' : 'nullable', 'string', 'max:120'],
        ]);
        try {
            $current = $sharedDrafts->readable($publicId, $this->humanActor($request));
            $expected = $fence->version($current, $validated['version']);
        } catch (AuthorizationException) {
            abort(404);
        } catch (EmailDraftConflictException $exception) {
            throw new HttpResponseException($this->draftConflict($exception));
        }

        if ($expected !== (int) $current->version) {
            throw new HttpResponseException($this->draftConflict(
                new EmailDraftConflictException($current),
            ));
        }

        return [$current, $this->leaseContext($validated), $validated];
    }

    /** @return array<string, array<int, mixed>> */
    private function leaseRules(): array
    {
        return [
            'lease_token' => ['required', 'string', 'min:32', 'max:256'],
            'fencing_token' => ['required', 'integer', 'min:1'],
            'content_version' => ['required', 'integer', 'min:1'],
            'source_version' => ['required', 'string', 'max:512'],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function leaseContext(array $validated): EmailSharedDraftLeaseContext
    {
        return new EmailSharedDraftLeaseContext(
            $validated['lease_token'],
            (int) $validated['fencing_token'],
            (int) $validated['content_version'],
            $validated['source_version'],
        );
    }

    /** @param array<string, mixed> $validated */
    private function assertOpenVersions(
        EmailComposerDraft $draft,
        EmailDraftFence $fence,
        array $validated,
    ): void {
        if ($fence->version($draft, $validated['version']) !== (int) $draft->version
            || (int) $validated['content_version'] !== (int) $draft->content_version
            || ! hash_equals($this->sharedSourceVersion($draft), $validated['source_version'])) {
            throw new EmailDraftConflictException($draft);
        }
    }

    private function sharedSourceVersion(EmailComposerDraft $draft): string
    {
        return app(EmailSharedDraftService::class)->sourceVersion($draft);
    }

    private function locked(EmailSharedDraftLockedException $exception): JsonResponse
    {
        $lock = $exception->lock;

        return response()->json([
            'error' => ['code' => $exception->safeCode, 'message' => $exception->getMessage()],
            'data' => [
                'draft' => $exception->draft
                    ? new EmailComposerDraftResource(
                        $exception->draft->load(['attachments', 'sharedLock.holder']),
                    )
                    : null,
                'lease' => $lock ? $this->lockData($lock) : null,
            ],
        ], 423);
    }

    private function stale(EmailSharedDraftStaleException $exception): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $exception->safeCode, 'message' => $exception->getMessage()],
            'data' => new EmailComposerDraftResource(
                $exception->draft->load(['attachments', 'sharedLock.holder']),
            ),
        ], 409);
    }

    private function draftConflict(EmailDraftConflictException $exception): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'email_draft_version_conflict', 'message' => $exception->getMessage()],
            'data' => $exception->currentDraft
                ? new EmailComposerDraftResource(
                    $exception->currentDraft->load(['attachments', 'sharedLock.holder']),
                )
                : null,
        ], 409);
    }

    /** @return array<string, mixed> */
    private function lockData(EmailSharedDraftLock $lock): array
    {
        $lock->loadMissing('holder');

        return [
            'id' => $lock->public_id,
            'active' => $lock->isActive(),
            'fencing_token' => (int) $lock->fencing_token,
            'content_version' => (int) $lock->content_version,
            'expires_at' => $lock->lease_expires_at,
            'holder' => $lock->holder ? [
                'id' => (int) $lock->holder->id,
                'name' => (string) $lock->holder->name,
            ] : null,
        ];
    }

    private function humanActor(Request $request): User
    {
        $actor = $request->user();
        abort_if(! $actor?->isActive() || $actor->isSystemActor(), 403);

        return $actor;
    }

    private function ensureAvailable(EmailCollaborationGate $gate): void
    {
        abort_unless($gate->available(), 503, 'Mail collaboration is not available.');
    }
}
