<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Services\EmailLiveInvalidator;
use App\Modules\Email\Services\EmailOrdinaryMailboxEntitlementResolver;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\EmailUnreadSchemaState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetEmailUnreadForMe
{
    public function __construct(
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
        private readonly EmailUnreadAccessEpochService $epochs,
        private readonly EmailUnreadSchemaState $schemaState,
        private readonly EmailLiveInvalidator $invalidator,
    ) {}

    public function handle(User $actor, EmailMessage $message, bool $isUnread): EmailMessageUserState
    {
        $liveOperationId = (string) Str::uuid();

        return DB::transaction(function () use ($actor, $isUnread, $liveOperationId, $message): EmailMessageUserState {
            $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($message->account_id);
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->id);

            if ($lockedActor->isSystemActor()
                || ! $this->entitlements->hasCurrentViewAccess($lockedAccount, $lockedActor)) {
                throw new AuthorizationException('Personal unread is unavailable for this mailbox access.');
            }

            $usesEpochs = $this->schemaState->usesAccessEpochs();

            if (! $usesEpochs && ! $this->schemaState->usesLegacyState()) {
                throw new AuthorizationException('Personal unread is temporarily unavailable.');
            }

            $baseline = $usesEpochs
                ? $this->epochs->ensureCurrentEntitlement($lockedAccount, $lockedActor, $lockedActor)
                : null;

            if ($usesEpochs && ! $baseline) {
                throw new AuthorizationException('Personal unread is unavailable for this mailbox access.');
            }

            $lockedMessage = EmailMessage::query()
                ->where('account_id', $lockedAccount->id)
                ->lockForUpdate()
                ->findOrFail($message->id);
            $state = EmailMessageUserState::query()
                ->where('email_message_id', $lockedMessage->id)
                ->where('user_id', $lockedActor->id)
                ->when(
                    $usesEpochs,
                    fn ($query) => $query->where('access_epoch', $baseline->access_epoch),
                )
                ->lockForUpdate()
                ->first();
            $now = now();

            $attributes = [
                'email_message_id' => $lockedMessage->id,
                'user_id' => $lockedActor->id,
                'opened_count' => 0,
            ];
            if ($usesEpochs) {
                $attributes['access_epoch'] = $baseline->access_epoch;
            }

            $state ??= new EmailMessageUserState($attributes);
            $state->is_unread = $isUnread;
            $state->marked_read_at = $isUnread ? $state->marked_read_at : $now;
            $state->marked_unread_at = $isUnread ? $now : $state->marked_unread_at;
            $state->save();

            $this->invalidator->record([
                'user' => [
                    $lockedActor->id => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE],
                ],
                'conversations' => $this->conversationIds($lockedMessage),
                'idempotency_key' => "unread-for-me:{$liveOperationId}",
            ]);

            return $state->fresh();
        });
    }

    /** @return list<int> */
    private function conversationIds(EmailMessage $message): array
    {
        return EmailMailboxPlacement::query()
            ->where('email_message_id', $message->id)
            ->whereNotNull('email_conversation_id')
            ->distinct()
            ->orderBy('email_conversation_id')
            ->limit(51)
            ->pluck('email_conversation_id')
            ->map(fn (mixed $identifier): int => (int) $identifier)
            ->filter(fn (int $identifier): bool => $identifier > 0)
            ->values()
            ->all();
    }
}
