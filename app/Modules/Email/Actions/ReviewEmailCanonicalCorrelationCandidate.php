<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCanonicalCorrelationCandidate;
use App\Modules\Email\Models\EmailCanonicalCorrelationInspection;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailCanonicalCorrelationEvidence;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewEmailCanonicalCorrelationCandidate
{
    public function __construct(
        private readonly ResolveMailboxAccessDecision $accessDecisions,
        private readonly EmailCanonicalCorrelationEvidence $evidence,
    ) {}

    public function handle(
        EmailCanonicalCorrelationCandidate $candidate,
        User $actor,
        string $reviewState,
        string $reasonCode,
    ): EmailCanonicalCorrelationCandidate {
        $allowedStates = [
            EmailCanonicalCorrelationCandidate::REVIEW_CONFIRMED,
            EmailCanonicalCorrelationCandidate::REVIEW_KEEP_SEPARATE,
            EmailCanonicalCorrelationCandidate::REVIEW_MORE_EVIDENCE,
        ];
        $validator = validator([
            'review_state' => $reviewState,
            'reason_code' => $reasonCode,
        ], [
            'review_state' => ['required', Rule::in($allowedStates)],
            'reason_code' => ['required', 'regex:/\A[a-z0-9_:-]{1,80}\z/'],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($candidate, $actor, $reviewState, $reasonCode): EmailCanonicalCorrelationCandidate {
            $actor = User::query()->find($actor->id);
            $locked = EmailCanonicalCorrelationCandidate::query()->lockForUpdate()->find($candidate->id);

            if (! $actor?->isActive()
                || $actor->isSystemActor()
                || ! $actor->can('email.mailbox_sync_manage')
                || ! $locked) {
                throw new AuthorizationException('This correlation candidate is not available.');
            }

            $run = EmailCanonicalCorrelationRun::query()->find($locked->email_canonical_correlation_run_id);
            if (! $run || $run->status !== EmailCanonicalCorrelationRun::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'review_state' => 'The bounded correlation run must finish before review.',
                ]);
            }

            foreach (array_unique([
                $locked->left_email_account_id,
                $locked->right_email_account_id,
            ]) as $accountId) {
                $account = EmailAccount::query()->find($accountId);
                if (! $account?->is_active
                    || ! $this->accessDecisions->resolve($actor, $account, MailboxAccess::VIEW)->allowed) {
                    throw new AuthorizationException('This correlation candidate is not available.');
                }
            }

            if ($locked->candidate_class === EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED
                && $reviewState === EmailCanonicalCorrelationCandidate::REVIEW_CONFIRMED) {
                throw ValidationException::withMessages([
                    'review_state' => 'An oversized representative pair cannot be confirmed as canonical evidence.',
                ]);
            }

            if ($reviewState !== EmailCanonicalCorrelationCandidate::REVIEW_MORE_EVIDENCE
                && ! EmailCanonicalCorrelationInspection::query()
                    ->where('email_canonical_correlation_candidate_id', $locked->id)
                    ->where('inspected_by', $actor->id)
                    ->where('left_evidence_hash', $locked->left_evidence_hash)
                    ->where('right_evidence_hash', $locked->right_evidence_hash)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'review_state' => 'Inspect this exact candidate evidence before confirming or separating it.',
                ]);
            }

            $messages = EmailMessage::query()
                ->whereIn('id', [
                    $locked->left_email_message_id,
                    $locked->right_email_message_id,
                ])
                ->with(['account:id,address', 'attachments'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $left = $messages->get($locked->left_email_message_id);
            $right = $messages->get($locked->right_email_message_id);

            if (! $left
                || ! $right
                || (int) $left->account_id !== (int) $locked->left_email_account_id
                || (int) $right->account_id !== (int) $locked->right_email_account_id
                || (int) $left->id > (int) $run->frozen_max_message_id
                || (int) $right->id > (int) $run->frozen_max_message_id) {
                throw ValidationException::withMessages([
                    'review_state' => 'The candidate evidence is no longer available.',
                ]);
            }

            $comparison = $this->evidence->compare(
                $this->evidence->forMessage($left),
                $this->evidence->forMessage($right),
            );
            if (! hash_equals($locked->left_evidence_hash, $comparison['left_evidence_hash'])
                || ! hash_equals($locked->right_evidence_hash, $comparison['right_evidence_hash'])
                || ($locked->candidate_class !== EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED
                    && (! hash_equals($locked->pair_fingerprint, $comparison['pair_fingerprint'])
                        || $locked->candidate_class !== $comparison['candidate_class']))) {
                throw ValidationException::withMessages([
                    'review_state' => 'The candidate evidence changed. Run correlation again before review.',
                ]);
            }

            if ($locked->review_state !== EmailCanonicalCorrelationCandidate::REVIEW_UNREVIEWED) {
                if ($locked->review_state === $reviewState
                    && $locked->review_reason_code === $reasonCode
                    && (int) $locked->reviewed_by === (int) $actor->id) {
                    return $locked;
                }

                throw ValidationException::withMessages([
                    'review_state' => 'This candidate already has an immutable review decision.',
                ]);
            }

            $locked->forceFill([
                'review_state' => $reviewState,
                'review_reason_code' => $reasonCode,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            return $locked->refresh();
        }, 3);
    }
}
