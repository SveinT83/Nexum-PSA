<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Exceptions\AmbiguousMarketingCampaignDeliveryIdentity;
use App\Modules\Marketing\Models\MarketingCampaignDelivery;
use App\Modules\Marketing\Models\MarketingCampaignDeliveryIdentityKey;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;

class FindMarketingCampaignDeliveryForRecipient
{
    public function handle(
        MarketingCampaignRecipient $recipient,
        bool $lockForUpdate = false,
    ): ?MarketingCampaignDelivery {
        $identityKeys = $this->identityKeysForRecipient($recipient);

        if ($identityKeys === []) {
            return null;
        }

        $query = MarketingCampaignDeliveryIdentityKey::query()
            ->select('marketing_campaign_delivery_identity_keys.*')
            ->join(
                'marketing_campaign_deliveries',
                'marketing_campaign_deliveries.id',
                '=',
                'marketing_campaign_delivery_identity_keys.marketing_campaign_delivery_id',
            )
            ->where(
                'marketing_campaign_delivery_identity_keys.marketing_campaign_email_id',
                $recipient->marketing_campaign_email_id,
            )
            ->where(function ($query) use ($identityKeys): void {
                foreach ($identityKeys as $identityKey) {
                    $query->orWhere(function ($query) use ($identityKey): void {
                        $query->where(
                            'marketing_campaign_delivery_identity_keys.identity_type',
                            $identityKey['type'],
                        )->where(
                            'marketing_campaign_delivery_identity_keys.identity_hash',
                            $identityKey['hash'],
                        );
                    });
                }
            })
            ->orderByRaw(
                "CASE marketing_campaign_deliveries.status WHEN 'sent' THEN 0 WHEN 'outcome_unknown' THEN 1 WHEN 'provider_write_started' THEN 2 ELSE 3 END"
            )
            ->orderBy('marketing_campaign_deliveries.id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $deliveryIds = $query->get()
            ->pluck('marketing_campaign_delivery_id')
            ->map(fn ($deliveryId): int => (int) $deliveryId)
            ->unique()
            ->values();

        if ($deliveryIds->count() > 1) {
            throw new AmbiguousMarketingCampaignDeliveryIdentity;
        }

        $deliveryId = $deliveryIds->first();

        if (! $deliveryId) {
            return null;
        }

        $deliveryQuery = MarketingCampaignDelivery::query()
            ->whereKey($deliveryId);

        if ($lockForUpdate) {
            $deliveryQuery->lockForUpdate();
        }

        return $deliveryQuery->first();
    }

    /** @return array<int, array{type: string, hash: string}> */
    public function identityKeysForRecipient(MarketingCampaignRecipient $recipient): array
    {
        $keys = [];

        if ($recipient->contact_id) {
            $keys[] = $this->identityKey('contact', (string) (int) $recipient->contact_id);
        }

        if ($recipient->client_user_id) {
            $keys[] = $this->identityKey('client_user', (string) (int) $recipient->client_user_id);
        }

        $email = $this->normalizeEmail($recipient->email);

        if ($email !== '') {
            $keys[] = $this->identityKey('email', $email);
        }

        return $keys;
    }

    public function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /** @return array{type: string, hash: string} */
    private function identityKey(string $type, string $value): array
    {
        return [
            'type' => $type,
            'hash' => hash('sha256', 'marketing-delivery-identity-v1:'.$type.':'.$value),
        ];
    }
}
