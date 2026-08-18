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
use Illuminate\Validation\ValidationException;

class InspectEmailCanonicalCorrelationCandidate
{
    public function __construct(
        private readonly ResolveMailboxAccessDecision $accessDecisions,
        private readonly EmailCanonicalCorrelationEvidence $evidence,
    ) {}

    /**
     * Reauthorize and audit an exact candidate inspection before returning either message body.
     * No opened/unread/provider state is changed by this metadata-maintenance surface.
     *
     * @return array{candidate:EmailCanonicalCorrelationCandidate,left:EmailMessage,right:EmailMessage}
     */
    public function handle(EmailCanonicalCorrelationCandidate $candidate, User $actor): array
    {
        return DB::transaction(function () use ($actor, $candidate): array {
            $actor = User::query()->find($actor->id);
            $candidate = EmailCanonicalCorrelationCandidate::query()->find($candidate->id);
            if (! $actor?->isActive()
                || $actor->isSystemActor()
                || ! $actor->can('email.mailbox_sync_manage')
                || ! $candidate) {
                throw new AuthorizationException('This correlation candidate is not available.');
            }

            $run = EmailCanonicalCorrelationRun::query()->find($candidate->email_canonical_correlation_run_id);
            if (! $run || $run->status !== EmailCanonicalCorrelationRun::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'candidate' => 'The bounded correlation run must finish before content inspection.',
                ]);
            }

            foreach (array_unique([
                (int) $candidate->left_email_account_id,
                (int) $candidate->right_email_account_id,
            ]) as $accountId) {
                $account = EmailAccount::query()->find($accountId);
                if (! $account?->is_active
                    || ! $this->accessDecisions->resolve($actor, $account, MailboxAccess::VIEW)->allowed) {
                    throw new AuthorizationException('This correlation candidate is not available.');
                }
            }

            $messages = EmailMessage::query()
                ->where(function ($messages) use ($candidate): void {
                    $messages
                        ->where(function ($left) use ($candidate): void {
                            $left->whereKey($candidate->left_email_message_id)
                                ->where('account_id', $candidate->left_email_account_id);
                        })
                        ->orWhere(function ($right) use ($candidate): void {
                            $right->whereKey($candidate->right_email_message_id)
                                ->where('account_id', $candidate->right_email_account_id);
                        });
                })
                ->with([
                    'account:id,address',
                    'attachments',
                    'placements.folder:id,path',
                ])
                ->get()
                ->keyBy('id');
            $left = $messages->get($candidate->left_email_message_id);
            $right = $messages->get($candidate->right_email_message_id);
            if (! $left || ! $right) {
                throw new AuthorizationException('This correlation candidate is not available.');
            }
            if ((int) $left->account_id !== (int) $candidate->left_email_account_id
                || (int) $right->account_id !== (int) $candidate->right_email_account_id) {
                throw new AuthorizationException('This correlation candidate is not available.');
            }

            $comparison = $this->evidence->compare(
                $this->evidence->forMessage($left),
                $this->evidence->forMessage($right),
            );
            if (! hash_equals($candidate->left_evidence_hash, $comparison['left_evidence_hash'])
                || ! hash_equals($candidate->right_evidence_hash, $comparison['right_evidence_hash'])) {
                throw ValidationException::withMessages([
                    'candidate' => 'The candidate evidence changed. Run correlation again before inspection.',
                ]);
            }

            EmailCanonicalCorrelationInspection::query()->firstOrCreate([
                'email_canonical_correlation_candidate_id' => $candidate->id,
                'inspected_by' => $actor->id,
                'left_evidence_hash' => $candidate->left_evidence_hash,
                'right_evidence_hash' => $candidate->right_evidence_hash,
            ], [
                'inspected_at' => now(),
            ]);

            return compact('candidate', 'left', 'right');
        }, 3);
    }
}
