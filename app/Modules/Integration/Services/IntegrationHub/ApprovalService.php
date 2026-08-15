<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\IntegrationHubApprovalDecision;
use App\Modules\Integration\Models\IntegrationHubApprovalRequest;
use App\Modules\Integration\Models\IntegrationHubExecution;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    /** @param array<string, mixed> $scope */
    public function request(IntegrationHubExecution $execution, User $requester, string $planDigest, array $scope, string $riskLevel, int $expiresInMinutes = 30): IntegrationHubApprovalRequest
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $planDigest)) {
            throw new IntegrationHubDeniedException('approval_plan_digest_invalid', 'Plan digest is invalid.', 422, 'failed');
        }
        if (! in_array($riskLevel, ['low', 'medium', 'high', 'critical'], true)) {
            throw new IntegrationHubDeniedException('approval_risk_level_invalid', 'Approval risk level is invalid.', 422, 'failed');
        }

        return DB::transaction(function () use ($execution, $requester, $planDigest, $scope, $riskLevel, $expiresInMinutes): IntegrationHubApprovalRequest {
            $locked = IntegrationHubExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['partial', 'failed', 'unknown', 'completed', 'cancelled'], true)) {
                throw new IntegrationHubDeniedException('approval_execution_terminal', 'The execution no longer accepts approval requests.', 409, 'failed');
            }
            if ($locked->plan_digest !== null && ! hash_equals($locked->plan_digest, $planDigest)) {
                throw new IntegrationHubDeniedException('approval_plan_changed', 'The execution plan has changed.', 409, 'failed');
            }

            IntegrationHubApprovalRequest::query()
                ->where('execution_id', $locked->id)
                ->where('status', 'pending')
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired', 'decided_at' => now()]);
            $pending = IntegrationHubApprovalRequest::query()
                ->where('execution_id', $locked->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if ($pending) {
                throw new IntegrationHubDeniedException('approval_already_pending', 'An approval request is already pending.', 409, 'failed');
            }

            $locked->forceFill(['plan_digest' => $planDigest, 'status' => 'input_required'])->save();

            return IntegrationHubApprovalRequest::query()->create([
                'execution_id' => $locked->id,
                'requested_by' => $requester->id,
                'plan_digest' => $planDigest,
                'scope' => collect($scope)->only(['installation', 'client_ids', 'site_ids', 'integration_ids', 'environment', 'target_type', 'target_id'])->all(),
                'risk_level' => $riskLevel,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(min(120, max(1, $expiresInMinutes))),
            ]);
        });
    }

    /** @param array<string, mixed> $evidence */
    public function decide(IntegrationHubApprovalRequest $approval, User $decider, string $decision, string $planDigest, array $evidence = []): IntegrationHubApprovalDecision
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new IntegrationHubDeniedException('approval_decision_invalid', 'Approval decision is invalid.', 422, 'failed');
        }

        return DB::transaction(function () use ($approval, $decider, $decision, $planDigest, $evidence): IntegrationHubApprovalDecision {
            $locked = IntegrationHubApprovalRequest::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            $execution = IntegrationHubExecution::query()->whereKey($locked->execution_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending' || $locked->expires_at->isPast()) {
                throw new IntegrationHubDeniedException('approval_expired_or_decided', 'Approval is no longer pending.', 409, 'failed');
            }
            if (! hash_equals($locked->plan_digest, $planDigest)) {
                throw new IntegrationHubDeniedException('approval_plan_changed', 'The approved plan has changed.', 409, 'failed');
            }
            if ($execution->plan_digest === null || ! hash_equals($execution->plan_digest, $planDigest)) {
                throw new IntegrationHubDeniedException('approval_plan_changed', 'The execution plan has changed.', 409, 'failed');
            }
            if ((int) $locked->requested_by === (int) $decider->id
                || (int) $execution->actor_id === (int) $decider->id
                || (int) $execution->service_actor_id === (int) $decider->id
                || $decider->isSystemActor()) {
                throw new IntegrationHubDeniedException('approval_separation_of_duties_required');
            }

            $decisionRecord = IntegrationHubApprovalDecision::query()->create([
                'approval_request_id' => $locked->id,
                'decision' => $decision,
                'decided_by' => $decider->id,
                'reason_code' => $decision === 'approved' ? 'human_approved' : 'human_rejected',
                'evidence' => collect($evidence)->only(['reference', 'note'])->all(),
            ]);
            $locked->forceFill(['status' => $decision, 'decided_at' => now()])->save();
            $execution->forceFill($decision === 'approved'
                ? ['status' => 'queued']
                : ['status' => 'failed', 'result_status' => 'failed', 'failure_code' => 'human_rejected', 'finished_at' => now()]
            )->save();

            return $decisionRecord;
        });
    }
}
