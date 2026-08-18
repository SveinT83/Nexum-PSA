<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailUnreadHandoverItem;
use Illuminate\Support\Collection;

class EmailUnreadHandoverFingerprint
{
    public function __construct(
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
    ) {}

    public function authorization(
        User $actor,
        User $target,
        EmailAccount $account,
        EmailAccountUserReadBaseline $baseline,
    ): string {
        $sources = $this->entitlements->describeCurrentSources($account, $target);

        return $this->checksum([
            'actor_id' => (int) $actor->id,
            'actor_active' => $actor->isActive(),
            'actor_can_manage' => $account->isPersonal()
                ? (int) $account->owner_id === (int) $actor->id
                : $actor->can('email.account_manage'),
            'account_id' => (int) $account->id,
            'account_kind' => (string) $account->account_kind,
            'account_owner_id' => $account->owner_id === null ? null : (int) $account->owner_id,
            'account_active' => (bool) $account->is_active,
            'target_id' => (int) $target->id,
            'target_status' => (string) $target->status,
            'target_can_view' => $target->can('email.inbox_view'),
            'ordinary_sources' => $sources['fingerprint'],
            'baseline_id' => (int) $baseline->id,
            'access_epoch' => (int) $baseline->access_epoch,
            'baseline_message_id' => (int) $baseline->baseline_message_id,
            'ordinary_view_entitled' => (bool) $baseline->ordinary_view_entitled,
        ]);
    }

    /**
     * @param  Collection<int, EmailUnreadHandoverItem>|array<int, array<string, int>>  $items
     * @param  array<int, int>  $folderIds
     */
    public function snapshot(
        Collection|array $items,
        array $folderIds,
        string $dateFrom,
        string $dateTo,
        int $accessEpoch,
    ): string {
        $normalizedItems = collect($items)
            ->map(function (EmailUnreadHandoverItem|array $item): array {
                if ($item instanceof EmailUnreadHandoverItem) {
                    return [
                        'order' => (int) $item->snapshot_order,
                        'message_id' => (int) $item->email_message_id,
                        'placement_id' => (int) $item->email_mailbox_placement_id,
                        'folder_id' => (int) $item->email_folder_id,
                    ];
                }

                return [
                    'order' => (int) $item['snapshot_order'],
                    'message_id' => (int) $item['email_message_id'],
                    'placement_id' => (int) $item['email_mailbox_placement_id'],
                    'folder_id' => (int) $item['email_folder_id'],
                ];
            })
            ->sortBy('order')
            ->values()
            ->all();
        sort($folderIds, SORT_NUMERIC);

        return $this->checksum([
            'access_epoch' => $accessEpoch,
            'folder_ids' => array_values($folderIds),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'items' => $normalizedItems,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
