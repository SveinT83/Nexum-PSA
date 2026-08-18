<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Notification\Notifications\MailboxBreakGlassActivatedNotification;
use Illuminate\Support\Collection;

class DispatchMailboxBreakGlassActivationNotice
{
    public const TYPE = 'mailbox_break_glass_activated';

    public function __construct(private readonly RecordCanonicalNotification $notifications) {}

    public function handle(int $accessId): void
    {
        $access = EmailBreakGlassAccess::query()
            ->with(['account.owner', 'actor'])
            ->find($accessId);

        if (! $access?->account || ! $access->actor) {
            return;
        }

        $owner = $access->account->owner;
        $activeOwner = $owner instanceof User && $owner->isActive() && ! $owner->isSystemActor()
            ? $owner
            : null;
        $securityRecipients = $this->securityRecipients();
        $payload = $this->payload($access);

        // Deliver the owner first and checkpoint that category independently. If a later security
        // recipient fails, the queue retry resumes idempotently without losing evidence that the
        // active owner already received the mandatory notice.
        if ($activeOwner) {
            $this->deliver($access, $activeOwner, $payload);
            EmailBreakGlassAccess::query()
                ->whereKey($access->id)
                ->whereNull('owner_notification_sent_at')
                ->update(['owner_notification_sent_at' => now()]);
        }

        foreach ($securityRecipients as $recipient) {
            $this->deliver($access, $recipient, $payload);
        }

        // This timestamp means every currently eligible security recipient completed. A partial
        // loop throws before this checkpoint, and the idempotent delivery identities make retry safe.
        if ($securityRecipients->isNotEmpty()) {
            EmailBreakGlassAccess::query()
                ->whereKey($access->id)
                ->whereNull('security_notification_sent_at')
                ->update(['security_notification_sent_at' => now()]);
        }
    }

    /** @return Collection<int, User> */
    private function securityRecipients(): Collection
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('is_system_actor')->orWhere('is_system_actor', false);
            })
            ->permission('email.break_glass_audit')
            ->get();
    }

    /** @return array<string, mixed> */
    private function payload(EmailBreakGlassAccess $access): array
    {
        return [
            'type' => self::TYPE,
            'title' => 'Emergency mailbox access activated',
            'email_account_id' => (int) $access->email_account_id,
            'account' => (string) $access->account->address,
            'actor_id' => (int) $access->actor_id,
            'actor' => (string) $access->actor->name,
            'operations' => $this->operations($access),
            'reason' => (string) $access->reason,
            'expires_at' => $access->expires_at?->utc()->toIso8601String(),
            'revoked_at' => $access->revoked_at?->utc()->toIso8601String(),
            'url' => route('tech.mail.access.history', [
                'account' => (int) $access->email_account_id,
            ], false),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function deliver(EmailBreakGlassAccess $access, User $recipient, array $payload): void
    {
        $this->notifications->handle(
            user: $recipient,
            notificationClass: MailboxBreakGlassActivatedNotification::class,
            deliveryIdentity: 'mailbox-break-glass:'.$access->id.':user:'.$recipient->id,
            data: $payload,
            unread: true,
        );
    }

    /** @return list<string> */
    private function operations(EmailBreakGlassAccess $access): array
    {
        return array_keys(array_filter([
            'content_view' => $access->can_view_content,
            'search' => $access->can_search,
            'attachment_download' => $access->can_download_attachments,
            'raw_source' => $access->can_view_raw_source,
        ]));
    }
}
