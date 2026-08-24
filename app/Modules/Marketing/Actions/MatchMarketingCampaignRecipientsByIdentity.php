<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Support\Collection;

class MatchMarketingCampaignRecipientsByIdentity
{
    public function handle(
        Collection $recipients,
        MarketingListMember $member,
        ?int $campaignEmailId = null,
    ): Collection {
        return $recipients
            ->filter(function (MarketingCampaignRecipient $recipient) use ($member, $campaignEmailId): bool {
                if ($campaignEmailId !== null && (int) $recipient->marketing_campaign_email_id !== $campaignEmailId) {
                    return false;
                }

                return $this->matches($recipient, $member);
            })
            ->values();
    }

    /**
     * @return array{
     *     contact: array<int, array<int, MarketingCampaignRecipient>>,
     *     client_user: array<int, array<int, MarketingCampaignRecipient>>,
     *     email: array<string, array<int, MarketingCampaignRecipient>>,
     *     list_member: array<int, array<int, MarketingCampaignRecipient>>
     * }
     */
    public function index(Collection $recipients): array
    {
        $index = [
            'contact' => [],
            'client_user' => [],
            'email' => [],
            'list_member' => [],
        ];

        foreach ($recipients as $recipient) {
            if ($recipient->marketing_list_member_id) {
                $index['list_member'][(int) $recipient->marketing_list_member_id][] = $recipient;
            }

            if ($recipient->contact_id) {
                $index['contact'][(int) $recipient->contact_id][] = $recipient;
            }

            if ($recipient->client_user_id) {
                $index['client_user'][(int) $recipient->client_user_id][] = $recipient;
            }

            $email = $this->normalizeEmail($recipient->email);

            if ($email !== '') {
                $index['email'][$email][] = $recipient;
            }
        }

        return $index;
    }

    public function forMember(
        array $index,
        MarketingListMember $member,
        ?int $campaignEmailId = null,
    ): Collection {
        $matched = [];
        $append = function (array $recipients) use (&$matched): void {
            foreach ($recipients as $recipient) {
                $key = $recipient->getKey() !== null
                    ? 'id:'.(int) $recipient->getKey()
                    : 'object:'.spl_object_id($recipient);
                $matched[$key] = $recipient;
            }
        };

        if ($member->id) {
            $append($index['list_member'][(int) $member->id] ?? []);
        }

        if ($member->contact_id) {
            $append($index['contact'][(int) $member->contact_id] ?? []);
        }

        if ($member->client_user_id) {
            $append($index['client_user'][(int) $member->client_user_id] ?? []);
        }

        $email = $this->normalizeEmail($member->email);

        if ($email !== '') {
            $append($index['email'][$email] ?? []);
        }

        return collect(array_values($matched))
            ->filter(fn (MarketingCampaignRecipient $recipient): bool => $this->matches($recipient, $member))
            ->values()
            ->when(
                $campaignEmailId !== null,
                fn (Collection $recipients): Collection => $recipients
                    ->filter(
                        fn (MarketingCampaignRecipient $recipient): bool => (int) $recipient->marketing_campaign_email_id === $campaignEmailId,
                    )
                    ->values(),
            );
    }

    public function matches(
        MarketingCampaignRecipient $recipient,
        MarketingListMember $member,
    ): bool {
        if ($member->contact_id && (int) $recipient->contact_id === (int) $member->contact_id) {
            return true;
        }

        if ($member->client_user_id && (int) $recipient->client_user_id === (int) $member->client_user_id) {
            return true;
        }

        if (
            $member->id
            && (int) $recipient->marketing_list_member_id === (int) $member->id
        ) {
            $contactConflict = $member->contact_id
                && $recipient->contact_id
                && (int) $member->contact_id !== (int) $recipient->contact_id;
            $clientUserConflict = $member->client_user_id
                && $recipient->client_user_id
                && (int) $member->client_user_id !== (int) $recipient->client_user_id;

            if (! $contactConflict && ! $clientUserConflict) {
                return true;
            }
        }

        $email = $this->normalizeEmail($member->email);

        return $email !== '' && $this->normalizeEmail($recipient->email) === $email;
    }

    public function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
