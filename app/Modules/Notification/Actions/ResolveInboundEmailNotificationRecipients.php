<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Notification\Models\NotificationInboundEmailFanout;
use App\Modules\Notification\Models\NotificationInboundEmailScope;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;

/** Resolve only one bounded fanout page or one exact delivery recipient. */
class ResolveInboundEmailNotificationRecipients
{
    public const TYPE_TICKET_CUSTOMER_REPLY_RECEIVED = 'ticket_customer_reply_received';

    public const TYPE_INBOUND_EMAIL_RECEIVED = 'inbound_email_received';

    public function __construct(private readonly MailboxAccess $mailboxAccess) {}

    /**
     * Freeze the exact bounded page range before a worker claim becomes
     * RUNNING. Sparse setting IDs are safe because a short page advances its
     * ceiling to the immutable event high-water; a non-final page must contain
     * the full remaining capacity.
     *
     * @return array{
     *     setting_through_id:int,
     *     setting_row_count:int,
     *     owner_pending:bool,
     *     owner_candidate_included:bool
     * }
     */
    public function pageWitness(
        NotificationInboundEmailFanout $fanout,
        int $limit,
    ): array {
        $limit = max(1, min(100, $limit));
        $ownerPending = ! (bool) $fanout->owner_candidate_processed;
        $ownerIncluded = $ownerPending && (int) $fanout->ticket_owner_user_id > 0;
        $settingLimit = $limit - ($ownerIncluded ? 1 : 0);
        $cursorId = (int) $fanout->notification_setting_cursor_id;
        $throughId = (int) $fanout->notification_setting_through_id;

        $settingIds = NotificationSetting::query()
            ->where('notification_type', self::TYPE_INBOUND_EMAIL_RECEIVED)
            ->where('id', '>', $cursorId)
            ->where('id', '<=', $throughId)
            ->orderBy('id')
            ->limit($settingLimit + 1)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $hasSuccessor = count($settingIds) > $settingLimit;
        $includedIds = array_slice($settingIds, 0, $settingLimit);
        $pageThroughId = $hasSuccessor
            ? (int) end($includedIds)
            : $throughId;

        return [
            'setting_through_id' => $pageThroughId,
            'setting_row_count' => count($includedIds),
            'owner_pending' => $ownerPending,
            'owner_candidate_included' => $ownerIncluded,
        ];
    }

    /**
     * @return array{
     *     candidates:array<int,array{user_id:int,notification_type:string,owner_candidate:bool,notification_setting_id:?int}>,
     *     owner_processed:bool,
     *     cursor_id:int
     * }
     */
    public function candidatePage(NotificationInboundEmailFanout $fanout): array
    {
        $candidates = [];
        $ownerProcessed = (bool) $fanout->owner_candidate_processed;
        $ownerPending = (bool) $fanout->page_owner_pending;
        $ownerIncluded = (bool) $fanout->page_owner_candidate_included;
        $settingLimit = (int) $fanout->page_setting_row_count;
        $pageThroughId = (int) $fanout->page_setting_through_id;

        if ($ownerPending) {
            $ownerProcessed = true;
            if ($ownerIncluded) {
                $candidates[] = [
                    'user_id' => (int) $fanout->ticket_owner_user_id,
                    'notification_type' => self::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
                    'owner_candidate' => true,
                    'notification_setting_id' => null,
                ];
            }
        }

        $cursorId = (int) $fanout->notification_setting_cursor_id;
        if ($settingLimit < 1 || $cursorId >= $pageThroughId) {
            return [
                'candidates' => $candidates,
                'owner_processed' => $ownerProcessed,
                'cursor_id' => $pageThroughId,
            ];
        }

        $settings = NotificationSetting::query()
            ->where('notification_type', self::TYPE_INBOUND_EMAIL_RECEIVED)
            ->where('id', '>', $cursorId)
            ->where('id', '<=', $pageThroughId)
            ->orderBy('id')
            ->limit($settingLimit + 1)
            ->get(['id', 'user_id']);
        if ($settings->count() !== $settingLimit) {
            throw new \RuntimeException('inbound_notification_page_witness_drift');
        }

        foreach ($settings as $setting) {
            $candidates[] = [
                'user_id' => (int) $setting->user_id,
                'notification_type' => self::TYPE_INBOUND_EMAIL_RECEIVED,
                'owner_candidate' => false,
                'notification_setting_id' => (int) $setting->id,
            ];
        }

        return [
            'candidates' => $candidates,
            'owner_processed' => $ownerProcessed,
            'cursor_id' => $pageThroughId,
        ];
    }

