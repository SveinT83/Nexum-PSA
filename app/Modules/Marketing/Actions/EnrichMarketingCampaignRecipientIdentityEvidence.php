<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingCampaignDelivery;
use App\Modules\Marketing\Models\MarketingCampaignDeliveryIdentityKey;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class EnrichMarketingCampaignRecipientIdentityEvidence
{
    public const REVIEW_ERROR_CODE = 'MARKETING_DELIVERY_IDENTITY_AMBIGUOUS';

    public function __construct(
        private readonly FindMarketingCampaignDeliveryForRecipient $deliveries,
    ) {}

    public function handle(
        MarketingCampaignRecipient $recipient,
        MarketingListMember $member,
    ): bool {
        if (! $this->canBridge($recipient, $member)) {
            return true;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(
                    fn (): bool => $this->enrichLocked($recipient, $member),
                    3,
                );
            } catch (UniqueConstraintViolationException) {
                // A concurrent identity enrichment may have inserted the same
                // key. Re-read once; a different delivery is detected below.
            }
        }

        return DB::transaction(function () use ($recipient): bool {
            $locked = MarketingCampaignRecipient::query()
                ->lockForUpdate()
                ->find($recipient->id);

            if (! $locked) {
                return false;
            }

            $delivery = $this->lockedDelivery($locked);
            $this->markForReview($locked, $delivery);

            return false;
        }, 3);
    }

    private function enrichLocked(
        MarketingCampaignRecipient $recipient,
        MarketingListMember $member,
    ): bool {
        $locked = MarketingCampaignRecipient::query()
            ->lockForUpdate()
            ->find($recipient->id);

        if (! $locked || ! $this->canBridge($locked, $member)) {
            return true;
        }

        if ($this->hasIdentityReviewBlock($locked)) {
            return false;
        }

        $contactId = $locked->contact_id ?: $member->contact_id;
        $clientUserId = $locked->client_user_id ?: $member->client_user_id;
        $updates = [];

        if (! $locked->contact_id && $contactId) {
            $updates['contact_id'] = (int) $contactId;
        }

        if (! $locked->client_user_id && $clientUserId) {
            $updates['client_user_id'] = (int) $clientUserId;
        }

        if ($updates === []) {
            return true;
        }

        $delivery = $this->lockedDelivery($locked);

        if ($delivery) {
            $identityRecipient = clone $locked;
            $identityRecipient->forceFill($updates);
            $identityKeys = $this->deliveries->identityKeysForRecipient($identityRecipient);
            $existingKeys = [];

            foreach ($identityKeys as $identityKey) {
                $key = MarketingCampaignDeliveryIdentityKey::query()
                    ->where('marketing_campaign_email_id', $locked->marketing_campaign_email_id)
                    ->where('identity_type', $identityKey['type'])
                    ->where('identity_hash', $identityKey['hash'])
                    ->lockForUpdate()
                    ->first();

                if ($key && (int) $key->marketing_campaign_delivery_id !== (int) $delivery->id) {
                    $this->markForReview($locked, $delivery);

                    return false;
                }

                if ($key) {
                    $existingKeys[$identityKey['type'].':'.$identityKey['hash']] = true;
                }
            }

            foreach ($identityKeys as $identityKey) {
                $key = $identityKey['type'].':'.$identityKey['hash'];

                if (isset($existingKeys[$key])) {
                    continue;
                }

                MarketingCampaignDeliveryIdentityKey::query()->create([
                    'marketing_campaign_delivery_id' => $delivery->id,
                    'marketing_campaign_email_id' => $locked->marketing_campaign_email_id,
                    'identity_type' => $identityKey['type'],
                    'identity_hash' => $identityKey['hash'],
                ]);
            }
        }

        $locked->forceFill($updates)->save();
        $recipient->forceFill($updates);

        return true;
    }

    private function canBridge(
        MarketingCampaignRecipient $recipient,
        MarketingListMember $member,
    ): bool {
        $recipientEmail = $this->deliveries->normalizeEmail($recipient->email);
        $memberEmail = $this->deliveries->normalizeEmail($member->email);
        $sameEmail = $recipientEmail !== '' && $recipientEmail === $memberEmail;
        $sameListMember = $member->id
            && (int) $recipient->marketing_list_member_id === (int) $member->id;

        if (! $sameEmail && ! $sameListMember) {
            return false;
        }

        $contactConflict = $recipient->contact_id
            && $member->contact_id
            && (int) $recipient->contact_id !== (int) $member->contact_id;
        $clientUserConflict = $recipient->client_user_id
            && $member->client_user_id
            && (int) $recipient->client_user_id !== (int) $member->client_user_id;

        return ! $contactConflict && ! $clientUserConflict;
    }

    private function lockedDelivery(
        MarketingCampaignRecipient $recipient,
    ): ?MarketingCampaignDelivery {
        if (! $recipient->marketing_campaign_delivery_id) {
            return null;
        }

        return MarketingCampaignDelivery::query()
            ->lockForUpdate()
            ->find($recipient->marketing_campaign_delivery_id);
    }

    private function markForReview(
        MarketingCampaignRecipient $recipient,
        ?MarketingCampaignDelivery $delivery,
    ): void {
        $metadata = $recipient->metadata ?? [];
        $metadata['delivery_invariant'] = array_merge(
            (array) ($metadata['delivery_invariant'] ?? []),
            [
                'error_code' => self::REVIEW_ERROR_CODE,
                'automatic_replay_allowed' => false,
                'identity_enrichment_conflict' => true,
            ],
        );
        $updates = [
            'due_at' => null,
            'last_error' => 'Stable recipient identity evidence conflicts with another delivery. Automatic transmission is blocked pending review.',
            'metadata' => $metadata,
        ];

        if (in_array($recipient->status, ['pending', 'failed', 'suppressed'], true)) {
            $updates['status'] = 'failed';
        }

        $recipient->forceFill($updates)->save();

        if (! $delivery) {
            return;
        }

        $deliveryMetadata = $delivery->metadata ?? [];
        $deliveryMetadata['identity_enrichment_review'] = [
            'error_code' => self::REVIEW_ERROR_CODE,
            'automatic_replay_allowed' => false,
            'detected_at' => now()->toISOString(),
        ];
        $delivery->forceFill(['metadata' => $deliveryMetadata])->save();
    }

    private function hasIdentityReviewBlock(
        MarketingCampaignRecipient $recipient,
    ): bool {
        $metadata = $recipient->metadata ?? [];
        $deliveryInvariant = (array) ($metadata['delivery_invariant'] ?? []);

        return ($deliveryInvariant['error_code'] ?? null) === self::REVIEW_ERROR_CODE;
    }
}
