<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Actions\BuildEmailSmartInboxRulePrefill;
use App\Modules\Email\Actions\CreatePersonalEmailRule;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Actions\UndoEmailRemoteOperation;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EmailRuleReversalService
{
    private const REASON_AVAILABLE = 'EMAIL_RULE_UNDO_AVAILABLE';

    private const REASON_ATTEMPT_NOT_SUCCEEDED = 'EMAIL_RULE_UNDO_ATTEMPT_NOT_SUCCEEDED';

    private const REASON_EXECUTION_EVIDENCE_INVALID = 'EMAIL_RULE_UNDO_EXECUTION_EVIDENCE_INVALID';

    private const REASON_MIXED_EFFECTS = 'EMAIL_RULE_UNDO_MIXED_EFFECTS';

    private const REASON_NOT_REVERSIBLE = 'EMAIL_RULE_UNDO_ACTION_NOT_REVERSIBLE';

    private const REASON_OPERATION_MISSING = 'EMAIL_RULE_UNDO_OPERATION_MISSING';

    private const REASON_OPERATION_MISMATCH = 'EMAIL_RULE_UNDO_OPERATION_MISMATCH';

    public function __construct(
        private readonly EmailRemoteOperationUndoEligibility $remoteEligibility,
        private readonly UndoEmailRemoteOperation $undoRemoteOperation,
    ) {}

    /**
     * Return content-free eligibility for the one provider effect that can safely represent the
     * complete execution attempt. Rule attempts remain immutable; the remote-operation source and
     * its uniquely linked inverse are the durable Undo ledger.
     *
     * @return array{
     *     eligible: bool,
     *     reason_code: string,
     *     reason_message: string,
     *     action_position: int|null,
     *     action_type: string|null,
     *     source_remote_operation_id: int|null,
     *     inverse_remote_operation_id: int|null,
     *     inverse_remote_operation_status: string|null,
     *     provider_reason_code: string|null,
     *     expires_at: \Carbon\CarbonInterface|null
     * }
     */
    public function eligibility(EmailRuleExecutionAttempt $attempt, ?User $actor): array
    {
        $context = $this->reversibleContext($attempt);
        if (! $context['valid']) {
            return $this->blocked($context['reason_code'], $context['reason_message']);
        }

        /** @var EmailRemoteOperation $operation */
        $operation = $context['operation'];
        $provider = $this->remoteEligibility->evaluate($operation, $actor);

        return [
            'eligible' => (bool) $provider['eligible'],
            'reason_code' => $provider['eligible']
                ? self::REASON_AVAILABLE
                : (string) $provider['reason_code'],
            'reason_message' => (string) $provider['reason_message'],
            'action_position' => $context['position'],
            'action_type' => $context['type'],
            'source_remote_operation_id' => $operation->id,
            'inverse_remote_operation_id' => $provider['inverse_operation_id'],
            'inverse_remote_operation_status' => $provider['inverse_operation_status'],
            'provider_reason_code' => (string) $provider['reason_code'],
            'expires_at' => $provider['expires_at'],
        ];
    }

    /**
     * Create or return the exact verified provider inverse for a rule execution.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function revert(EmailRuleExecutionAttempt $attempt, User $actor): EmailRemoteOperation
    {
        if (! $actor->isActive() || ! $actor->can('email.rule_manage')) {
            throw new AuthorizationException('Current Email rule-management permission is required.');
        }

        $context = $this->reversibleContext($attempt);
        if (! $context['valid']) {
            throw ValidationException::withMessages([
                'attempt' => $context['reason_message'],
            ]);
        }

        return $this->undoRemoteOperation->handle($context['operation'], $actor);
    }

    /**
     * @return array{
     *     valid: bool,
     *     reason_code: string,
     *     reason_message: string,
     *     position?: int,
     *     type?: string,
     *     operation?: EmailRemoteOperation
     * }
     */
    private function reversibleContext(EmailRuleExecutionAttempt $attempt): array
    {
        $attempt->loadMissing(['rule', 'version', 'message.account', 'placement.account']);

        if ($attempt->status !== EmailRuleExecutionAttempt::STATUS_SUCCEEDED || ! $attempt->finished_at) {
            return $this->invalid(
                self::REASON_ATTEMPT_NOT_SUCCEEDED,
                'Only a completed successful rule execution can be considered for Undo.',
            );
        }

        $results = $attempt->action_results_json;
        $actions = $attempt->actions_json;
        if (! is_array($results)
            || ! array_is_list($results)
            || ! is_array($actions)
            || ! array_is_list($actions)
            || $actions === []
            || count($results) !== count($actions)) {
            return $this->invalid(
                self::REASON_EXECUTION_EVIDENCE_INVALID,
                'The rule execution does not contain complete immutable per-action evidence.',
            );
        }

        foreach ($results as $resultIndex => $candidate) {
            $candidatePosition = is_array($candidate)
                ? filter_var($candidate['position'] ?? null, FILTER_VALIDATE_INT)
                : false;
            $candidateAction = $actions[$resultIndex] ?? null;
            if (! is_array($candidate)
                || ! is_array($candidateAction)
                || $candidatePosition === false
                || $candidatePosition !== $resultIndex
                || ($candidate['type'] ?? null) !== ($candidateAction['type'] ?? null)
                || ! in_array($candidate['status'] ?? null, [
                    EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
                    EmailRuleExecutionAttempt::STATUS_FAILED,
                    EmailRuleExecutionAttempt::STATUS_SKIPPED,
                    EmailRuleExecutionAttempt::STATUS_NOT_RUN,
                ], true)) {
                return $this->invalid(
                    self::REASON_EXECUTION_EVIDENCE_INVALID,
                    'The rule execution action results do not match the complete ordered action snapshot.',
                );
            }
        }

        if (collect($results)->contains(
            fn (array $result): bool => $result['status'] !== EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
        )) {
            return $this->invalid(
                self::REASON_EXECUTION_EVIDENCE_INVALID,
                'A successful rule attempt cannot contain failed, skipped, or not-run action evidence.',
            );
        }

        $successful = collect($results)
            ->filter(fn (mixed $result): bool => is_array($result)
                && ($result['status'] ?? null) === EmailRuleExecutionAttempt::STATUS_SUCCEEDED)
            ->values();

        if ($successful->count() !== 1) {
            return $this->invalid(
                $successful->isEmpty() ? self::REASON_NOT_REVERSIBLE : self::REASON_MIXED_EFFECTS,
                $successful->isEmpty()
                    ? 'This rule execution has no successful action with an approved verified inverse.'
                    : 'A rule execution with several successful effects cannot be partially presented as undone.',
            );
        }

        /** @var array<string, mixed> $result */
        $result = $successful->first();
        $position = filter_var($result['position'] ?? null, FILTER_VALIDATE_INT);
        $type = trim((string) ($result['type'] ?? ''));
        if ($position === false || $position < 0 || $type === '') {
            return $this->invalid(
                self::REASON_EXECUTION_EVIDENCE_INVALID,
                'The rule execution action identity is incomplete.',
            );
        }

        $snapshottedAction = $actions[$position] ?? null;
        if (! is_array($snapshottedAction) || ($snapshottedAction['type'] ?? null) !== $type) {
            return $this->invalid(
                self::REASON_EXECUTION_EVIDENCE_INVALID,
                'The rule execution result does not match its immutable action snapshot.',
            );
        }

        $expectedOperationType = $this->expectedOperationType($attempt, $type);
        if ($expectedOperationType === null) {
            return $this->invalid(
                self::REASON_NOT_REVERSIBLE,
                'This rule action has no approved verified inverse.',
            );
        }

        $operationId = filter_var($result['remote_operation_id'] ?? null, FILTER_VALIDATE_INT);
        if ($operationId === false || $operationId < 1) {
            return $this->invalid(
                self::REASON_OPERATION_MISSING,
                'The rule action is missing its provider-operation ledger reference.',
            );
        }

        $operation = EmailRemoteOperation::query()
            ->with(['account', 'placement', 'folder', 'inverseOperation', 'attemptRecords'])
            ->find($operationId);
        if (! $operation) {
            return $this->invalid(
                self::REASON_OPERATION_MISSING,
                'The referenced provider operation is unavailable.',
            );
        }

        $attemptAccountId = (int) ($attempt->placement?->account_id ?: $attempt->message?->account_id);
        $expectedTargetFolderId = filter_var(
            $snapshottedAction['target_folder_id'] ?? null,
            FILTER_VALIDATE_INT,
        );
        $operationTargetFolderId = (int) Arr::get(
            $operation->result_snapshot_json ?? [],
            'target_folder.folder_id',
            0,
        );
        if ($attemptAccountId < 1
            || (int) $operation->account_id !== $attemptAccountId
            || (int) $operation->email_mailbox_placement_id !== (int) $attempt->email_mailbox_placement_id
            || $operation->operation_type !== $expectedOperationType
            || $expectedTargetFolderId === false
            || $expectedTargetFolderId < 1
            || $operationTargetFolderId !== $expectedTargetFolderId
            || ($result['remote_operation_status'] ?? null) !== EmailRemoteOperation::STATUS_SUCCEEDED) {
            return $this->invalid(
                self::REASON_OPERATION_MISMATCH,
                'The provider-operation reference does not match the rule execution source and action.',
            );
        }

        return [
            'valid' => true,
            'reason_code' => self::REASON_AVAILABLE,
            'reason_message' => 'Undo is available through the verified provider-operation ledger.',
            'position' => (int) $position,
            'type' => $type,
            'operation' => $operation,
        ];
    }

    private function expectedOperationType(EmailRuleExecutionAttempt $attempt, string $type): ?string
    {
        if ($type === BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE) {
            return PerformEmailRemoteOperation::ARCHIVE;
        }

        if ($type === BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE) {
            return PerformEmailRemoteOperation::MOVE;
        }

        $ruleKind = $attempt->version?->rule_kind ?? $attempt->rule?->rule_kind;
        if ($ruleKind !== EmailRule::KIND_PERSONAL_SIMPLE) {
            return null;
        }

        return match ($type) {
            CreatePersonalEmailRule::ACTION_ARCHIVE => PerformEmailRemoteOperation::ARCHIVE,
            CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER => PerformEmailRemoteOperation::MOVE,
            default => null,
        };
    }

    /**
     * @return array{valid: false, reason_code: string, reason_message: string}
     */
    private function invalid(string $reasonCode, string $reasonMessage): array
    {
        return [
            'valid' => false,
            'reason_code' => $reasonCode,
            'reason_message' => $reasonMessage,
        ];
    }

    /**
     * @return array{
     *     eligible: false,
     *     reason_code: string,
     *     reason_message: string,
     *     action_position: null,
     *     action_type: null,
     *     source_remote_operation_id: null,
     *     inverse_remote_operation_id: null,
     *     inverse_remote_operation_status: null,
     *     provider_reason_code: null,
     *     expires_at: null
     * }
     */
    private function blocked(string $reasonCode, string $reasonMessage): array
    {
        return [
            'eligible' => false,
            'reason_code' => $reasonCode,
            'reason_message' => $reasonMessage,
            'action_position' => null,
            'action_type' => null,
            'source_remote_operation_id' => null,
            'inverse_remote_operation_id' => null,
            'inverse_remote_operation_status' => null,
            'provider_reason_code' => null,
            'expires_at' => null,
        ];
    }
}
