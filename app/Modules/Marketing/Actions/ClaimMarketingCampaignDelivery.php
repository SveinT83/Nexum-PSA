<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Marketing\Exceptions\AmbiguousMarketingCampaignDeliveryIdentity;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignDelivery;
use App\Modules\Marketing\Models\MarketingCampaignDeliveryIdentityKey;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ClaimMarketingCampaignDelivery
{
    public function __construct(
        private readonly FindMarketingCampaignDeliveryForRecipient $deliveries,
        private readonly MatchMarketingCampaignRecipientsByIdentity $identityMatcher,
    ) {}

    /**
     * Atomically elect the only automatic SMTP attempt for this campaign-email
     * and stable recipient identity. A null result always means no provider
     * write was authorized.
     */
    public function handle(
        MarketingCampaignRecipient $recipient,
        EmailAccount $account,
    ): ?MarketingCampaignDelivery {
        $claimToken = hash('sha256', Str::uuid()->toString().':'.bin2hex(random_bytes(16)));
        $messageId = $this->generateMessageId($account);
        $claimIdentityRecipient = null;

        try {
            return DB::transaction(function () use ($recipient, $claimToken, $messageId, &$claimIdentityRecipient): ?MarketingCampaignDelivery {
                $locked = MarketingCampaignRecipient::query()
                    ->lockForUpdate()
                    ->find($recipient->id);

                if (
                    ! $locked
                    || $locked->status !== 'pending'
                    || ! $locked->due_at
                    || $locked->due_at->isFuture()
                ) {
                    return null;
                }

                // Pause/deactivation can race the earlier progression check.
                // Lock and reauthorize both mutable owners in the same atomic
                // boundary that elects the provider transmission.
                $campaign = MarketingCampaign::query()
                    ->lockForUpdate()
                    ->find($locked->marketing_campaign_id);
                $campaignEmail = MarketingCampaignEmail::query()
                    ->lockForUpdate()
                    ->find($locked->marketing_campaign_email_id);

                if (
                    ! $campaign
                    || ! in_array($campaign->status, ['approved', 'active'], true)
                    || ! $campaignEmail
                    || (int) $campaignEmail->marketing_campaign_id !== (int) $campaign->id
                    || $campaignEmail->status !== 'active'
                ) {
                    return null;
                }

                $claimIdentityRecipient = $this->claimIdentityRecipient($locked, $campaign);

                if (! $claimIdentityRecipient) {
                    return null;
                }

                $identityKeys = $this->deliveries->identityKeysForRecipient($claimIdentityRecipient);

                if ($identityKeys === []) {
                    throw new RuntimeException('marketing_delivery_identity_missing');
                }

                try {
                    $existing = $this->deliveries->handle($claimIdentityRecipient, true);
                } catch (AmbiguousMarketingCampaignDeliveryIdentity) {
                    $this->markIdentityAmbiguous($locked);

                    return null;
                }

                if ($existing) {
                    $this->markDuplicateSkipped($locked, $existing);

                    return null;
                }

                $delivery = MarketingCampaignDelivery::query()->create([
                    'marketing_campaign_id' => $locked->marketing_campaign_id,
                    'marketing_campaign_email_id' => $locked->marketing_campaign_email_id,
                    'marketing_campaign_recipient_id' => $locked->id,
                    'status' => MarketingCampaignDelivery::STATUS_CLAIMED,
                    'source' => 'runtime',
                    'claim_token' => $claimToken,
                    'rfc_message_id' => $messageId,
                    'claimed_at' => now(),
                    'metadata' => [
                        'recipient_cycle_number' => (int) ($locked->cycle_number ?: 1),
                        'identity_evidence_enriched' => $locked->contact_id !== $claimIdentityRecipient->contact_id
                            || $locked->client_user_id !== $claimIdentityRecipient->client_user_id,
                        'automatic_replay_allowed' => false,
                    ],
                ]);

                foreach ($identityKeys as $identityKey) {
                    MarketingCampaignDeliveryIdentityKey::query()->create([
                        'marketing_campaign_delivery_id' => $delivery->id,
                        'marketing_campaign_email_id' => $locked->marketing_campaign_email_id,
                        'identity_type' => $identityKey['type'],
                        'identity_hash' => $identityKey['hash'],
                    ]);
                }

                $locked->forceFill([
                    'marketing_campaign_delivery_id' => $delivery->id,
                    'status' => 'claimed',
                    'claimed_at' => $delivery->claimed_at,
                    'attempts' => (int) $locked->attempts + 1,
                    'rfc_message_id' => $messageId,
                    'last_error' => null,
                    'metadata' => $this->recipientMetadata($locked, [
                        'delivery_claim_id' => $delivery->id,
                        'automatic_replay_allowed' => false,
                    ]),
                ])->save();

                return $delivery->fresh(['identityKeys']);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Another worker won one of the stable identity-key inserts. The
            // failed transaction contains no delivery or recipient mutation.
            try {
                $existing = $this->deliveries->handle(
                    $claimIdentityRecipient ?: ($recipient->fresh() ?: $recipient),
                );
            } catch (AmbiguousMarketingCampaignDeliveryIdentity) {
                $this->markIdentityAmbiguousAfterRollback($recipient);

                return null;
            }

            if (! $existing) {
                throw $exception;
            }

            DB::transaction(function () use ($recipient, $existing): void {
                $locked = MarketingCampaignRecipient::query()
                    ->lockForUpdate()
                    ->find($recipient->id);

                if ($locked && $locked->status === 'pending') {
                    $this->markDuplicateSkipped($locked, $existing);
                }
            });

            return null;
        }
    }

    private function claimIdentityRecipient(
        MarketingCampaignRecipient $recipient,
        MarketingCampaign $campaign,
    ): ?MarketingCampaignRecipient {
        $campaign->loadMissing(['lists.members', 'list.members']);
        $matchingMembers = $campaign->audienceLists()
            ->flatMap(fn ($list) => $list->members->whereIn('status', ['eligible', 'active']))
            ->filter(fn (MarketingListMember $member): bool => $this->identityMatcher->matches($recipient, $member))
            ->values();

        // Eligibility was checked immediately before claim, but membership can
        // race that check. Fail closed without consuming a delivery when the
        // frozen recipient no longer maps to the current eligible audience.
        if ($matchingMembers->isEmpty()) {
            return null;
        }

        $contactIds = collect([$recipient->contact_id])
            ->merge($matchingMembers->pluck('contact_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $clientUserIds = collect([$recipient->client_user_id])
            ->merge($matchingMembers->pluck('client_user_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($contactIds->count() > 1 || $clientUserIds->count() > 1) {
            $this->markIdentityAmbiguous($recipient);

            return null;
        }

        $identityRecipient = clone $recipient;
        $identityRecipient->forceFill([
            'contact_id' => $contactIds->first(),
            'client_user_id' => $clientUserIds->first(),
        ]);

        return $identityRecipient;
    }

    public function markProviderWriteStarted(int $deliveryId, string $claimToken): ?MarketingCampaignDelivery
    {
        return DB::transaction(function () use ($deliveryId, $claimToken): ?MarketingCampaignDelivery {
            $delivery = MarketingCampaignDelivery::query()
                ->lockForUpdate()
                ->find($deliveryId);

            if (
                ! $this->ownsClaim($delivery, $claimToken)
                || $delivery->status !== MarketingCampaignDelivery::STATUS_CLAIMED
            ) {
                return null;
            }

            $delivery->forceFill([
                'status' => MarketingCampaignDelivery::STATUS_PROVIDER_WRITE_STARTED,
                'provider_write_started_at' => now(),
            ])->save();

            return $delivery->refresh();
        });
    }

    public function markSent(
        int $deliveryId,
        string $claimToken,
        ?string $acceptedMessageId = null,
    ): bool {
        return DB::transaction(function () use ($deliveryId, $claimToken, $acceptedMessageId): bool {
            $delivery = MarketingCampaignDelivery::query()
                ->lockForUpdate()
                ->find($deliveryId);

            if (
                ! $this->ownsClaim($delivery, $claimToken)
                || $delivery->status !== MarketingCampaignDelivery::STATUS_PROVIDER_WRITE_STARTED
            ) {
                return false;
            }

            $acceptedMessageId = $this->cleanMessageId($acceptedMessageId)
                ?: (string) $delivery->rfc_message_id;
            $metadata = $delivery->metadata ?? [];
            $metadata['provider_acceptance'] = [
                'accepted_at' => now()->toISOString(),
                'accepted_message_id' => $acceptedMessageId,
            ];

            $delivery->forceFill([
                'status' => MarketingCampaignDelivery::STATUS_SENT,
                'sent_at' => now(),
                'last_error_code' => null,
                'metadata' => $metadata,
            ])->save();

            $recipient = MarketingCampaignRecipient::query()
                ->lockForUpdate()
                ->find($delivery->marketing_campaign_recipient_id);

            if ($recipient) {
                $recipient->forceFill([
                    'marketing_campaign_delivery_id' => $delivery->id,
                    'status' => 'sent',
                    'sent_at' => $delivery->sent_at,
                    'rfc_message_id' => $acceptedMessageId,
                    'last_error' => null,
                    'outcome_unknown_at' => null,
                ])->save();
            }

            return true;
        });
    }

    public function markOutcomeUnknown(
        int $deliveryId,
        string $claimToken,
        string $errorCode = 'SMTP_SEND_OUTCOME_UNRESOLVED',
    ): bool {
        return DB::transaction(function () use ($deliveryId, $claimToken, $errorCode): bool {
            $delivery = MarketingCampaignDelivery::query()
                ->lockForUpdate()
                ->find($deliveryId);

            if (
                ! $this->ownsClaim($delivery, $claimToken)
                || ! in_array($delivery->status, [
                    MarketingCampaignDelivery::STATUS_CLAIMED,
                    MarketingCampaignDelivery::STATUS_PROVIDER_WRITE_STARTED,
                ], true)
            ) {
                return false;
            }

            $errorCode = $this->safeErrorCode($errorCode);
            $metadata = $delivery->metadata ?? [];
            $metadata['outcome_review'] = [
                'automatic_replay_allowed' => false,
                'recorded_at' => now()->toISOString(),
            ];

            $delivery->forceFill([
                'status' => MarketingCampaignDelivery::STATUS_OUTCOME_UNKNOWN,
                'outcome_unknown_at' => now(),
                'last_error_code' => $errorCode,
                'metadata' => $metadata,
            ])->save();

            $recipient = MarketingCampaignRecipient::query()
                ->lockForUpdate()
                ->find($delivery->marketing_campaign_recipient_id);

            if ($recipient) {
                $recipient->forceFill([
                    'marketing_campaign_delivery_id' => $delivery->id,
                    'status' => 'outcome_unknown',
                    'outcome_unknown_at' => $delivery->outcome_unknown_at,
                    'rfc_message_id' => $delivery->rfc_message_id,
                    'last_error' => 'The SMTP provider outcome could not be confirmed. Automatic resend is blocked pending review.',
                ])->save();
            }

            return true;
        });
    }

    private function markIdentityAmbiguousAfterRollback(MarketingCampaignRecipient $recipient): void
    {
        DB::transaction(function () use ($recipient): void {
            $locked = MarketingCampaignRecipient::query()
                ->lockForUpdate()
                ->find($recipient->id);

            if ($locked && $locked->status === 'pending') {
                $this->markIdentityAmbiguous($locked);
            }
        });
    }

    private function markIdentityAmbiguous(MarketingCampaignRecipient $recipient): void
    {
        $recipient->forceFill([
            'status' => 'failed',
            'due_at' => null,
            'last_error' => 'Stable recipient identity matches conflicting delivery evidence. Automatic transmission is blocked pending review.',
            'metadata' => $this->recipientMetadata($recipient, [
                'error_code' => 'MARKETING_DELIVERY_IDENTITY_AMBIGUOUS',
                'review_reason' => 'conflicting_delivery_identity',
                'automatic_replay_allowed' => false,
            ]),
        ])->save();
    }

    private function markDuplicateSkipped(
        MarketingCampaignRecipient $recipient,
        MarketingCampaignDelivery $delivery,
    ): void {
        $recipient->forceFill([
            'marketing_campaign_delivery_id' => $delivery->id,
            'status' => 'duplicate_skipped',
            'due_at' => null,
            'last_error' => 'A lifetime delivery guard already exists for this campaign email and recipient identity.',
            'metadata' => $this->recipientMetadata($recipient, [
                'matched_delivery_claim_id' => $delivery->id,
                'matched_delivery_status' => $delivery->status,
                'automatic_replay_allowed' => false,
            ]),
        ])->save();
    }

    private function ownsClaim(?MarketingCampaignDelivery $delivery, string $claimToken): bool
    {
        return $delivery
            && preg_match('/^[a-f0-9]{64}$/', $claimToken) === 1
            && hash_equals((string) $delivery->claim_token, $claimToken);
    }

    private function generateMessageId(EmailAccount $account): string
    {
        $domain = trim((string) str($account->address)->after('@'));
        $domain = preg_replace('/[^a-z0-9.-]/i', '', $domain)
            ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = $domain ?: 'nexum-psa.local';

        return '<'.bin2hex(random_bytes(16)).'@'.$domain.'>';
    }

    private function cleanMessageId(?string $messageId): string
    {
        return trim((string) preg_replace('/[\r\n]+/', '', (string) $messageId));
    }

    private function safeErrorCode(string $errorCode): string
    {
        $errorCode = strtoupper((string) preg_replace('/[^A-Z0-9_]+/i', '_', $errorCode));

        return substr(trim($errorCode, '_'), 0, 100) ?: 'SMTP_SEND_OUTCOME_UNRESOLVED';
    }

    private function recipientMetadata(
        MarketingCampaignRecipient $recipient,
        array $deliveryMetadata,
    ): array {
        $metadata = $recipient->metadata ?? [];
        $metadata['delivery_invariant'] = array_merge(
            (array) ($metadata['delivery_invariant'] ?? []),
            $deliveryMetadata,
        );

        return $metadata;
    }
}
