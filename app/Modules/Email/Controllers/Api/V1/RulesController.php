<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailMessage;
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
    use \App\Modules\Email\Concerns\SerializesEmailRuleApi;

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

    /** @return array<string, mixed> */

    /**
     * @param  array<string, mixed>  $undo
     * @return array<string, mixed>
     */

    /**
     * @param  array<string, mixed>  $undo
     * @return array<string, mixed>
     */
}
