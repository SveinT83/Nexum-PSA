<?php

namespace App\Modules\Commercial\Actions;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractDocumentReadiness;
use App\Modules\Commercial\Support\ContractTermSnapshotReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared Contract lifecycle preserves exact evidence/state and lock ordering. */
class ContractLifecycleWorkflow
{
    public function transitionForSending(Contracts $contract, string $status, ?string $ccEmail, ?array $reviewedDocument = null, ?string $reviewedToken = null): array
    {
        return DB::transaction(function () use ($contract, $status, $ccEmail, $reviewedDocument, $reviewedToken): array {
            $locked = Contracts::query()
                ->with('client')
                ->lockForUpdate()
                ->findOrFail($contract->getKey());

            if (! $locked->isEditable()) {
                return ['error' => 'Only editable contract drafts can be sent.'];
            }

            if (! $locked->isReady()) {
                return ['error' => 'Contract is not ready to be sent. Please check items and terms.'];
            }

            $readiness = app(ContractDocumentReadiness::class);
            if ($readiness->missingLegalIdentity($locked) !== []) {
                return ['error' => $readiness->failureMessage($locked)];
            }

            $termReadiness = app(ContractTermSnapshotReadiness::class);
            if (! $termReadiness->isCurrent($locked)) {
                return ['error' => $termReadiness->failureMessage()];
            }

            $previousStatus = (string) $locked->approval_status;
            if ($reviewedDocument !== null) {
                app(CaptureContractCustomerDocument::class)->captureReviewed($locked, $reviewedDocument, $status);
            } else {
                app(CaptureContractTermVersions::class)->replace($locked);
                app(CaptureContractCustomerDocument::class)->replace($locked, $status);
            }
            $locked->forceFill([
                'approval_status' => $status,
                'sent_at' => now(),
                'cc_email' => $ccEmail,
                // A newly sent document is a new bearer capability. Never let
                // a link shared for an older draft/client open this snapshot.
                'secure_token' => $reviewedToken ?? Str::random(64),
            ])->save();

            return [
                'contract' => $locked,
                'previous_status' => $previousStatus,
            ];
        });
    }

    public function approve(Contracts $contract, User $approvedBy): array
    {
        return DB::transaction(function () use ($contract, $approvedBy): array {
            $locked = Contracts::query()
                ->with('client')
                ->lockForUpdate()
                ->findOrFail($contract->getKey());

            if (! in_array($locked->approval_status, ['draft', 'negotiation', 'quote_lost', 'sent_quote', 'sent_contract'], true)) {
                return ['error' => 'This contract cannot be manually approved in its current status.'];
            }

            $wasEditable = $locked->isEditable();

            if ($wasEditable && ! $locked->isReady()) {
                return ['error' => 'Kontrakten må ha tjenester, vilkår og gyldig avtalestart før manuell godkjenning.'];
            }

            $customerDocuments = app(ContractCustomerDocument::class);

            if ($wasEditable) {
                $readiness = app(ContractDocumentReadiness::class);
                if ($readiness->missingLegalIdentity($locked) !== []) {
                    return ['error' => $readiness->failureMessage($locked)];
                }

                $termReadiness = app(ContractTermSnapshotReadiness::class);
                if (! $termReadiness->isCurrent($locked)) {
                    return ['error' => $termReadiness->failureMessage()];
                }
            } else {
                // Resolve historical evidence before considering today's
                // identity or term sources. A missing legacy snapshot must
                // first pass the explicit named attestation workflow.
                $customerDocuments->resolve($locked);
            }

            if ($wasEditable) {
                app(CaptureContractTermVersions::class)->replace($locked);
                app(CaptureContractCustomerDocument::class)->replace($locked, 'won');
            } else {
                app(CaptureContractCustomerDocument::class)->handle($locked, 'won');
            }

            $approvedAt = now();
            $approvalValues = [
                'approval_status' => 'won',
                'accepted_at' => $approvedAt,
                'accepted_by_name' => $approvedBy?->name ?: 'Intern godkjenning',
                'approval_approved_at' => $approvedAt,
                'approval_approved_by' => $approvedBy?->id,
            ];

            if ($wasEditable) {
                // Manual approval did not send a customer link. Remove any
                // dormant capability left by an older editable lifecycle.
                $approvalValues['secure_token'] = null;
            }

            $locked->forceFill($approvalValues)->save();

            return ['contract' => $locked];
        });
    }

    public function refreshTerms(Contracts $contract, int $actorId): bool
    {
        return DB::transaction(function () use ($contract, $actorId): bool {
            $locked = Contracts::query()->lockForUpdate()->findOrFail($contract->getKey());

            if (! $locked->isEditable()) {
                return false;
            }

            $locked->load('items.service.serviceTerms');
            $locked->forceFill(array_merge(
                app(BuildContractTermSnapshots::class)->handle($locked),
                ['customer_document_snapshot' => null],
            ))->save();
            app(ContractTermSnapshotReadiness::class)->markReviewed($locked, $actorId);

            return true;
        });
    }

    public function saveTerms(Contracts $contract, array $updates, int $actorId): bool
    {
        return DB::transaction(function () use ($contract, $updates, $actorId): bool {
            $locked = Contracts::query()->lockForUpdate()->findOrFail($contract->getKey());

            if (! $locked->isEditable()) {
                return false;
            }

            $locked->update($updates);
            $locked->forceFill(['customer_document_snapshot' => null])->save();
            app(ContractTermSnapshotReadiness::class)->markReviewed($locked, $actorId);

            return true;
        });
    }
}
