<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationActionItem;
use App\Modules\Email\Models\EmailConversationActionRun;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\EmailConversationAcknowledgementBoundary;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PreviewEmailConversationAcknowledgement
{
    public function __construct(
        private readonly EmailConversationAcknowledgementBoundary $boundary,
    ) {}

    public function activeAccountConversation(
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
        string $idempotencyKey,
        bool $targetPersonalUnread = false,
        bool $alsoMarkProviderSeen = false,
        ?int $itemCap = null,
    ): EmailConversationActionRun {
        $this->boundary->assertAvailable();
        $cap = $this->boundary->previewCap($itemCap);
        $this->assertTargets($targetPersonalUnread, $alsoMarkProviderSeen);
        $this->assertIdempotencyKey($idempotencyKey);

        $requestFingerprint = $this->requestFingerprint([
            'actor_id' => (int) $actor->id,
            'scope_kind' => EmailConversationActionRun::SCOPE_ACTIVE_ACCOUNT_CONVERSATION,
            'account_id' => (int) $account->id,
            'conversation_id' => (int) $conversation->id,
            'target_personal_unread' => $targetPersonalUnread,
            'provider_seen_requested' => $alsoMarkProviderSeen,
            'item_cap' => $cap,
        ]);

        if ($existing = $this->existingRun($actor, $idempotencyKey, $requestFingerprint)) {
            return $existing;
        }

        if ((int) $conversation->account_id !== (int) $account->id
            || $conversation->status !== EmailConversation::STATUS_ACTIVE) {
            throw new AuthorizationException('This mailbox action is not available.');
        }

        $this->authorizeAccount($actor, $account, $alsoMarkProviderSeen);

        /** @var Collection<int, EmailMailboxPlacement> $placements */
        $placements = EmailMailboxPlacement::query()
            ->with(['account', 'conversation', 'folder', 'message.account'])
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversation->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->orderBy('id')
            ->limit($cap + 1)
            ->get();

        if ($placements->count() > $cap) {
            throw ValidationException::withMessages([
                'scope' => "Conversation acknowledgement exceeds the {$cap}-placement preview limit.",
            ]);
        }

        return $this->storePreview(
            actor: $actor,
            placements: $placements,
            scopeKind: EmailConversationActionRun::SCOPE_ACTIVE_ACCOUNT_CONVERSATION,
            idempotencyKey: $idempotencyKey,
            requestFingerprint: $requestFingerprint,
            targetPersonalUnread: $targetPersonalUnread,
            alsoMarkProviderSeen: $alsoMarkProviderSeen,
            cap: $cap,
            activeAccountId: (int) $account->id,
            activeConversationId: (int) $conversation->id,
        );
    }

    /**
     * Freeze only identifiers the caller explicitly selected. Correlation,
     * subject, Message-ID and Ticket relationships never add members here.
     *
     * @param  array<int, int|string>  $placementIds
     */
    public function explicitMultiAccount(
        User $actor,
        array $placementIds,
        string $idempotencyKey,
        bool $targetPersonalUnread = false,
        bool $alsoMarkProviderSeen = false,
        ?int $itemCap = null,
    ): EmailConversationActionRun {
        $this->boundary->assertAvailable();
        $cap = $this->boundary->previewCap($itemCap);
        $this->assertTargets($targetPersonalUnread, $alsoMarkProviderSeen);
        $this->assertIdempotencyKey($idempotencyKey);
        $ids = $this->normalizePlacementIds($placementIds, $cap);
        $requestFingerprint = $this->requestFingerprint([
            'actor_id' => (int) $actor->id,
            'scope_kind' => EmailConversationActionRun::SCOPE_EXPLICIT_MULTI_ACCOUNT,
            'placement_ids' => $ids,
            'target_personal_unread' => $targetPersonalUnread,
            'provider_seen_requested' => $alsoMarkProviderSeen,
            'item_cap' => $cap,
        ]);

        if ($existing = $this->existingRun($actor, $idempotencyKey, $requestFingerprint)) {
            return $existing;
        }

        /** @var Collection<int, EmailMailboxPlacement> $placements */
        $placements = EmailMailboxPlacement::query()
            ->with(['account', 'conversation', 'folder', 'message.account'])
            ->whereKey($ids)
            ->orderBy('id')
            ->get();

        if ($placements->count() !== count($ids)) {
            throw new AuthorizationException('This mailbox action is not available.');
        }

        foreach ($placements->pluck('account')->filter()->unique('id') as $account) {
            $this->authorizeAccount($actor, $account, $alsoMarkProviderSeen);
        }

        if ($placements->pluck('account_id')->unique()->count() > $this->boundary->maximumAccounts()) {
            throw ValidationException::withMessages([
                'scope' => 'Conversation acknowledgement includes too many selected accounts.',
            ]);
        }

        return $this->storePreview(
            actor: $actor,
            placements: $placements,
            scopeKind: EmailConversationActionRun::SCOPE_EXPLICIT_MULTI_ACCOUNT,
            idempotencyKey: $idempotencyKey,
            requestFingerprint: $requestFingerprint,
            targetPersonalUnread: $targetPersonalUnread,
            alsoMarkProviderSeen: $alsoMarkProviderSeen,
            cap: $cap,
        );
    }

    /** @param Collection<int, EmailMailboxPlacement> $placements */
    private function storePreview(
        User $actor,
        Collection $placements,
        string $scopeKind,
        string $idempotencyKey,
        string $requestFingerprint,
        bool $targetPersonalUnread,
        bool $alsoMarkProviderSeen,
        int $cap,
        ?int $activeAccountId = null,
        ?int $activeConversationId = null,
    ): EmailConversationActionRun {
        if ($placements->isEmpty()) {
            throw ValidationException::withMessages([
                'scope' => 'No active mailbox placements are available for this acknowledgement.',
            ]);
        }

        $epochs = [];
        $bindings = [];
        $itemSnapshots = [];
        $personalEffectKeys = [];

        foreach ($placements as $placement) {
            $this->assertPlacementSnapshotAvailable($placement);
            $accountId = (int) $placement->account_id;
            $epochs[$accountId] ??= $this->boundary->accessEpoch($actor, $placement->account);
            $bindings[$accountId] ??= $this->boundary->providerBindingVersion($placement->account);
            $personalBefore = $this->boundary->personalUnread($actor, $placement->message);
            $personalEffectKey = implode(':', [
                $accountId,
                (int) $placement->email_message_id,
                $epochs[$accountId],
                $targetPersonalUnread ? 1 : 0,
            ]);
            $personalSelected = ! isset($personalEffectKeys[$personalEffectKey]);
            $personalEffectKeys[$personalEffectKey] = true;
            $sourceFingerprint = $this->boundary->sourceFingerprint($placement, $placement->message);
            $snapshot = [
                'account_id' => $accountId,
                'access_epoch' => $epochs[$accountId],
                'conversation_id' => (int) $placement->email_conversation_id,
                'folder_id' => (int) $placement->email_folder_id,
                'message_id' => (int) $placement->email_message_id,
                'personal_before' => $personalBefore,
                'personal_selected' => $personalSelected,
                'personal_target' => $targetPersonalUnread,
                'placement_id' => (int) $placement->id,
                'provider_before' => (bool) $placement->provider_seen,
                'provider_binding_version' => $bindings[$accountId],
                'provider_selected' => $alsoMarkProviderSeen,
                'provider_target' => true,
                'source_fingerprint' => $sourceFingerprint,
                'sync_version' => (int) $placement->sync_version,
                'uid' => (int) $placement->imap_uid,
                'uid_namespace_id' => (int) $placement->uid_namespace_id,
                'uid_validity' => (int) $placement->imap_uid_validity,
            ];
            $snapshot['item_fingerprint'] = $this->boundary->itemFingerprint($snapshot);
            $snapshot['placement'] = $placement;
            $itemSnapshots[] = $snapshot;
        }

        $scopeFingerprint = hash('sha256', json_encode([
            'scope_kind' => $scopeKind,
            'active_account_id' => $activeAccountId,
            'active_conversation_id' => $activeConversationId,
            'target_personal_unread' => $targetPersonalUnread,
            'provider_seen_requested' => $alsoMarkProviderSeen,
            'items' => array_column($itemSnapshots, 'item_fingerprint'),
        ], JSON_THROW_ON_ERROR));
        $now = now();

        return DB::transaction(function () use (
            $activeAccountId,
            $activeConversationId,
            $actor,
            $alsoMarkProviderSeen,
            $bindings,
            $cap,
            $epochs,
            $idempotencyKey,
            $itemSnapshots,
            $now,
            $requestFingerprint,
            $scopeFingerprint,
            $scopeKind,
            $targetPersonalUnread,
        ): EmailConversationActionRun {
            $existing = EmailConversationActionRun::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingRun($existing, $actor, $requestFingerprint);

                return $existing->load('items');
            }

            $run = EmailConversationActionRun::query()->create([
                'public_id' => (string) Str::uuid(),
                'requested_by' => $actor->id,
                'operation' => EmailConversationActionRun::OPERATION_ACKNOWLEDGE,
                'scope_kind' => $scopeKind,
                'active_email_account_id' => $activeAccountId,
                'active_email_conversation_id' => $activeConversationId,
                'target_personal_unread' => $targetPersonalUnread,
                'provider_seen_requested' => $alsoMarkProviderSeen,
                'status' => EmailConversationActionRun::STATUS_PREVIEWED,
                'item_cap' => $cap,
                'account_count' => count($epochs),
                'item_count' => count($itemSnapshots),
                'request_fingerprint' => $requestFingerprint,
                'scope_fingerprint' => $scopeFingerprint,
                'idempotency_key' => $idempotencyKey,
                'previewed_at' => $now,
                'expires_at' => $now->copy()->addMinutes($this->boundary->previewTtlMinutes()),
            ]);

            foreach ($itemSnapshots as $index => $snapshot) {
                /** @var EmailMailboxPlacement $placement */
                $placement = $snapshot['placement'];
                EmailConversationActionItem::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'run_id' => $run->id,
                    'ordinal' => $index + 1,
                    'account_id' => $placement->account_id,
                    'email_conversation_id' => $placement->email_conversation_id,
                    'email_message_id' => $placement->email_message_id,
                    'email_mailbox_placement_id' => $placement->id,
                    'email_folder_id' => $placement->email_folder_id,
                    'uid_namespace_id' => $placement->uid_namespace_id,
                    'imap_uid_validity' => $placement->imap_uid_validity,
                    'imap_uid' => $placement->imap_uid,
                    'access_epoch' => $epochs[(int) $placement->account_id],
                    'provider_binding_version' => $bindings[(int) $placement->account_id],
                    'placement_sync_version' => $placement->sync_version,
                    'source_fingerprint' => $snapshot['source_fingerprint'],
                    'item_fingerprint' => $snapshot['item_fingerprint'],
                    'personal_selected' => $snapshot['personal_selected'],
                    'personal_before' => $snapshot['personal_before'],
                    'personal_target' => $targetPersonalUnread,
                    'personal_status' => ! $snapshot['personal_selected']
                        ? EmailConversationActionItem::PERSONAL_COALESCED
                        : ($snapshot['personal_before'] === $targetPersonalUnread
                            ? EmailConversationActionItem::PERSONAL_UNCHANGED
                            : EmailConversationActionItem::PERSONAL_PENDING),
                    'personal_reason_code' => $snapshot['personal_selected']
                        ? null
                        : 'personal_effect_coalesced',
                    'provider_selected' => $alsoMarkProviderSeen,
                    'provider_before' => $snapshot['provider_before'],
                    'provider_target' => true,
                    'provider_status' => ! $alsoMarkProviderSeen
                        ? EmailConversationActionItem::PROVIDER_NOT_REQUESTED
                        : ($snapshot['provider_before']
                            ? EmailConversationActionItem::PROVIDER_UNCHANGED
                            : EmailConversationActionItem::PROVIDER_PENDING),
                ]);
            }

            return $run->load('items');
        });
    }

    private function existingRun(
        User $actor,
        string $idempotencyKey,
        string $requestFingerprint,
    ): ?EmailConversationActionRun {
        $existing = EmailConversationActionRun::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing) {
            return null;
        }

        $this->assertExistingRun($existing, $actor, $requestFingerprint);
        $accountIds = $existing->items()->pluck('account_id')->unique();
        $accounts = EmailAccount::query()->whereKey($accountIds)->get()->keyBy('id');

        if ($accounts->count() !== $accountIds->count()) {
            throw new AuthorizationException('This mailbox action is not available.');
        }

        foreach ($accounts as $account) {
            $this->authorizeAccount($actor, $account, (bool) $existing->provider_seen_requested);
        }

        return $existing->load('items');
    }

    private function assertExistingRun(
        EmailConversationActionRun $run,
        User $actor,
        string $requestFingerprint,
    ): void {
        if ((int) $run->requested_by !== (int) $actor->id
            || ! hash_equals((string) $run->request_fingerprint, $requestFingerprint)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This acknowledgement reference is already in use.',
            ]);
        }
    }

    private function authorizeAccount(User $actor, EmailAccount $account, bool $providerSeen): void
    {
        $this->boundary->authorize($actor, $account, MailboxAccess::VIEW);

        if ($providerSeen) {
            $this->boundary->authorize($actor, $account, MailboxAccess::ORGANIZE);
        }
    }

    private function assertPlacementSnapshotAvailable(EmailMailboxPlacement $placement): void
    {
        if (! $placement->account
            || ! $placement->conversation
            || ! $placement->folder
            || ! $placement->message
            || (int) $placement->account_id !== (int) $placement->message->account_id
            || (int) $placement->account_id !== (int) $placement->conversation->account_id
            || $placement->conversation->status !== EmailConversation::STATUS_ACTIVE
            || $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
            || $placement->provider_missing_at !== null
            || (int) $placement->email_folder_id < 1
            || (int) $placement->uid_namespace_id < 1
            || (int) $placement->imap_uid_validity < 1
            || (int) $placement->imap_uid < 1
            || (int) $placement->sync_version < 1) {
            throw new AuthorizationException('This mailbox action is not available.');
        }
    }

    /** @param array<int, int|string> $placementIds
     * @return array<int, int>
     */
    private function normalizePlacementIds(array $placementIds, int $cap): array
    {
        $ids = [];

        foreach ($placementIds as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages([
                    'placements' => 'Choose valid mailbox placements.',
                ]);
            }

            $normalized = (int) $id;
            if ($normalized < 1) {
                throw ValidationException::withMessages([
                    'placements' => 'Choose valid mailbox placements.',
                ]);
            }
            $ids[$normalized] = $normalized;
        }

        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);

        if ($ids === []) {
            throw ValidationException::withMessages([
                'placements' => 'Choose at least one mailbox placement.',
            ]);
        }

        if (count($ids) > $cap) {
            throw ValidationException::withMessages([
                'placements' => "Conversation acknowledgement is limited to {$cap} placements per preview.",
            ]);
        }

        return $ids;
    }

    private function assertTargets(bool $targetPersonalUnread, bool $providerSeen): void
    {
        if ($targetPersonalUnread && $providerSeen) {
            throw ValidationException::withMessages([
                'provider_seen' => 'Provider Seen cannot accompany a personal mark-unread acknowledgement.',
            ]);
        }
    }

    private function assertIdempotencyKey(string $key): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Provide a valid acknowledgement reference.',
            ]);
        }
    }

    /** @param array<string, mixed> $request */
    private function requestFingerprint(array $request): string
    {
        return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
    }
}
