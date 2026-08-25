<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Services\EmailRuleReversalService;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RulesController extends Controller
{
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
