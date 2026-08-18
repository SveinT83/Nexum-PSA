<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use App\Modules\Email\Services\EmailSmartInboxSuggestionEventRecorder;
use App\Modules\Email\Services\EmailSmartInboxSuggestionIdentity;
use App\Modules\Email\Services\EmailSmartInboxSuggestionNormalizer;
use App\Modules\Email\Services\EmailSmartInboxSuggestionStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectEmailSmartInboxSuggestion
{
    public function __construct(
        private readonly EmailSmartInboxSuggestionStateService $stateService,
        private readonly EmailSmartInboxSuggestionNormalizer $normalizer,
        private readonly EmailSmartInboxSuggestionIdentity $identity,
        private readonly EmailSmartInboxSuggestionEventRecorder $eventRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $proposal
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        array $proposal,
        mixed $explanation = null,
        mixed $confidence = null,
    ): EmailSmartInboxSuggestion {
        $result = DB::transaction(function () use (
            $suggestion,
            $actor,
            $proposal,
            $explanation,
            $confidence,
        ): array {
            $locked = EmailSmartInboxSuggestion::query()
                ->lockForUpdate()
                ->findOrFail($suggestion->id);
            $locked = $this->stateService->evaluateLocked($locked, $actor);

            if ($locked->status === EmailSmartInboxSuggestion::STATUS_REVOKED) {
                return ['suggestion' => $locked, 'error' => 'revoked'];
            }

            if ($locked->status === EmailSmartInboxSuggestion::STATUS_STALE) {
                return ['suggestion' => $locked, 'error' => 'stale'];
            }

            if ($locked->status !== EmailSmartInboxSuggestion::STATUS_PENDING) {
                return ['suggestion' => $locked, 'error' => 'terminal'];
            }

            $sourceMessageIds = collect($locked->source_message_ids_json ?? [])
                ->filter(fn (mixed $id): bool => is_numeric($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();
            $attachmentNames = EmailAttachment::query()
                ->whereIn('message_id', $sourceMessageIds)
                ->pluck('filename')
                ->filter(fn (mixed $filename): bool => is_string($filename) && trim($filename) !== '')
                ->map(fn (string $filename): string => trim($filename))
                ->values()
                ->all();
            $normalizedProposal = $this->normalizer->correctionProposal(
                $locked->effect_type,
                $proposal,
                $sourceMessageIds,
                $attachmentNames,
                (int) $locked->account_id,
                (int) ($locked->selectedPlacement?->email_folder_id ?? 0),
            );
            $normalizedProposal = $this->preserveCleanupSourceEvidence($locked, $normalizedProposal);
            $normalizedExplanation = $explanation === null
                ? $locked->explanation
                : $this->normalizer->normalizeExplanation($explanation, $attachmentNames);
            $normalizedConfidence = $confidence === null
                ? $locked->confidence
                : $this->normalizer->normalizeConfidence($confidence);
            $proposalFingerprint = $this->identity->checksum($normalizedProposal);

            if (hash_equals((string) $locked->proposal_fingerprint, $proposalFingerprint)
                && $locked->explanation === $normalizedExplanation
                && $locked->confidence === $normalizedConfidence) {
                return ['suggestion' => $locked, 'error' => null];
            }

            $before = $this->eventRecorder->snapshot($locked);
            $locked->forceFill([
                'proposal_json' => $normalizedProposal,
                'proposal_fingerprint' => $proposalFingerprint,
                'explanation' => $normalizedExplanation,
                'confidence' => $normalizedConfidence,
                'corrected_by' => $actor->id,
                'corrected_at' => now(),
            ])->save();
            $this->eventRecorder->record(
                $locked,
                EmailSmartInboxSuggestionEvent::TYPE_CORRECTED,
                $actor,
                $before,
                'user_corrected',
            );

            return ['suggestion' => $locked->refresh(), 'error' => null];
        });

        if ($result['error'] === 'revoked') {
            throw new AuthorizationException('Smart Inbox suggestion not found.');
        }

        if ($result['error'] === 'stale') {
            throw ValidationException::withMessages([
                'suggestion' => 'This suggestion is stale because the Mail conversation changed.',
            ]);
        }

        if ($result['error'] === 'terminal') {
            throw ValidationException::withMessages([
                'suggestion' => 'Only a pending Smart Inbox suggestion can be corrected.',
            ]);
        }

        return $result['suggestion'];
    }

    /**
     * Provider identity is server-owned review evidence, not editable form
     * input. Correcting a target or explanation must never refresh or replace
     * the source snapshot that the user originally reviewed.
     *
     * @param  array<string, mixed>  $normalizedProposal
     * @return array<string, mixed>
     */
    private function preserveCleanupSourceEvidence(
        EmailSmartInboxSuggestion $suggestion,
        array $normalizedProposal,
    ): array {
        if (! in_array($suggestion->effect_type, [
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
        ], true)) {
            return $normalizedProposal;
        }

        $current = is_array($suggestion->proposal_json) ? $suggestion->proposal_json : [];

        foreach ([
            'source_message_id',
            'source_placement_id',
            'source_folder_id',
            'source_folder_path',
            'source_imap_uid',
            'source_uid_validity',
            'source_sync_version',
        ] as $key) {
            if (array_key_exists($key, $current)) {
                $normalizedProposal[$key] = $current[$key];
            }
        }

        return $normalizedProposal;
    }
}
