<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use Illuminate\Support\Collection;

class MarkNotificationsReadBySource
{
    public const SOURCE_TICKET_MESSAGE = 'ticket_message';

    public const SOURCE_EMAIL_MESSAGE = 'email_message';

    /**
     * @param  array<int, int>|Collection<int, int>  $sourceIds
     * @param  array<int, int>|Collection<int, int>  $relatedEmailMessageIds
     * @return array{updated: int, web_push_tags: list<string>}
     */
    public function handle(User $user, string $sourceType, array|Collection $sourceIds, array|Collection $relatedEmailMessageIds = []): array
    {
        $sourceIds = $this->normalizeIds($sourceIds);
        $relatedEmailMessageIds = $this->normalizeIds($relatedEmailMessageIds);

        if (($sourceIds->isEmpty() && $relatedEmailMessageIds->isEmpty())
            || ! in_array($sourceType, [self::SOURCE_TICKET_MESSAGE, self::SOURCE_EMAIL_MESSAGE], true)) {
            return ['updated' => 0, 'web_push_tags' => []];
        }

        $updated = 0;
        $tags = [];

        $user->unreadNotifications()
            ->where('type', InboundEmailRoutedNotification::class)
            ->latest()
            ->get()
            ->each(function ($notification) use ($sourceType, $sourceIds, $relatedEmailMessageIds, &$updated, &$tags): void {
                $data = $notification->data;

                if (! $this->matchesSource($data, $sourceType, $sourceIds, $relatedEmailMessageIds)) {
                    return;
                }

                $notification->markAsRead();
                $updated++;

                if (is_string($data['web_push_tag'] ?? null)) {
                    $tags[] = $data['web_push_tag'];
                }
            });

        return [
            'updated' => $updated,
            'web_push_tags' => array_values(array_unique($tags)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, int>  $sourceIds
     * @param  Collection<int, int>  $relatedEmailMessageIds
     */
    private function matchesSource(array $data, string $sourceType, Collection $sourceIds, Collection $relatedEmailMessageIds): bool
    {
        if ($sourceType === self::SOURCE_TICKET_MESSAGE) {
            return in_array($data['type'] ?? null, [
                ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
                ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            ], true)
                && (
                    $sourceIds->contains((int) ($data['ticket_message_id'] ?? 0))
                    || $relatedEmailMessageIds->contains((int) ($data['email_message_id'] ?? 0))
                );
        }

        return ($data['type'] ?? null) === ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED
            && $sourceIds->contains((int) ($data['email_message_id'] ?? 0));
    }

    /**
     * @param  array<int, int>|Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function normalizeIds(array|Collection $ids): Collection
    {
        return collect($ids)
            ->map(fn ($sourceId): int => (int) $sourceId)
            ->filter()
            ->unique()
            ->values();
    }
}