    /**
     * Reauthorize one current recipient without scanning any other settings.
     * Owner defaults are synthesized in memory; generic candidates require the
     * exact explicit setting row that was inside the frozen high-water.
     *
     * @return array{
     *     authorized:bool,
     *     reason:string,
     *     reserve_owner?:bool,
     *     notification_setting_id?:?int,
     *     user?:User,
     *     email?:EmailMessage,
     *     ticket?:?Ticket,
     *     channels?:array{database:bool,mail:bool,web_push:bool,nextcloud_talk:bool,preview:bool,nextcloud_talk_webhook_url:?string}
     * }
     */
    public function authorizeExact(
        NotificationInboundEmailFanout $fanout,
        int $userId,
        string $notificationType,
        bool $ownerCandidate = false,
        ?int $notificationSettingId = null,
        bool $settingIdentityFrozen = false,
    ): array {
        if (! in_array($notificationType, [
            self::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
            self::TYPE_INBOUND_EMAIL_RECEIVED,
        ], true)) {
            return $this->denied('inbound_notification_type_invalid');
        }

        $user = User::query()->find($userId);
        if (! $user?->isActive() || $user->isSystemActor()) {
            return $this->denied('inbound_notification_recipient_revoked');
        }

        $email = EmailMessage::query()
            ->whereKey($fanout->email_message_id)
            ->where('account_id', $fanout->email_account_id)
            ->whereHas('placements', function ($placements): void {
                $placements
                    ->whereColumn('email_mailbox_placements.account_id', 'email_messages.account_id')
                    ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                    ->where('sync_status', EmailMailboxPlacement::SYNC_SYNCED)
                    ->whereNull('provider_missing_at');
            })
            ->first();
        if (! $email) {
            return $this->denied('inbound_notification_source_revoked');
        }
        $email->load('tags');
        if ($email->state === 'archived'
            || $email->tags->contains(fn (Tag $tag): bool => in_array(
                strtolower((string) ($tag->slug ?: $tag->name)),
                ['spam', 'junk', 'not-ticket'],
                true,
            ))) {
            return $this->denied('inbound_notification_source_revoked');
        }

        $ticket = null;
        if ($fanout->ticket_id !== null) {
            if ((int) $email->ticket_id !== (int) $fanout->ticket_id) {
                return $this->denied('inbound_notification_ticket_scope_drift');
            }

            $ticket = Ticket::query()
                ->whereKey($fanout->ticket_id)
                ->where('queue_id', $fanout->ticket_queue_id)
                ->first();
            if (! $ticket) {
                return $this->denied('inbound_notification_ticket_scope_drift');
            }
        } elseif ($email->ticket_id !== null) {
            return $this->denied('inbound_notification_ticket_scope_drift');
        }

        $settingQuery = NotificationSetting::query()
            ->where('user_id', $user->id)
            ->where('notification_type', $notificationType);
        $setting = $ownerCandidate
            ? ($settingIdentityFrozen && $notificationSettingId !== null
                ? (clone $settingQuery)->whereKey($notificationSettingId)->first()
                : ($settingIdentityFrozen ? null : $settingQuery->first()))
            : (clone $settingQuery)
                ->whereKey($notificationSettingId)
                ->where('id', '<=', $fanout->notification_setting_through_id)
                ->first();

        if ($ownerCandidate) {
            if ($notificationType !== self::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED
                || ! $ticket
                || (int) $fanout->ticket_owner_user_id !== (int) $user->id
                || (int) $ticket->owner_id !== (int) $user->id
                || ! $user->can('ticket.view')) {
                return $this->denied('inbound_notification_recipient_revoked');
            }
            if ($settingIdentityFrozen
                && (($notificationSettingId !== null && ! $setting)
                    || ($notificationSettingId === null && $settingQuery->exists()))) {
                return $this->denied('inbound_notification_recipient_revoked');
            }

            $channels = $setting
                ? $this->channels($setting)
                : $this->defaultChannels($notificationType);
        } else {
            if ($notificationType !== self::TYPE_INBOUND_EMAIL_RECEIVED || ! $setting) {
                return $this->denied('inbound_notification_recipient_revoked');
            }

            if ($ticket) {
                if (! $user->can('ticket.view')) {
                    return $this->denied('inbound_notification_recipient_revoked');
                }
            } elseif (! $this->mailboxAccess->scopeMessages(
                EmailMessage::query()->whereKey($email->id),
                $user,
            )->exists()) {
                return $this->denied('inbound_notification_recipient_revoked');
            }

            if (! $this->scopeAllowsExact($user->id, $email, $ticket)) {
                return $this->denied('inbound_notification_scope_revoked');
            }

            $channels = $this->channels($setting);
        }

        if (! $channels['database']
            && ! $channels['mail']
            && ! $channels['web_push']
            && ! $channels['nextcloud_talk']) {
            return $this->denied(
                'inbound_notification_channels_disabled',
                reserveOwner: $ownerCandidate,
            );
        }

        return [
            'authorized' => true,
            'reason' => '',
            'reserve_owner' => $ownerCandidate,
            'notification_setting_id' => $setting ? (int) $setting->id : null,
            'user' => $user,
            'email' => $email,
            'ticket' => $ticket,
            'channels' => $channels,
        ];
    }

