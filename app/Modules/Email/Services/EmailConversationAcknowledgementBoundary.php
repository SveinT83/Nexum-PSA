<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EmailConversationAcknowledgementBoundary
{
    public function __construct(
        private readonly ResolveMailboxAccessDecision $accessDecisions,
        private readonly EmailAccountProviderRuntimeResolver $providerRuntime,
        private readonly EmailUnreadForMeResolver $unreadForMe,
    ) {}

    public function assertAvailable(): void
    {
        if (! config('email_live.conversation_acknowledgement_enabled', false)
            || ! Schema::hasTable('email_conversation_action_runs')
            || ! Schema::hasTable('email_conversation_action_items')) {
            throw new EmailConversationAcknowledgementUnavailable;
        }
    }

    public function previewCap(?int $requestedCap = null): int
    {
        $default = max(1, (int) config('email_live.conversation_acknowledgement_preview_cap', 100));
        $hard = min(500, max(1, (int) config('email_live.conversation_acknowledgement_hard_cap', 500)));
        $cap = $requestedCap ?? min($default, $hard);

        if ($cap < 1 || $cap > $hard) {
            throw ValidationException::withMessages([
                'item_cap' => "Conversation acknowledgement is limited to {$hard} placements per preview.",
            ]);
        }

        return $cap;
    }

    public function maximumAccounts(): int
    {
        return min(20, max(1, (int) config('email_live.conversation_acknowledgement_max_accounts', 20)));
    }

    public function previewTtlMinutes(): int
    {
        return min(15, max(1, (int) config('email_live.conversation_acknowledgement_preview_ttl_minutes', 15)));
    }

    public function authorize(User $actor, EmailAccount $account, string $operation): void
    {
        if ($actor->isSystemActor()) {
            throw new AuthorizationException('This mailbox action is not available.');
        }

        $decision = $this->accessDecisions->resolve($actor, $account, $operation);

        if (! $decision->allowed || $decision->usesBreakGlass()) {
            throw new AuthorizationException('This mailbox action is not available.');
        }
    }

    public function accessEpoch(User $actor, EmailAccount $account): int
    {
        $baseline = EmailAccountUserReadBaseline::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $actor->id)
            ->where('ordinary_view_entitled', true)
            ->first();

        if (! $baseline || (int) $baseline->access_epoch < 1) {
            throw new AuthorizationException('Personal unread is not available for this mailbox access.');
        }

        return (int) $baseline->access_epoch;
    }

    public function personalUnread(User $actor, EmailMessage $message): bool
    {
        $isUnread = $this->unreadForMe->resolve($message, $actor);

        if ($isUnread === null) {
            throw new AuthorizationException('Personal unread is not available for this mailbox access.');
        }

        return $isUnread;
    }

    public function providerBindingVersion(EmailAccount $account): int
    {
        return $this->providerRuntime->captureBindingVersion($account);
    }

    public function sourceFingerprint(
        EmailMailboxPlacement $placement,
        EmailMessage $message,
    ): string {
        return hash('sha256', json_encode([
            'account_id' => (int) $placement->account_id,
            'conversation_id' => (int) $placement->email_conversation_id,
            'message_id' => (int) $placement->email_message_id,
            'message_account_id' => (int) $message->account_id,
            'message_updated_at' => $message->getRawOriginal('updated_at'),
            'message_deleted_at' => $message->getRawOriginal('deleted_at'),
            'placement_id' => (int) $placement->id,
            'folder_id' => (int) $placement->email_folder_id,
            'uid_namespace_id' => (int) $placement->uid_namespace_id,
            'uid_validity' => (int) $placement->imap_uid_validity,
            'uid' => (int) $placement->imap_uid,
            'sync_version' => (int) $placement->sync_version,
            'local_state' => (string) $placement->local_state,
            'provider_missing' => $placement->provider_missing_at !== null,
        ], JSON_THROW_ON_ERROR));
    }

    public function itemFingerprint(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
