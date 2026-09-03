<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Models\EmailRuleReprocessRun;
use App\Modules\Email\Services\EmailRuleDraftService;
use App\Modules\Email\Services\EmailRulePublisher;
use App\Modules\Email\Services\EmailRuleReprocessService;
use App\Modules\Email\Services\EmailRuleReversalService;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RulesController extends Controller
{
    public function createDraft(Request $request, EmailRuleDraftService $drafts): JsonResponse
    {
        $this->authorizeRuleWrite($request);
        $payload = $this->validatedDraftPayload($request);
        $rule = EmailRule::query()->create([
            'name' => $payload['name'],
            'description' => null,
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'rule_kind' => EmailRule::KIND_ADMIN,
            'weight' => 10,
            'is_active' => false,
            'lifecycle_status' => EmailRule::LIFECYCLE_DRAFT,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $draft = $drafts->save($rule, $payload, $request->user());

        return response()->json(['data' => $this->serializeDraft($draft)], 201);
    }

    public function draft(Request $request, EmailRule $rule): JsonResponse
    {
        $this->authorizeRuleRead($request);
        abort_unless($rule->isAdminManaged(), 404);
        $draft = $rule->draft()->firstOrFail();

        return response()->json(['data' => $this->serializeDraft($draft)]);
    }

    public function saveDraft(Request $request, EmailRule $rule, EmailRuleDraftService $drafts): JsonResponse
    {
        $this->authorizeRuleWrite($request);
        abort_unless($rule->isAdminManaged(), 404);
        $payload = $this->validatedDraftPayload($request);
        $draft = $drafts->save($rule, $payload, $request->user(), $request->integer('lock_version') ?: null);

        return response()->json(['data' => $this->serializeDraft($draft)]);
    }

    public function validateDraft(Request $request, EmailRule $rule, EmailRuleDraftService $drafts): JsonResponse
    {
        $this->authorizeRuleWrite($request);
        abort_unless($rule->isAdminManaged(), 404);
        $payload = $this->validatedDraftPayload($request);

        return response()->json(['data' => [
            'valid' => true,
            'account_count' => count($payload['account_ids']),
            'action_count' => count($payload['actions_json']),
            'condition_group_count' => count($payload['conditions_json']['groups'] ?? []),
        ]]);
    }

    public function publishPreview(Request $request, EmailRule $rule, EmailRuleDraftService $drafts): JsonResponse
    {
        $this->authorizeRuleWrite($request);
        abort_unless($rule->isAdminManaged(), 404);

        return response()->json(['data' => $drafts->publicationPreview($rule)]);
    }

    public function publish(
        Request $request,
        EmailRule $rule,
        EmailRulePublisher $publisher,
    ): JsonResponse {
        $this->authorizeRulePublish($request);
        abort_unless($rule->isAdminManaged(), 404);
        $data = $request->validate(['draft_checksum' => ['required', 'string', 'size:64']]);
        $version = $publisher->publishDraft($rule, $request->user(), $data['draft_checksum']);

        return response()->json(['data' => $this->serializeVersion($version)], 201);
    }

    public function versions(Request $request, EmailRule $rule): JsonResponse
    {
        $this->authorizeRuleRead($request);
        abort_unless($rule->isAdminManaged(), 404);

        return response()->json(['data' => $rule->versions()->latest('version_number')->get()->map(
            fn ($version): array => $this->serializeVersion($version),
        )->values()]);
    }

    public function reprocessPreview(
        Request $request,
        EmailRule $rule,
        EmailRuleReprocessService $runs,
    ): JsonResponse {
        $this->authorizeRuleReprocess($request);
        abort_unless($rule->isAdminManaged(), 404);
        $selection = $request->validate([
            'account_id' => ['required', 'integer', 'exists:email_accounts,id'],
            'message_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'message_ids.*' => ['integer'],
            'folder_id' => ['nullable', 'integer', 'exists:email_folders,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'utc_date' => ['nullable', 'date_format:Y-m-d'],
            'cap' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $run = $runs->preview($rule, $request->user(), $selection);

        return response()->json(['data' => $this->serializeRun($run)], 201);
    }

    public function run(Request $request, EmailRuleReprocessRun $run, EmailRuleReprocessService $runs): JsonResponse
    {
        $this->authorizeRuleRead($request);
        $runs->authorizeRun($run, $request->user());

        return response()->json(['data' => $this->serializeRun($run->load('items'))]);
    }

    public function applyRun(Request $request, EmailRuleReprocessRun $run, EmailRuleReprocessService $runs): JsonResponse
    {
        $this->authorizeRuleReprocess($request);

        return response()->json(['data' => $this->serializeRun($runs->apply($run, $request->user()))], 202);
    }

    public function cancelRun(Request $request, EmailRuleReprocessRun $run, EmailRuleReprocessService $runs): JsonResponse
    {
        $this->authorizeRuleReprocess($request);

        return response()->json(['data' => $this->serializeRun($runs->cancel($run, $request->user()))]);
    }

    public function retryRun(Request $request, EmailRuleReprocessRun $run, EmailRuleReprocessService $runs): JsonResponse
    {
        $this->authorizeRuleReprocess($request);

        return response()->json(['data' => $this->serializeRun($runs->repeat($run->load('items'), $request->user(), false))], 202);
    }

    public function fullRerun(Request $request, EmailRuleReprocessRun $run, EmailRuleReprocessService $runs): JsonResponse
    {
        $this->authorizeRuleReprocess($request);
        $request->validate(['confirmation' => ['required', Rule::in(['FULL RERUN'])]]);

        return response()->json(['data' => $this->serializeRun($runs->repeat($run->load('items'), $request->user(), true))], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeRuleRead($request);

        $rules = EmailRule::query()
            ->adminManaged()
            ->with(['accounts:id,address,account_kind', 'publishedVersion'])
            ->orderBy('weight')
            ->orderBy('id')
            ->get()
            ->map(fn (EmailRule $rule): array => $this->serializeRule($rule))
            ->values();

        return response()->json(['data' => $rules]);
    }

    public function show(Request $request, EmailRule $rule): JsonResponse
    {
        $this->authorizeRuleRead($request);
        abort_unless($rule->isAdminManaged(), 404);

        $rule->load(['accounts:id,address,account_kind', 'publishedVersion']);

        return response()->json(['data' => $this->serializeRule($rule, includeSnapshot: true)]);
    }

    public function preview(
        Request $request,
        EmailRule $rule,
        InboundEmailRuleEngine $ruleEngine,
        MailboxAccess $mailboxAccess,
    ): JsonResponse {
        $this->authorizeRuleRead($request);
        abort_unless($rule->isAdminManaged(), 404);

        $data = $request->validate([
            'email_message_id' => 'required|integer|exists:email_messages,id',
        ]);

        $message = EmailMessage::query()
            ->with(['account', 'latestPlacement'])
            ->findOrFail($data['email_message_id']);

        if (! $mailboxAccess->canViewMessage($request->user(), $message)) {
            abort(404);
        }

        return response()->json([
            'data' => $ruleEngine->previewRule($rule, $message),
        ]);
    }

    public function execution(
        Request $request,
        EmailRuleExecutionAttempt $attempt,
        MailboxAccess $mailboxAccess,
        EmailRuleReversalService $reversalService,
    ): JsonResponse {
        $this->authorizeRuleRead($request);
        $this->assertExecutionVisible($request, $attempt, $mailboxAccess);

        return response()->json([
            'data' => $this->serializeExecution(
                $attempt,
                $reversalService->eligibility($attempt, $request->user()),
            ),
        ]);
    }

    public function undoEligibility(
        Request $request,
        EmailRuleExecutionAttempt $attempt,
        MailboxAccess $mailboxAccess,
        EmailRuleReversalService $reversalService,
    ): JsonResponse {
        $this->authorizeRuleRead($request);
        $this->assertExecutionVisible($request, $attempt, $mailboxAccess);

        return response()->json([
            'data' => $this->serializeUndoEligibility(
                $reversalService->eligibility($attempt, $request->user()),
            ),
        ]);
    }

    public function undo(
        Request $request,
        EmailRuleExecutionAttempt $attempt,
        MailboxAccess $mailboxAccess,
        EmailRuleReversalService $reversalService,
    ): JsonResponse {
        $this->authorizeRuleRead($request);
        $this->assertExecutionVisible($request, $attempt, $mailboxAccess);

        $inverse = $reversalService->revert($attempt, $request->user());

        return response()->json(['data' => [
            'execution_attempt_id' => $attempt->id,
            'source_remote_operation_id' => $inverse->inverse_of_email_remote_operation_id,
            'inverse_remote_operation_id' => $inverse->id,
            'inverse_operation_type' => $inverse->operation_type,
            'status' => $inverse->status,
            'reason_code' => $this->safeReasonCode(
                $inverse->status_reason_code ?: $inverse->error_code,
            ) ?: 'EMAIL_RULE_UNDO_'.strtoupper($inverse->status),
            'reason_message' => $this->undoStatusMessage($inverse->status),
            'created_at' => $inverse->created_at?->toIso8601String(),
            'acknowledged_at' => $inverse->acknowledged_at?->toIso8601String(),
        ]]);
    }

    private function authorizeRuleRead(Request $request): void
    {
        abort_unless($request->user()?->can('email.rule_manage'), 403);
    }

    private function authorizeRuleWrite(Request $request): void
    {
        abort_unless($request->user()?->isActive() && $request->user()?->can('email.rule_manage'), 403);
    }

    private function authorizeRulePublish(Request $request): void
    {
        abort_unless($request->user()?->isActive()
            && $request->user()?->can('email.rule_manage')
            && $request->user()?->can('email.rule_publish'), 403);
    }

    private function authorizeRuleReprocess(Request $request): void
    {
        abort_unless($request->user()?->isActive()
            && $request->user()?->can('email.rule_manage')
            && $request->user()?->can('email.rule_reprocess'), 403);
    }

    /** @return array<string, mixed> */
    private function validatedDraftPayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'weight' => ['required', 'integer', 'min:0', 'max:100000'],
            'routing_phase' => ['required', Rule::in(['normal', 'preclassification'])],
            'is_active' => ['required', 'boolean'],
            'stop_processing' => ['required', 'boolean'],
            'conditions_json' => ['required', 'array'],
            'conditions_json.match' => ['nullable', Rule::in(['all', 'any'])],
            'conditions_json.groups' => ['nullable', 'array', 'min:1'],
            'conditions_json.groups.*.match' => ['nullable', Rule::in(['all', 'any'])],
            'conditions_json.groups.*.conditions' => ['required_with:conditions_json.groups', 'array', 'min:1'],
            'conditions_json.groups.*.conditions.*.field' => ['required_with:conditions_json.groups', Rule::in([
                'from', 'from_domain', 'to', 'cc', 'subject', 'body', 'message_id', 'is_reply', 'has_ticket_key',
            ])],
            'conditions_json.groups.*.conditions.*.operator' => ['required_with:conditions_json.groups', Rule::in([
                'contains', 'equals', 'not_equals', 'starts_with', 'ends_with', 'regex', 'present',
            ])],
            'actions_json' => ['required', 'array', 'min:1'],
            'actions_json.*.type' => ['required', Rule::in([
                'link_ticket_by_subject_token', 'create_ticket', 'archive', 'tag', 'tag_message',
                'tag_conversation', 'set_conversation_category', 'emit_signal', 'provider_archive', 'provider_move',
            ])],
            'actions_json.*.value' => ['nullable', 'string', 'max:1000'],
            'actions_json.*.target_folder_id' => ['nullable', 'integer', 'exists:email_folders,id'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', 'exists:email_accounts,id'],
            'lock_version' => ['nullable', 'integer', 'min:1'],
        ]);

        $accountIds = collect($data['account_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
        $eligibleCount = EmailAccount::query()
            ->whereIn('id', $accountIds)
            ->where('account_kind', '!=', EmailAccount::KIND_PERSONAL)
            ->where('ticket_ingress_enabled', true)
            ->count();
        if ($eligibleCount !== $accountIds->count()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'account_ids' => 'Email rules may target only shared or system mailboxes with Ticket ingress enabled.',
            ]);
        }

        return collect($data)->except('lock_version')->all();
    }

    private function serializeDraft($draft): array
    {
        return [
            'rule_id' => $draft->email_rule_id,
            'base_version_id' => $draft->base_email_rule_version_id,
            'lock_version' => $draft->lock_version,
            'checksum' => $draft->checksum,
            'definition' => $draft->payload_json,
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];
    }

    private function serializeVersion($version): array
    {
        return [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'snapshot_hash' => $version->snapshot_hash,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    private function serializeRun(EmailRuleReprocessRun $run): array
    {
        return [
            'id' => $run->public_id,
            'rule_id' => $run->email_rule_id,
            'version_id' => $run->email_rule_version_id,
            'operation' => $run->operation,
            'status' => $run->status,
            'requested_count' => $run->requested_count,
            'matched_count' => $run->matched_count,
            'succeeded_count' => $run->succeeded_count,
            'failed_count' => $run->failed_count,
            'overflow' => $run->overflow,
            'expires_at' => $run->expires_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'items' => $run->relationLoaded('items') ? $run->items->map(fn ($item): array => [
                'id' => $item->id,
                'message_id' => $item->email_message_id,
                'status' => $item->status,
                'reason_code' => $item->reason_code,
                'matched' => $item->matched,
                'actions' => $item->action_summary_json ?? [],
            ])->values()->all() : null,
        ];
    }

    private function assertExecutionVisible(
        Request $request,
        EmailRuleExecutionAttempt $attempt,
        MailboxAccess $mailboxAccess,
    ): void {
        $attempt->loadMissing(['rule', 'version', 'message.account', 'placement.account']);
        $ruleKind = $attempt->version?->rule_kind ?? $attempt->rule?->rule_kind;
        $account = $attempt->placement?->account ?? $attempt->message?->account;

        abort_if(
            $ruleKind !== EmailRule::KIND_ADMIN
            || ! $account
            || ! $mailboxAccess->canAccessAccount($request->user(), $account, MailboxAccess::VIEW),
            404,
        );
    }

    private function serializeRule(EmailRule $rule, bool $includeSnapshot = false): array
    {
        $version = $rule->publishedVersion;
        $data = [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'trigger' => $rule->trigger,
            'routing_phase' => $rule->routing_phase,
            'weight' => $rule->weight,
            'is_active' => $rule->is_active,
            'lifecycle_status' => $rule->lifecycle_status,
            'stop_processing' => $rule->stop_processing,
            'published_version' => $version ? [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status,
                'snapshot_hash' => $version->snapshot_hash,
                'published_at' => $version->published_at?->toIso8601String(),
            ] : null,
            'accounts' => $rule->accounts
                ->map(fn ($account): array => [
                    'id' => $account->id,
                    'address' => $account->address,
                    'account_kind' => $account->account_kind,
                ])
                ->values()
                ->all(),
            'last_hit_at' => $rule->last_hit_at?->toIso8601String(),
            'hit_count' => $rule->hit_count,
        ];

        if ($includeSnapshot) {
            $data['conditions'] = $version?->conditions_json ?? $rule->conditions_json ?? [];
            $data['actions'] = $version?->actions_json ?? $rule->actions_json ?? [];
            $data['account_ids'] = $version?->account_ids_json ?? $rule->accounts->pluck('id')->values()->all();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $undo
     * @return array<string, mixed>
     */
    private function serializeExecution(EmailRuleExecutionAttempt $attempt, array $undo): array
    {
        return [
            'id' => $attempt->id,
            'rule_id' => $attempt->email_rule_id,
            'rule_version_id' => $attempt->email_rule_version_id,
            'account_id' => $attempt->placement?->account_id ?? $attempt->message?->account_id,
            'routing_phase' => $this->safeCode($attempt->routing_phase, 'unknown'),
            'status' => $this->safeCode($attempt->status, 'unknown'),
            'reason_code' => $this->safeReasonCode($attempt->reason_code),
            'matched' => $attempt->matched,
            'stop_processing' => $attempt->stop_processing,
            'actions' => collect($attempt->action_results_json ?? [])
                ->filter(fn (mixed $result): bool => is_array($result))
                ->map(fn (array $result): array => [
                    'position' => isset($result['position']) ? (int) $result['position'] : null,
                    'type' => $this->safeCode($result['type'] ?? null, 'unknown'),
                    'status' => $this->safeCode($result['status'] ?? null, 'unknown'),
                    'reason_code' => $this->safeReasonCode($result['reason'] ?? null),
                    'remote_operation_id' => isset($result['remote_operation_id'])
                        ? (int) $result['remote_operation_id']
                        : null,
                    'remote_operation_status' => isset($result['remote_operation_status'])
                        ? $this->safeCode($result['remote_operation_status'], 'unknown')
                        : null,
                ])
                ->values()
                ->all(),
            'undo' => $this->serializeUndoEligibility($undo),
            'started_at' => $attempt->started_at?->toIso8601String(),
            'finished_at' => $attempt->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $undo
     * @return array<string, mixed>
     */
    private function serializeUndoEligibility(array $undo): array
    {
        return [
            ...$undo,
            'expires_at' => $undo['expires_at']?->toIso8601String(),
        ];
    }

    private function safeReasonCode(mixed $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return $this->safeCode($reason, 'email_rule_action_failed');
    }

    private function safeCode(mixed $value, ?string $fallback): ?string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $value = trim($value);

        return strlen($value) <= 120
            && preg_match('/\A[A-Za-z0-9]+(?:_[A-Za-z0-9]+)*\z/', $value) === 1
            ? $value
            : $fallback;
    }

    private function undoStatusMessage(string $status): string
    {
        return match ($status) {
            EmailRemoteOperation::STATUS_SUCCEEDED => 'The verified inverse was acknowledged by the mailbox provider.',
            EmailRemoteOperation::STATUS_PENDING,
            EmailRemoteOperation::STATUS_RUNNING => 'The verified inverse is waiting for provider acknowledgement.',
            EmailRemoteOperation::STATUS_SUPERSEDED => 'Undo stopped safely because mailbox or provider evidence changed.',
            EmailRemoteOperation::STATUS_CANCELLED => 'The verified inverse was cancelled before another provider attempt.',
            default => 'The verified inverse requires safe mailbox-operation recovery.',
        };
    }
}
