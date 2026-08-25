<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Actions\SubmitEmailComposerDraft;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Resources\Api\V1\EmailComposerDraftResource;
use App\Modules\Email\Resources\Api\V1\EmailOutboundSubmissionResource;
use App\Modules\Email\Services\EmailComposerDraftService;
use App\Modules\Email\Services\EmailDraftConflictException;
use App\Modules\Email\Services\EmailDraftFence;
use App\Modules\Email\Services\EmailSubmissionConflictException;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Email Mail drafts', description: 'Private, fenced Mail drafts and idempotent submission.')]
class MailboxDraftsController extends Controller
{
    #[OA\Get(path: '/api/v1/email/mailbox/drafts', operationId: 'listEmailMailboxDrafts', summary: 'List current private Mail drafts', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], responses: [new OA\Response(response: 200, description: 'Authorized private drafts'), new OA\Response(response: 403, description: 'Missing email.drafts.read ability')])]
    public function index(Request $request, MailboxAccess $mailboxAccess): AnonymousResourceCollection
    {
        $actor = $this->humanActor($request);
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $sendAccountIds = $mailboxAccess->scopeAccounts(
            EmailAccount::query(),
            $actor,
            MailboxAccess::SEND,
        )->pluck('id');
        $viewAccountIds = $mailboxAccess->scopeAccounts(
            EmailAccount::query(),
            $actor,
            MailboxAccess::VIEW,
        )->pluck('id');

        if (isset($validated['account_id'])
            && ! $sendAccountIds->contains((int) $validated['account_id'])) {
            abort(404);
        }

        $drafts = EmailComposerDraft::query()
            ->with('attachments')
            ->where('user_id', $actor->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->whereIn('email_account_id', $sendAccountIds)
            ->where(function ($query) use ($viewAccountIds): void {
                $query->where('mode', SendEmailComposerMessage::MODE_COMPOSE)
                    ->orWhere(function ($replyDrafts) use ($viewAccountIds): void {
                        $replyDrafts
                            ->whereIn('email_account_id', $viewAccountIds)
                            ->whereHas('placement', function ($placements): void {
                                $placements
                                    ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                                    ->whereNull('provider_missing_at');
                            });
                    });
            })
            ->when(
                isset($validated['account_id']),
                fn ($query) => $query->where('email_account_id', $validated['account_id']),
            )
            ->latest('last_saved_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return EmailComposerDraftResource::collection($drafts);
    }

    #[OA\Post(path: '/api/v1/email/mailbox/drafts', operationId: 'createEmailMailboxDraft', summary: 'Create one private fenced Mail draft', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], responses: [new OA\Response(response: 201, description: 'Private draft created'), new OA\Response(response: 404, description: 'Mailbox or source placement outside current scope'), new OA\Response(response: 422, description: 'Validation error or unsupported shared scope')])]
    public function store(
        Request $request,
        EmailComposerDraftService $drafts,
        SendEmailComposerMessage $composer,
        MailboxAccess $mailboxAccess,
    ): JsonResponse {
        $actor = $this->humanActor($request);
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in([EmailComposerDraft::SCOPE_PRIVATE])],
            'account_id' => ['required', 'integer'],
            'source_placement_id' => ['nullable', 'integer'],
            'mode' => ['required', Rule::in([
                SendEmailComposerMessage::MODE_COMPOSE,
                SendEmailComposerMessage::MODE_REPLY,
                SendEmailComposerMessage::MODE_REPLY_ALL,
                SendEmailComposerMessage::MODE_FORWARD,
                SendEmailComposerMessage::MODE_PROVIDER_DRAFT,
            ])],
            'to' => ['nullable', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:512'],
            'body_html' => ['nullable', 'string', 'max:120000'],
        ]);
        $account = $mailboxAccess->scopeAccounts(
            EmailAccount::query(),
            $actor,
            MailboxAccess::SEND,
        )->findOrFail($validated['account_id']);
        $placement = isset($validated['source_placement_id'])
            ? EmailMailboxPlacement::query()
                ->with(['account', 'folder', 'message.attachments'])
                ->whereKey($validated['source_placement_id'])
                ->where('account_id', $account->id)
                ->firstOrFail()
            : null;

        if ($validated['mode'] !== SendEmailComposerMessage::MODE_COMPOSE) {
            abort_if(
                ! $placement?->message
                || ! $placement->message->hasActiveProviderPlacement($placement)
                || ! $mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::VIEW),
                404,
            );
        }

