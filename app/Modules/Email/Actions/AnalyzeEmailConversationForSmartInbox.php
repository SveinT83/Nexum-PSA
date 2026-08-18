<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use App\Modules\Email\Services\EmailConversationFingerprint;
use App\Modules\Email\Services\EmailSmartInboxSuggestionEventRecorder;
use App\Modules\Email\Services\EmailSmartInboxSuggestionIdentity;
use App\Modules\Email\Services\EmailSmartInboxSuggestionNormalizer;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Integration\Models\AiAgent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AnalyzeEmailConversationForSmartInbox
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailConversationFingerprint $conversationFingerprint,
        private readonly SummarizeEmailWithAi $summarizeEmailWithAi,
        private readonly EmailSmartInboxSuggestionNormalizer $normalizer,
        private readonly EmailSmartInboxSuggestionIdentity $identity,
        private readonly EmailSmartInboxSuggestionEventRecorder $eventRecorder,
    ) {}

    /**
     * Run one explicit, governed AI analysis and persist typed review proposals.
     * Analysis itself never calls provider mutation or another domain's write.
     *
     * @return Collection<int, EmailSmartInboxSuggestion>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        EmailConversation $conversation,
        EmailMailboxPlacement $selectedPlacement,
        User $actor,
    ): Collection {
        // Resolve only the account boundary first. Message content and
        // attachment relations are not loaded until View is reauthorized.
        $selectedIdentity = EmailMailboxPlacement::query()
            ->whereKey($selectedPlacement->getKey())
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->with('account:id,account_kind,owner_id,is_active')
            ->first();

        if (! $selectedIdentity?->account) {
            throw ValidationException::withMessages([
                'conversation' => 'Select an active stored Mail conversation before using Smart Inbox.',
            ]);
        }

        if (! $this->mailboxAccess->canAccessAccount($actor, $selectedIdentity->account, MailboxAccess::VIEW)) {
            throw new AuthorizationException('You need mailbox View access before using Smart Inbox.');
        }

        $durableConversation = EmailConversation::query()
            ->whereKey($conversation->getKey())
            ->where('account_id', $selectedIdentity->account_id)
            ->where('status', EmailConversation::STATUS_ACTIVE)
            ->first();

        if (! $durableConversation
            || (int) $selectedIdentity->email_conversation_id !== (int) $durableConversation->id) {
            throw ValidationException::withMessages([
                'conversation' => 'The selected Mail placement is not part of this account conversation.',
            ]);
        }

        $selected = EmailMailboxPlacement::query()
            ->whereKey($selectedIdentity->id)
            ->where('account_id', $durableConversation->account_id)
            ->where('email_conversation_id', $durableConversation->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->with(['account:id,address,account_kind,owner_id,is_active', 'folder', 'message.attachments', 'message.ticket'])
            ->first();

        if (! $selected?->message) {
            throw ValidationException::withMessages([
                'conversation' => 'The selected Mail placement no longer has an active stored message.',
            ]);
        }

        $placements = EmailMailboxPlacement::query()
            ->where('account_id', $durableConversation->account_id)
            ->where('email_conversation_id', $durableConversation->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereHas('message')
            ->with(['account:id,address,account_kind,owner_id,is_active', 'folder', 'message.attachments', 'message.ticket'])
            ->orderBy('id')
            ->get();

        if (! $placements->contains(fn (EmailMailboxPlacement $placement): bool => (int) $placement->id === (int) $selected->id)) {
            throw ValidationException::withMessages([
                'conversation' => 'The selected Mail placement is no longer active in this conversation.',
            ]);
        }

        $fingerprintSchema = EmailSmartInboxSuggestion::fingerprintSchemaForNewRows();
        $source = $this->conversationFingerprint->forConversation(
            $durableConversation,
            $fingerprintSchema,
        );

        if ($source['source_message_ids'] === []) {
            throw ValidationException::withMessages([
                'conversation' => 'This Mail conversation has no active source messages to analyze.',
            ]);
        }

        // SummarizeEmailWithAi owns the Integration data-egress gate and is
        // invoked exactly once for this explicit user request.
        $summary = $this->summarizeEmailWithAi->handle($selected, $actor, $placements);
        $current = $this->conversationFingerprint->forConversation(
            $durableConversation,
            $fingerprintSchema,
        );

        if (! hash_equals($source['fingerprint'], $current['fingerprint'])) {
            throw ValidationException::withMessages([
                'conversation' => 'The Mail conversation changed during analysis. Review the new mail and analyze again.',
            ]);
        }

        $attachmentNames = EmailAttachment::query()
            ->whereIn('message_id', $source['source_message_ids'])
            ->pluck('filename')
            ->filter(fn (mixed $filename): bool => is_string($filename) && trim($filename) !== '')
            ->map(fn (string $filename): string => trim($filename))
            ->values()
            ->all();

        $normalized = collect($this->normalizer->fromSummary(
            $summary,
            $source['source_message_ids'],
            $attachmentNames,
            (int) $durableConversation->account_id,
            (int) $selected->email_folder_id,
        ))
            // Provider cleanup is always about the exact placement being
            // reviewed. Suggestions for another conversation message are not
            // silently retargeted; the user can select that message and run a
            // separate analysis instead.
            ->reject(fn (array $candidate): bool => $this->isCleanupEffect($candidate['effect_type'])
                && isset($candidate['proposal']['source_message_id'])
                && (int) $candidate['proposal']['source_message_id'] !== (int) $selected->email_message_id)
            ->map(function (array $candidate) use ($selected): array {
                if (! $this->isCleanupEffect($candidate['effect_type'])) {
                    return $candidate;
                }

                $candidate['proposal'] = array_merge($candidate['proposal'], [
                    'source_message_id' => (int) $selected->email_message_id,
                    'source_placement_id' => (int) $selected->id,
                    'source_folder_id' => (int) $selected->email_folder_id,
                    'source_folder_path' => (string) $selected->folder_path,
                    'source_imap_uid' => (int) $selected->imap_uid,
                    'source_uid_validity' => (int) $selected->imap_uid_validity,
                    'source_sync_version' => (int) $selected->sync_version,
                ]);

                return $candidate;
            })
            ->values()
            ->all();
        $trace = $this->trace($summary);

        return DB::transaction(function () use (
            $normalized,
            $source,
            $trace,
            $durableConversation,
            $selected,
            $actor,
        ): Collection {
            $lockedConversation = EmailConversation::query()
                ->whereKey($durableConversation->id)
                ->where('account_id', $durableConversation->account_id)
                ->where('status', EmailConversation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->firstOrFail();
            $currentActor = User::query()
                ->whereKey($actor->id)
                ->where('status', User::STATUS_ACTIVE)
                ->first();
            $currentAccount = EmailAccount::query()
                ->whereKey($lockedConversation->account_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            // The governed AI call may be slow. Re-authorize against fresh
            // rows before persisting anything so a grant or account revoked
            // during provider processing cannot create new review state.
            if (! $currentActor
                || ! $currentAccount
                || ! $this->mailboxAccess->canAccessAccount($currentActor, $currentAccount, MailboxAccess::VIEW)) {
                throw new AuthorizationException('You need current mailbox View access before Smart Inbox suggestions can be saved.');
            }

            $transactionSource = $this->conversationFingerprint->forConversation(
                $lockedConversation,
                $source['schema_version'],
            );
            $selectedIsStillActive = EmailMailboxPlacement::query()
                ->whereKey($selected->id)
                ->where('account_id', $lockedConversation->account_id)
                ->where('email_conversation_id', $lockedConversation->id)
                ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->exists();

            if (! $selectedIsStillActive
                || ! hash_equals($source['fingerprint'], $transactionSource['fingerprint'])) {
                throw ValidationException::withMessages([
                    'conversation' => 'The Mail conversation changed during analysis. Review the new mail and analyze again.',
                ]);
            }

            return collect($normalized)->map(function (array $candidate) use (
                $source,
                $trace,
                $lockedConversation,
                $selected,
                $currentActor,
            ): EmailSmartInboxSuggestion {
                $proposalFingerprint = $this->identity->checksum($candidate['proposal']);
                $idempotencyKey = $this->identity->checksum([
                    'schema_version' => EmailSmartInboxSuggestion::SCHEMA_VERSION,
                    'user_id' => (int) $currentActor->id,
                    'account_id' => (int) $lockedConversation->account_id,
                    'conversation_id' => (int) $lockedConversation->id,
                    'source_fingerprint' => $source['fingerprint'],
                    'effect_type' => $candidate['effect_type'],
                    'proposal_fingerprint' => $proposalFingerprint,
                ]);

                $suggestionAttributes = [
                    'user_id' => $currentActor->id,
                    'account_id' => $lockedConversation->account_id,
                    'email_conversation_id' => $lockedConversation->id,
                    'selected_email_mailbox_placement_id' => $selected->id,
                    'effect_type' => $candidate['effect_type'],
                    'proposal_json' => $candidate['proposal'],
                    'proposal_fingerprint' => $proposalFingerprint,
                    'explanation' => $candidate['explanation'],
                    'confidence' => $candidate['confidence'],
                    'source_fingerprint' => $source['fingerprint'],
                    'source_message_ids_json' => $source['source_message_ids'],
                    'schema_version' => EmailSmartInboxSuggestion::SCHEMA_VERSION,
                    'status' => EmailSmartInboxSuggestion::STATUS_PENDING,
                    'ai_execution_id' => $trace['execution_id'],
                    'ai_agent_id' => $trace['agent_id'],
                    'ai_provider_id' => $trace['provider_id'],
                    'ai_model' => $trace['model'],
                    'ai_policy_revision' => $trace['policy_revision'],
                    'ai_trace_json' => $trace['details'],
                    'generated_at' => now(),
                ];

                // A migration may finish while an AI request is running. If
                // the marker is now available, persist the exact schema used
                // at request start rather than relabelling a legacy digest.
                if (EmailSmartInboxSuggestion::supportsSourceFingerprintSchema()) {
                    $suggestionAttributes['source_fingerprint_schema'] = $source['schema_version'];
                }

                $suggestion = EmailSmartInboxSuggestion::query()->firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    $suggestionAttributes,
                );

                if ($suggestion->wasRecentlyCreated) {
                    $this->eventRecorder->record(
                        $suggestion,
                        EmailSmartInboxSuggestionEvent::TYPE_GENERATED,
                        $currentActor,
                    );
                }

                return $suggestion;
            })->values();
        });
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{execution_id: string|null, agent_id: int|null, provider_id: string|null, model: string|null, policy_revision: int|null, details: array<string, mixed>}
     */
    private function trace(array $summary): array
    {
        $metadata = is_array($summary['metadata'] ?? null) ? $summary['metadata'] : [];
        $agentId = is_numeric($metadata['agent_id'] ?? null) ? (int) $metadata['agent_id'] : null;
        $agent = $agentId ? AiAgent::query()->with('provider')->find($agentId) : null;
        $executionId = $this->boundedScalar($metadata['execution_id'] ?? null, 100);
        $model = $this->boundedScalar($metadata['model'] ?? $agent?->model, 191);
        $policyRevision = is_numeric($metadata['policy_revision'] ?? null)
            ? max(0, (int) $metadata['policy_revision'])
            : null;

        return [
            'execution_id' => $executionId,
            'agent_id' => $agent?->id,
            'provider_id' => $agent?->ai_provider_id,
            'model' => $model,
            'policy_revision' => $policyRevision,
            'details' => [
                'source' => in_array(($metadata['source'] ?? null), ['default_agent'], true)
                    ? $metadata['source']
                    : 'governed_mail_summary',
                'workload_id' => is_numeric($metadata['workload_id'] ?? null)
                    ? (int) $metadata['workload_id']
                    : null,
                'workload_slug' => $this->boundedScalar($metadata['workload_slug'] ?? null, 100),
                'processing_mode' => $this->boundedScalar($metadata['processing_mode'] ?? null, 60),
                'data_profile' => $this->boundedScalar($metadata['data_profile'] ?? null, 60),
                'response_schema' => SummarizeEmailWithAi::RESPONSE_SCHEMA_VERSION,
            ],
        ];
    }

    private function boundedScalar(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim(strip_tags((string) $value));

        return $value !== '' ? Str::limit($value, $limit, '') : null;
    }

    private function isCleanupEffect(string $effectType): bool
    {
        return in_array($effectType, [
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
        ], true);
    }
}