    private function scopeAllowsExact(int $userId, EmailMessage $email, ?Ticket $ticket): bool
    {
        $scope = NotificationInboundEmailScope::query()
            ->where('user_id', $userId)
            ->where('notification_type', self::TYPE_INBOUND_EMAIL_RECEIVED);
        if (! (clone $scope)->exists()) {
            return true;
        }

        if ((clone $scope)
            ->where('scope_kind', NotificationInboundEmailScope::KIND_EMAIL_ACCOUNT)
            ->where('scope_id', $email->account_id)
            ->exists()) {
            return true;
        }

        return $ticket !== null
            && (clone $scope)
                ->where('scope_kind', NotificationInboundEmailScope::KIND_TICKET_QUEUE)
                ->where('scope_id', $ticket->queue_id)
                ->exists();
    }

    /** @return array{database:bool,mail:bool,web_push:bool,nextcloud_talk:bool,preview:bool,nextcloud_talk_webhook_url:?string} */
    private function channels(NotificationSetting $setting): array
    {
        $talkUrl = is_string($setting->nextcloud_talk_webhook_url)
            && trim($setting->nextcloud_talk_webhook_url) !== ''
                ? $setting->nextcloud_talk_webhook_url
                : null;

        return [
            'database' => (bool) $setting->database_enabled,
            'mail' => (bool) $setting->mail_enabled,
            'web_push' => (bool) $setting->web_push_enabled,
            'nextcloud_talk' => (bool) $setting->nextcloud_talk_enabled,
            'preview' => (bool) $setting->web_push_preview_enabled,
            'nextcloud_talk_webhook_url' => $talkUrl,
        ];
    }

    /** @return array{database:bool,mail:bool,web_push:bool,nextcloud_talk:bool,preview:bool,nextcloud_talk_webhook_url:?string} */
    private function defaultChannels(string $notificationType): array
    {
        $defaults = NotificationSetting::defaultsForType($notificationType);

        return [
            'database' => (bool) ($defaults['database_enabled'] ?? false),
            'mail' => (bool) ($defaults['mail_enabled'] ?? false),
            'web_push' => (bool) ($defaults['web_push_enabled'] ?? false),
            'nextcloud_talk' => (bool) ($defaults['nextcloud_talk_enabled'] ?? false),
            'preview' => (bool) ($defaults['web_push_preview_enabled'] ?? false),
            'nextcloud_talk_webhook_url' => null,
        ];
    }

    /** @return array{authorized:false,reason:string,reserve_owner?:bool} */
    private function denied(string $reason, bool $reserveOwner = false): array
    {
        return [
            'authorized' => false,
            'reason' => $reason,
            'reserve_owner' => $reserveOwner,
        ];
    }
}