        try {
            if ($validated['mode'] === SendEmailComposerMessage::MODE_PROVIDER_DRAFT) {
                abort_if(! $placement, 404);
                $draft = $drafts->captureProviderDraftPlacement($actor, $placement);
            } else {
                $payload = $this->draftDefaults($validated, $placement, $composer);
                $draft = $drafts->save(
                    $actor,
                    $validated['mode'],
                    $account,
                    $placement,
                    $payload,
                );
            }
        } catch (EmailDraftConflictException $exception) {
            return $this->draftConflict($exception);
        }

        return (new EmailComposerDraftResource($draft->load('attachments')))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/email/mailbox/drafts/{draft}', operationId: 'showEmailMailboxDraft', summary: 'Show one authorized private Mail draft', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], parameters: [new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Private draft'), new OA\Response(response: 404, description: 'Draft outside current scope')])]
    public function show(Request $request, string $draft): EmailComposerDraftResource
    {
        return new EmailComposerDraftResource(
            $this->visibleDraft($request, $draft)->load('attachments'),
        );
    }

    #[OA\Patch(path: '/api/v1/email/mailbox/drafts/{draft}', operationId: 'updateEmailMailboxDraft', summary: 'Update an exact fenced private draft version', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], parameters: [new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Draft updated'), new OA\Response(response: 409, description: 'Opaque draft version conflict'), new OA\Response(response: 422, description: 'Validation error')])]
    public function update(
        Request $request,
        string $draft,
        EmailComposerDraftService $drafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $current = $this->visibleDraft($request, $draft);
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:512'],
            'to' => ['nullable', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:512'],
            'body_html' => ['nullable', 'string', 'max:120000'],
        ]);

        try {
            $expectedVersion = $fence->version($current, $validated['version']);
            $saved = $drafts->save(
                $request->user(),
                $current->mode,
                $current->account,
                $current->placement,
                [
                    'to' => $validated['to'] ?? $current->to_recipients,
                    'cc' => $validated['cc'] ?? $current->cc_recipients,
                    'subject' => $validated['subject'] ?? $current->subject,
                    'body_html' => $validated['body_html'] ?? $current->body_html,
                    'idempotency_key' => $current->idempotency_key,
                ],
                false,
                $expectedVersion,
            );
        } catch (EmailDraftConflictException $exception) {
            return $this->draftConflict($exception);
        }

        return new EmailComposerDraftResource($saved->load('attachments'));
    }

    #[OA\Delete(path: '/api/v1/email/mailbox/drafts/{draft}', operationId: 'discardEmailMailboxDraft', summary: 'Discard an exact fenced private draft version', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], parameters: [new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Draft discarded'), new OA\Response(response: 409, description: 'Opaque draft version conflict')])]
    public function discard(
        Request $request,
        string $draft,
        EmailComposerDraftService $drafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $current = $this->visibleDraft($request, $draft);
        $validated = $request->validate(['version' => ['required', 'string', 'max:512']]);

        try {
            $discarded = $drafts->discardDraft(
                $request->user(),
                $current,
                $fence->version($current, $validated['version']),
            );
        } catch (EmailDraftConflictException $exception) {
            return $this->draftConflict($exception);
        }

        return new EmailComposerDraftResource($discarded->load('attachments'));
    }

    #[OA\Post(path: '/api/v1/email/mailbox/drafts/{draft}/provider-sync', operationId: 'syncEmailMailboxProviderDraft', summary: 'Explicitly synchronize an exact private draft to provider Drafts', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], parameters: [new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Current provider Drafts status'), new OA\Response(response: 409, description: 'Version or provider evidence conflict')])]
    public function syncProvider(
        Request $request,
        string $draft,
        EmailComposerDraftService $drafts,
        EmailDraftFence $fence,
    ): EmailComposerDraftResource|JsonResponse {
        $current = $this->visibleDraft($request, $draft);
        $validated = $request->validate(['version' => ['required', 'string', 'max:512']]);

        try {
            $synced = $drafts->syncProviderDraft(
                $request->user(),
                $current,
                $fence->version($current, $validated['version']),
            );
        } catch (EmailDraftConflictException $exception) {
            return $this->draftConflict($exception);
        }

        return new EmailComposerDraftResource($synced->load('attachments'));
    }

    #[OA\Post(path: '/api/v1/email/mailbox/drafts/{draft}/preview', operationId: 'previewEmailMailboxDraftSubmission', summary: 'Preview the exact sanitized and signed outbound snapshot', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], parameters: [new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Safe outbound preview without provider write'), new OA\Response(response: 409, description: 'Opaque draft version conflict'), new OA\Response(response: 422, description: 'Outbound validation failed')])]
    public function preview(
        Request $request,
        string $draft,
        SubmitEmailComposerDraft $submissions,
        EmailDraftFence $fence,
    ): JsonResponse {
        $current = $this->visibleDraft($request, $draft);
        $validated = $request->validate(['version' => ['required', 'string', 'max:512']]);

        try {
            $expectedVersion = $fence->version($current, $validated['version']);
            $preview = $submissions->preview($current, $request->user());

            if ((int) $preview['draft']->version !== $expectedVersion) {
                throw new EmailDraftConflictException($preview['draft']);
            }
        } catch (EmailDraftConflictException $exception) {
            return $this->draftConflict($exception);
        }

        return response()->json(['data' => [
            'draft_id' => $current->public_id,
            'version' => $fence->issue($preview['draft']),
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

    #[OA\Post(path: '/api/v1/email/mailbox/drafts/{draft}/send', operationId: 'submitEmailMailboxDraft', summary: 'Reserve and send one exact private draft snapshot', description: 'A timeout or conflict is not permission to resend. Accepted repeats return the same submission; unresolved outcomes forbid another provider write.', security: [['bearerAuth' => []]], tags: ['Email Mail drafts'], parameters: [new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Outbound submission created and accepted'), new OA\Response(response: 200, description: 'Existing accepted submission returned idempotently'), new OA\Response(response: 409, description: 'Version, idempotency, or provider outcome conflict'), new OA\Response(response: 422, description: 'Outbound validation failed')])]
    public function send(
        Request $request,
        string $draft,
        SubmitEmailComposerDraft $submissions,
        EmailDraftFence $fence,
    ): EmailOutboundSubmissionResource|JsonResponse {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:512'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        $current = $this->submissionDraft($request, $draft);

        try {
            $submission = $submissions->submit(
                $current,
                $request->user(),
                $validated['idempotency_key'],
                SubmitEmailComposerDraft::CHANNEL_API,
                $fence->version($current, $validated['version']),
            );
        } catch (EmailDraftConflictException $exception) {
            return $this->draftConflict($exception);
        } catch (EmailSubmissionConflictException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'email_submission_conflict',
                    'message' => $exception->getMessage(),
                ],
                'data' => new EmailOutboundSubmissionResource(
                    $exception->submission->load(['draft', 'emailLog', 'sentReconciliation']),
                ),
            ], 409);
        }

        return new EmailOutboundSubmissionResource($submission);
    }

    private function humanActor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor?->isActive() || $actor->isSystemActor(), 403);

        return $actor;
    }

    private function visibleDraft(Request $request, string $publicId): EmailComposerDraft
    {
        $actor = $this->humanActor($request);
        $draft = EmailComposerDraft::query()
            ->with(['account', 'placement.message'])
            ->where('public_id', $publicId)
            ->where('user_id', $actor->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->firstOrFail();
        $access = app(MailboxAccess::class);
        $canSend = $draft->account
            && $access->canAccessAccount($actor, $draft->account, MailboxAccess::SEND);
        $needsView = $draft->mode !== SendEmailComposerMessage::MODE_COMPOSE;
        $hasCurrentSource = ! $needsView
            || ($draft->placement?->message?->hasActiveProviderPlacement($draft->placement) ?? false);

        abort_if(! $canSend || ! $hasCurrentSource || ($needsView && ! $access->canAccessAccount(
            $actor,
            $draft->account,
            MailboxAccess::VIEW,
        )), 404);

        return $draft;
    }

    /**
     * Accepted and unresolved submissions retain their draft resource identity
     * for exact idempotent replay and status recovery. All ordinary draft
     * endpoints continue to expose active private drafts only.
     */
    private function submissionDraft(Request $request, string $publicId): EmailComposerDraft
    {
        $actor = $this->humanActor($request);
        $draft = EmailComposerDraft::query()
            ->with(['account', 'placement.message'])
            ->where('public_id', $publicId)
            ->where('user_id', $actor->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->whereIn('status', [
                EmailComposerDraft::STATUS_ACTIVE,
                EmailComposerDraft::STATUS_SEND_RESERVED,
                EmailComposerDraft::STATUS_SENT,
            ])
            ->firstOrFail();
        $access = app(MailboxAccess::class);
        $needsView = $draft->mode !== SendEmailComposerMessage::MODE_COMPOSE;

        abort_if(
            ! $draft->account
            || ! $access->canAccessAccount($actor, $draft->account, MailboxAccess::SEND)
            || ($needsView && ! $access->canAccessAccount($actor, $draft->account, MailboxAccess::VIEW)),
            404,
        );

        return $draft;
    }

    /** @return array<string, mixed> */
    private function draftDefaults(
        array $validated,
        ?EmailMailboxPlacement $placement,
        SendEmailComposerMessage $composer,
    ): array {
        $mode = $validated['mode'];

        if ($mode !== SendEmailComposerMessage::MODE_COMPOSE) {
            abort_if(! $placement?->message, 404);
        }

        $replyAll = $mode === SendEmailComposerMessage::MODE_REPLY_ALL
            ? $composer->defaultReplyAllRecipientFields($placement->message, $placement->account)
            : ['to' => '', 'cc' => ''];

        return [
            'to' => $validated['to'] ?? match ($mode) {
                SendEmailComposerMessage::MODE_REPLY => $placement->message->from_email,
                SendEmailComposerMessage::MODE_REPLY_ALL => $replyAll['to'],
                default => '',
            },
            'cc' => $validated['cc'] ?? ($mode === SendEmailComposerMessage::MODE_REPLY_ALL ? $replyAll['cc'] : ''),
            'subject' => $validated['subject'] ?? match ($mode) {
                SendEmailComposerMessage::MODE_FORWARD => $composer->defaultForwardSubject($placement->message),
                SendEmailComposerMessage::MODE_REPLY,
                SendEmailComposerMessage::MODE_REPLY_ALL => $composer->defaultReplySubject($placement->message),
                default => '',
            },
            'body_html' => $validated['body_html'] ?? match ($mode) {
                SendEmailComposerMessage::MODE_FORWARD => $composer->defaultForwardBodyHtml($placement->message),
                SendEmailComposerMessage::MODE_REPLY,
                SendEmailComposerMessage::MODE_REPLY_ALL => $composer->defaultReplyBodyHtml(),
                default => '',
            },
        ];
    }

    private function draftConflict(EmailDraftConflictException $exception): JsonResponse
    {
        $current = $exception->currentDraft;

        return response()->json([
            'error' => [
                'code' => 'email_draft_version_conflict',
                'message' => $exception->getMessage(),
            ],
            'data' => $current
                ? new EmailComposerDraftResource($current->load('attachments'))
                : null,
        ], 409);
    }
}
