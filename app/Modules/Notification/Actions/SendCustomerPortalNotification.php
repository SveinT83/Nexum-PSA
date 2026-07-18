<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Notification\Notifications\CustomerPortalNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sends notifications only to portal accounts that can see the scoped record.
 */
class SendCustomerPortalNotification
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>|null  $channels
     */
    public function handle(
        string $type,
        int $clientId,
        ?int $siteId,
        string $title,
        string $body,
        string $url,
        ?string $sourceType = null,
        ?int $sourceId = null,
        bool $clientWideVisibleToSiteMembers = false,
        array $metadata = [],
        ?array $channels = null,
    ): int {
        $payload = [
            'type' => $type,
            'title' => Str::limit($title, 160, ''),
            'body' => Str::limit($body, 500, ''),
            'url' => $this->safePortalUrl($url),
            'client_id' => $clientId,
            'site_id' => $siteId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'metadata' => $metadata,
        ];

        $recipients = $this->recipients($clientId, $siteId, $clientWideVisibleToSiteMembers);

        $recipients->each(fn (User $user) => $user->notify(new CustomerPortalNotification($payload, $channels)));

        return $recipients->count();
    }

    /**
     * @return Collection<int, User>
     */
    public function recipients(int $clientId, ?int $siteId, bool $clientWideVisibleToSiteMembers = false): Collection
    {
        return CustomerPortalAccount::query()
            ->with(['user', 'contact', 'memberships'])
            ->where('status', CustomerPortalAccount::STATUS_ACTIVE)
            ->whereHas('memberships', function ($query) use ($clientId, $siteId, $clientWideVisibleToSiteMembers): void {
                $query->where('status', CustomerPortalMembership::STATUS_ACTIVE)
                    ->where('client_id', $clientId);

                if ($siteId) {
                    $query->where(fn ($scope) => $scope->whereNull('site_id')->orWhere('site_id', $siteId));
                } elseif (! $clientWideVisibleToSiteMembers) {
                    $query->whereNull('site_id');
                }
            })
            ->get()
            ->filter(function (CustomerPortalAccount $account): bool {
                if (! $account->user?->isActive() || ! $account->contact) {
                    return false;
                }

                if ((int) $account->user->contact_id !== (int) $account->contact_id) {
                    return false;
                }

                return ($account->contact->status ?? 'active') === 'active';
            })
            ->map(fn (CustomerPortalAccount $account) => $account->user)
            ->unique('id')
            ->values();
    }

    private function safePortalUrl(string $url): string
    {
        $portalRoot = url('/portal');

        if (str_starts_with($url, $portalRoot) || str_starts_with($url, '/portal')) {
            return $url;
        }

        return route('customer-portal.dashboard');
    }
}
